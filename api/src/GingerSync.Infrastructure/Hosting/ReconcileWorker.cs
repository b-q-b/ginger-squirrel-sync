using GingerSync.Core.Services;
using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.Hosting;

/// <summary>
/// Replaces the v1 PHP cron line. Loops through active mappings every
/// `Sync:ReconcileIntervalMinutes` minutes (default 5) and invokes SyncEngine.
/// Errors are caught per-mapping so one bad mapping doesn't stop the run.
/// </summary>
public sealed class ReconcileWorker : BackgroundService
{
    private readonly IServiceProvider _services;
    private readonly SyncOptions _opts;
    private readonly ILogger<ReconcileWorker> _log;

    public ReconcileWorker(IServiceProvider services, IOptions<SyncOptions> opts, ILogger<ReconcileWorker> log)
    {
        _services = services;
        _opts = opts.Value;
        _log = log;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // Stagger the first run so the app finishes startup first.
        try { await Task.Delay(TimeSpan.FromSeconds(45), stoppingToken); }
        catch (OperationCanceledException) { return; }

        var interval = TimeSpan.FromMinutes(Math.Max(1, _opts.ReconcileIntervalMinutes));
        _log.LogInformation("ReconcileWorker started — interval {Minutes} min", interval.TotalMinutes);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await RunOnceAsync(stoppingToken);
            }
            catch (OperationCanceledException) { break; }
            catch (Exception ex)
            {
                _log.LogError(ex, "ReconcileWorker iteration crashed");
            }

            try { await Task.Delay(interval, stoppingToken); }
            catch (OperationCanceledException) { break; }
        }

        _log.LogInformation("ReconcileWorker stopped");
    }

    private async Task RunOnceAsync(CancellationToken ct)
    {
        using var scope = _services.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<GingerSyncDbContext>();
        var engine = scope.ServiceProvider.GetRequiredService<ISyncEngine>();

        var mappings = await db.Mappings.AsNoTracking()
            .Where(m => m.IsActive)
            .OrderBy(m => m.CreatedAt)
            .ToListAsync(ct);

        if (mappings.Count == 0)
        {
            _log.LogDebug("No active mappings; cron tick is a no-op.");
            return;
        }

        foreach (var m in mappings)
        {
            try
            {
                var sw = System.Diagnostics.Stopwatch.StartNew();
                var result = await engine.ReconcileMappingAsync(m, "reconcile_cron", ct);
                sw.Stop();
                _log.LogInformation(
                    "Reconcile {Label} ({Id}) — T→CU:{T} CU→T:{C} skip:{S} err:{E} in {Ms} ms",
                    m.Label, m.Id, result.TrelloToClickUp, result.ClickUpToTrello, result.Skipped, result.Errors, sw.ElapsedMilliseconds);
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Reconcile failed for mapping {Label} ({Id})", m.Label, m.Id);
            }
        }
    }
}

using System.Net.Http;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using GingerSync.Infrastructure.External;
using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.Hosting;

/// <summary>
/// Drives meetings through the pipeline without requiring the browser to poll
/// /api/meetings/{id}/process. Runs every 20s. Picks up any meeting whose
/// status is uploaded | transcribing | analyzing and advances it one step.
/// One bad meeting doesn't block the others.
/// </summary>
public sealed class MeetingProcessorWorker : BackgroundService
{
    private static readonly TimeSpan Interval = TimeSpan.FromSeconds(20);

    private readonly IServiceProvider _services;
    private readonly ILogger<MeetingProcessorWorker> _log;

    public MeetingProcessorWorker(IServiceProvider services, ILogger<MeetingProcessorWorker> log)
    {
        _services = services;
        _log = log;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        try { await Task.Delay(TimeSpan.FromSeconds(30), stoppingToken); }
        catch (OperationCanceledException) { return; }

        _log.LogInformation("MeetingProcessorWorker started — tick every {Sec}s", Interval.TotalSeconds);

        while (!stoppingToken.IsCancellationRequested)
        {
            try { await TickAsync(stoppingToken); }
            catch (OperationCanceledException) { break; }
            catch (Exception ex) { _log.LogError(ex, "MeetingProcessorWorker tick crashed"); }

            try { await Task.Delay(Interval, stoppingToken); }
            catch (OperationCanceledException) { break; }
        }
    }

    private async Task TickAsync(CancellationToken ct)
    {
        using var scope = _services.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<GingerSyncDbContext>();

        var pending = await db.Meetings
            .Where(m => m.DeletedAt == null
                && (m.Status == MeetingStatus.Uploaded
                    || m.Status == MeetingStatus.Transcribing
                    || m.Status == MeetingStatus.Analyzing))
            .OrderBy(m => m.UpdatedAt)
            .Take(10)
            .ToListAsync(ct);

        if (pending.Count == 0) return;

        var transcriber = scope.ServiceProvider.GetRequiredService<ITranscriber>();
        var analyzer = scope.ServiceProvider.GetRequiredService<IMeetingAnalyzer>();
        var storage = scope.ServiceProvider.GetRequiredService<IOptions<StorageOptions>>().Value;
        var aaiKey = scope.ServiceProvider.GetRequiredService<IOptions<AssemblyAIOptions>>().Value.ApiKey;
        var orKey  = scope.ServiceProvider.GetRequiredService<IOptions<OpenRouterOptions>>().Value.ApiKey;

        foreach (var m in pending)
        {
            try
            {
                await AdvanceOneAsync(m, db, transcriber, analyzer, storage, aaiKey, orKey, ct);
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Meeting {Id} (status={Status}) advance failed", m.Id, m.Status);
                m.Status = MeetingStatus.Error;
                m.ErrorMessage = ex.Message;
                m.UpdatedAt = DateTimeOffset.UtcNow;
                try { await db.SaveChangesAsync(CancellationToken.None); }
                catch (Exception saveEx) { _log.LogError(saveEx, "Failed to persist error for {Id}", m.Id); }
            }
        }
    }

    private async Task AdvanceOneAsync(
        Meeting m,
        GingerSyncDbContext db,
        ITranscriber transcriber,
        IMeetingAnalyzer analyzer,
        StorageOptions storage,
        string aaiKey,
        string orKey,
        CancellationToken ct)
    {
        if (m.Status == MeetingStatus.Uploaded)
        {
            if (string.IsNullOrEmpty(aaiKey))
            {
                m.Status = MeetingStatus.AudioOnly;
                m.ErrorMessage = "AssemblyAI not configured. Audio stored; transcription skipped.";
                m.UpdatedAt = DateTimeOffset.UtcNow;
                await db.SaveChangesAsync(ct);
                return;
            }
            var local = Path.Combine(storage.AudioRoot, m.Id.ToString(), $"audio.{m.AudioExtension}");
            if (!File.Exists(local))
                throw new FileNotFoundException($"Audio file missing on disk: {local}");

            var uploadUrl = await transcriber.UploadAsync(local, ct);
            var transcriptId = await transcriber.CreateJobAsync(uploadUrl, m.SpeakersExpected, m.Language, ct);
            m.Status = MeetingStatus.Transcribing;
            m.AssemblyAITranscriptId = transcriptId;
            m.ErrorMessage = null;
            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            _log.LogInformation("Meeting {Id} → transcribing (job {Job})", m.Id, transcriptId);
            return;
        }

        if (m.Status == MeetingStatus.Transcribing)
        {
            if (string.IsNullOrEmpty(m.AssemblyAITranscriptId))
                throw new InvalidOperationException("missing transcript id");

            var result = await transcriber.GetResultAsync(m.AssemblyAITranscriptId, ct);
            if (result is null) return; // still queued/processing

            m.Status = MeetingStatus.Analyzing;
            m.Transcript = result.FormattedText;
            m.DurationMs = result.DurationMs > 0 ? result.DurationMs : m.DurationMs;
            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            _log.LogInformation("Meeting {Id} → analyzing (transcript {Len} chars)", m.Id, result.FormattedText.Length);
            return;
        }

        if (m.Status == MeetingStatus.Analyzing)
        {
            if (string.IsNullOrWhiteSpace(m.Transcript))
                throw new InvalidOperationException("no transcript to analyze");

            if (string.IsNullOrEmpty(orKey))
            {
                m.Status = MeetingStatus.Ready;
                m.ErrorMessage = "OPENROUTER_API_KEY not set; transcript saved but no analysis";
                m.UpdatedAt = DateTimeOffset.UtcNow;
                await db.SaveChangesAsync(ct);
                return;
            }

            var analysis = await analyzer.AnalyzeAsync(m.Transcript, m.Title, ct);
            m.Analysis = analysis;
            m.Status = MeetingStatus.Ready;
            m.ErrorMessage = null;
            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            _log.LogInformation("Meeting {Id} → ready ({S} summary / {A} actions)", m.Id, analysis.Summary.Count, analysis.ActionItems.Count);
        }
    }
}

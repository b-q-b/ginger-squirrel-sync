using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using GingerSync.Core.SyncEngine;
using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.Sync;

/// <summary>
/// Core sync orchestrator. Direct port of legacy-php/lib/sync_engine.php::reconcileMapping
/// with the canonical-hash + timestamp-aware decision tree we landed on after the
/// "ClickUp edits get reverted" bug.
/// </summary>
public sealed class SyncEngine : ISyncEngine
{
    private readonly GingerSyncDbContext _db;
    private readonly IClickUpClient _cu;
    private readonly ITrelloClient _tr;
    private readonly SyncOptions _opts;
    private readonly ILogger<SyncEngine> _log;

    public SyncEngine(
        GingerSyncDbContext db,
        IClickUpClient cu,
        ITrelloClient tr,
        IOptions<SyncOptions> opts,
        ILogger<SyncEngine> log)
    {
        _db = db; _cu = cu; _tr = tr; _opts = opts.Value; _log = log;
    }

    public async Task<ReconcileResult> ReconcileMappingAsync(Mapping m, string source, CancellationToken ct = default)
    {
        var stats = new RunStats();

        // ── 1. Live state from both platforms ─────────────────────────────
        var tCards = string.IsNullOrEmpty(m.TrelloListId)
            ? await _tr.GetBoardCardsAsync(m.TrelloBoardId, ct)
            : await _tr.GetListCardsAsync(m.TrelloListId!, ct);

        var allCuTasks = await _cu.GetListTasksAsync(m.ClickUpListId, includeSubtasks: false, ct);
        // Defensive: drop subtasks if any leak through (parent != null)
        var cuTasks = allCuTasks.Where(t => string.IsNullOrEmpty(t.Parent)).ToList();

        var trelloLists = await _tr.GetListsAsync(m.TrelloBoardId, ct);
        var listNamesById = trelloLists.ToDictionary(l => l.Id, l => l.Name);
        var listsByName = trelloLists.ToDictionary(l => l.Name, l => l.Id, StringComparer.Ordinal);
        var defaultTrelloListId = !string.IsNullOrEmpty(m.TrelloListId)
            ? m.TrelloListId!
            : (trelloLists.FirstOrDefault()?.Id ?? "");

        var syncMaps = await _db.SyncMaps
            .Where(s => s.MappingId == m.Id && s.DeletedAt == null)
            .ToListAsync(ct);

        var tCardById = tCards.ToDictionary(c => c.Id);
        var cuTaskById = cuTasks.ToDictionary(t => t.Id);
        var processedTrelloIds = new HashSet<string>(StringComparer.Ordinal);
        var processedCuIds = new HashSet<string>(StringComparer.Ordinal);
        var tolerance = TimeSpan.FromSeconds(Math.Max(0, _opts.EchoDebounceSeconds));
        var now = DateTimeOffset.UtcNow;

        // ── PASS 1: paired items (sync_map entries) ────────────────────────
        foreach (var sm in syncMaps)
        {
            var tId = sm.TrelloCardId;
            var cId = sm.ClickUpTaskId;
            if (tId is null || cId is null) continue;

            processedTrelloIds.Add(tId);
            processedCuIds.Add(cId);

            var tCard = tCardById.GetValueOrDefault(tId);
            var cuTask = cuTaskById.GetValueOrDefault(cId);

            // Both deleted
            if (tCard is null && cuTask is null)
            {
                sm.DeletedAt = now;
                continue;
            }

            // Trello side gone → cascade delete to ClickUp
            if (tCard is null)
            {
                try
                {
                    await _cu.DeleteTaskAsync(cId, ct);
                    sm.DeletedAt = now;
                    LogEvent(m.Id, source, SyncDirection.TrelloToClickUp, "delete", null, cId, "ok", null, null);
                }
                catch (Exception ex)
                {
                    stats.Errors++;
                    LogEvent(m.Id, source, SyncDirection.TrelloToClickUp, "delete", null, cId, "error", ex.Message, null);
                }
                continue;
            }

            // ClickUp side gone → cascade delete to Trello
            if (cuTask is null)
            {
                try
                {
                    await _tr.DeleteCardAsync(tId, ct);
                    sm.DeletedAt = now;
                    LogEvent(m.Id, source, SyncDirection.ClickUpToTrello, "delete", tId, null, "ok", null, null);
                }
                catch (Exception ex)
                {
                    stats.Errors++;
                    LogEvent(m.Id, source, SyncDirection.ClickUpToTrello, "delete", tId, null, "error", ex.Message, null);
                }
                continue;
            }

            // Both exist — compare canonical content
            var tCanon = CanonicalHash.FromTrello(tCard, m.StatusMap, listNamesById);
            var cCanon = CanonicalHash.FromClickUp(cuTask, m.StatusMap);
            var tHash = CanonicalHash.Compute(tCanon);
            var cHash = CanonicalHash.Compute(cCanon);

            if (tHash == cHash)
            {
                // Content identical — short-circuit. No work, no audit-noise.
                stats.Skipped++;
                // Don't write a sync_events row for "everything fine, nothing to do" —
                // the v1 production cron used to log these and it filled the table fast.
                continue;
            }

            // Content differs — pick direction by timestamps with the 60s tolerance
            var lastSync = sm.LastSyncedAt ?? DateTimeOffset.MinValue;
            var tTs = tCard.DateLastActivity ?? DateTimeOffset.MinValue;
            var cuTs = cuTask.DateUpdated > 0
                ? DateTimeOffset.FromUnixTimeMilliseconds(cuTask.DateUpdated)
                : DateTimeOffset.MinValue;

            var tChanged = tTs > lastSync + tolerance;
            var cuChanged = cuTs > lastSync + tolerance;

            SyncDirection direction;
            if (tChanged && cuChanged) direction = tTs >= cuTs ? SyncDirection.TrelloToClickUp : SyncDirection.ClickUpToTrello;
            else if (tChanged) direction = SyncDirection.TrelloToClickUp;
            else if (cuChanged) direction = SyncDirection.ClickUpToTrello;
            else direction = sm.LastDirection ?? SyncDirection.TrelloToClickUp;

            try
            {
                if (direction == SyncDirection.TrelloToClickUp)
                {
                    var payload = FieldMapper.TrelloCardToClickUp(tCard, m.StatusMap, listNamesById);
                    await _cu.UpdateTaskAsync(cId, payload, ct);
                    sm.LastHash = tHash;
                    stats.TrelloToClickUp++;
                }
                else
                {
                    var payload = FieldMapper.ClickUpTaskToTrello(cuTask, m.StatusMap, listsByName, defaultTrelloListId);
                    await _tr.UpdateCardAsync(tId, payload, ct);
                    sm.LastHash = cHash;
                    stats.ClickUpToTrello++;
                }
                sm.LastDirection = direction;
                sm.LastSyncedAt = now;
                LogEvent(m.Id, source, direction, "update", tId, cId, "ok", null, sm.LastHash);
            }
            catch (Exception ex)
            {
                stats.Errors++;
                LogEvent(m.Id, source, direction, "update", tId, cId, "error", ex.Message, null);
                _log.LogWarning(ex, "Sync failed for mapping {Mapping} card {TrelloCardId}/{ClickUpTaskId}", m.Label, tId, cId);
            }
        }

        // ── PASS 2: Trello-only orphans → create in ClickUp ───────────────
        foreach (var tCard in tCards.Where(c => !processedTrelloIds.Contains(c.Id)))
        {
            try
            {
                var payload = FieldMapper.TrelloCardToClickUp(tCard, m.StatusMap, listNamesById);
                var newTask = await _cu.CreateTaskAsync(m.ClickUpListId, payload, ct);

                var canon = CanonicalHash.FromTrello(tCard, m.StatusMap, listNamesById);
                _db.SyncMaps.Add(new SyncMap
                {
                    Id = Guid.NewGuid(),
                    MappingId = m.Id,
                    TrelloCardId = tCard.Id,
                    ClickUpTaskId = newTask.Id,
                    LastHash = CanonicalHash.Compute(canon),
                    LastDirection = SyncDirection.TrelloToClickUp,
                    LastSyncedAt = now,
                });
                stats.TrelloToClickUp++;
                LogEvent(m.Id, source, SyncDirection.TrelloToClickUp, "create", tCard.Id, newTask.Id, "ok", null, null);
            }
            catch (Exception ex)
            {
                stats.Errors++;
                LogEvent(m.Id, source, SyncDirection.TrelloToClickUp, "create", tCard.Id, null, "error", ex.Message, null);
                _log.LogWarning(ex, "Create CU from Trello orphan failed: card {TrelloCardId}", tCard.Id);
            }
        }

        // ── PASS 3: ClickUp-only orphans → create in Trello ───────────────
        foreach (var cuTask in cuTasks.Where(t => !processedCuIds.Contains(t.Id)))
        {
            try
            {
                var payload = FieldMapper.ClickUpTaskToTrello(cuTask, m.StatusMap, listsByName, defaultTrelloListId);
                var newCard = await _tr.CreateCardAsync(payload, ct);

                var canon = CanonicalHash.FromClickUp(cuTask, m.StatusMap);
                _db.SyncMaps.Add(new SyncMap
                {
                    Id = Guid.NewGuid(),
                    MappingId = m.Id,
                    TrelloCardId = newCard.Id,
                    ClickUpTaskId = cuTask.Id,
                    LastHash = CanonicalHash.Compute(canon),
                    LastDirection = SyncDirection.ClickUpToTrello,
                    LastSyncedAt = now,
                });
                stats.ClickUpToTrello++;
                LogEvent(m.Id, source, SyncDirection.ClickUpToTrello, "create", newCard.Id, cuTask.Id, "ok", null, null);
            }
            catch (Exception ex)
            {
                stats.Errors++;
                LogEvent(m.Id, source, SyncDirection.ClickUpToTrello, "create", null, cuTask.Id, "error", ex.Message, null);
                _log.LogWarning(ex, "Create Trello from CU orphan failed: task {CuTaskId}", cuTask.Id);
            }
        }

        // Heartbeat — one summary row per reconcile run per mapping. Lets the
        // dashboard's "last cron" stat update and gives /logs visible proof
        // the engine is firing, without the v1 noise of logging every skip.
        _db.SyncEvents.Add(new SyncEvent
        {
            CreatedAt = DateTimeOffset.UtcNow,
            Source = source,
            Action = "cycle",
            MappingId = m.Id,
            Status = stats.Errors > 0 ? "error" : "ok",
            Error = stats.Errors > 0 ? $"{stats.Errors} item error(s)" : null,
            PayloadHash = $"t→cu:{stats.TrelloToClickUp} cu→t:{stats.ClickUpToTrello} skip:{stats.Skipped}",
        });

        // Persist sync_map updates + audit events in one batch
        try
        {
            await _db.SaveChangesAsync(ct);
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "SaveChangesAsync after reconcile failed for mapping {Mapping}", m.Label);
            stats.Errors++;
        }

        return new ReconcileResult(stats.TrelloToClickUp, stats.ClickUpToTrello, stats.Skipped, stats.Errors);
    }

    public Task<SyncResult> SyncTrelloCardToClickUpAsync(Mapping mapping, string trelloCardId, string source, CancellationToken ct = default)
        => throw new NotImplementedException("Webhook-driven single-card sync arrives in slice 3c.");

    public Task<SyncResult> SyncClickUpTaskToTrelloAsync(Mapping mapping, string clickupTaskId, string source, CancellationToken ct = default)
        => throw new NotImplementedException();

    private void LogEvent(Guid mappingId, string source, SyncDirection? direction, string action,
        string? trelloCardId, string? clickUpTaskId, string status, string? error, string? payloadHash)
    {
        _db.SyncEvents.Add(new SyncEvent
        {
            CreatedAt = DateTimeOffset.UtcNow,
            Source = source,
            Direction = direction,
            Action = action,
            TrelloCardId = trelloCardId,
            ClickUpTaskId = clickUpTaskId,
            MappingId = mappingId,
            Status = status,
            Error = error,
            PayloadHash = payloadHash,
        });
    }

    private sealed class RunStats
    {
        public int TrelloToClickUp { get; set; }
        public int ClickUpToTrello { get; set; }
        public int Skipped { get; set; }
        public int Errors { get; set; }
    }
}

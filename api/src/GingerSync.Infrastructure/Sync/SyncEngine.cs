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

    public async Task<SyncResult> SyncTrelloCardToClickUpAsync(Mapping m, string trelloCardId, string source, CancellationToken ct = default)
    {
        try
        {
            var card = await _tr.GetCardAsync(trelloCardId, ct);
            var trelloLists = await _tr.GetListsAsync(m.TrelloBoardId, ct);
            var listNamesById = trelloLists.ToDictionary(l => l.Id, l => l.Name);

            var sm = await _db.SyncMaps.FirstOrDefaultAsync(
                s => s.MappingId == m.Id && s.TrelloCardId == trelloCardId && s.DeletedAt == null, ct);

            var canon = CanonicalHash.FromTrello(card, m.StatusMap, listNamesById);
            var hash = CanonicalHash.Compute(canon);

            // Echo guard — if this content matches what we last wrote, skip
            if (sm != null && sm.LastHash == hash)
            {
                return new SyncResult(SyncAction.SkippedHash, null);
            }

            var payload = FieldMapper.TrelloCardToClickUp(card, m.StatusMap, listNamesById);
            ClickUpTask cuTask;
            SyncAction action;
            string? cuTaskId;

            if (sm?.ClickUpTaskId is null)
            {
                cuTask = await _cu.CreateTaskAsync(m.ClickUpListId, payload, ct);
                cuTaskId = cuTask.Id;
                action = SyncAction.Created;
                if (sm is null)
                {
                    _db.SyncMaps.Add(new SyncMap
                    {
                        Id = Guid.NewGuid(),
                        MappingId = m.Id,
                        TrelloCardId = trelloCardId,
                        ClickUpTaskId = cuTaskId,
                        LastHash = hash,
                        LastDirection = SyncDirection.TrelloToClickUp,
                        LastSyncedAt = DateTimeOffset.UtcNow,
                    });
                }
                else
                {
                    sm.ClickUpTaskId = cuTaskId;
                    sm.LastHash = hash;
                    sm.LastDirection = SyncDirection.TrelloToClickUp;
                    sm.LastSyncedAt = DateTimeOffset.UtcNow;
                }
            }
            else
            {
                cuTask = await _cu.UpdateTaskAsync(sm.ClickUpTaskId, payload, ct);
                cuTaskId = sm.ClickUpTaskId;
                action = SyncAction.Updated;
                sm.LastHash = hash;
                sm.LastDirection = SyncDirection.TrelloToClickUp;
                sm.LastSyncedAt = DateTimeOffset.UtcNow;
            }

            LogEvent(m.Id, source, SyncDirection.TrelloToClickUp,
                action == SyncAction.Created ? "create" : "update",
                trelloCardId, cuTaskId, "ok", null, hash);
            await _db.SaveChangesAsync(ct);
            return new SyncResult(action, null);
        }
        catch (Exception ex)
        {
            LogEvent(m.Id, source, SyncDirection.TrelloToClickUp, "error", trelloCardId, null, "error", ex.Message, null);
            try { await _db.SaveChangesAsync(ct); } catch { /* best effort */ }
            return new SyncResult(SyncAction.Error, ex.Message);
        }
    }

    public async Task<SyncResult> SyncClickUpTaskToTrelloAsync(Mapping m, string clickupTaskId, string source, CancellationToken ct = default)
    {
        try
        {
            var task = await _cu.GetTaskAsync(clickupTaskId, ct);
            if (!string.IsNullOrEmpty(task.Parent))
            {
                // Subtasks aren't synced to Trello in v1 either — bail without an event
                return new SyncResult(SyncAction.SkippedEcho, "subtask, not synced");
            }

            var trelloLists = await _tr.GetListsAsync(m.TrelloBoardId, ct);
            var listsByName = trelloLists.ToDictionary(l => l.Name, l => l.Id, StringComparer.Ordinal);
            var defaultTrelloListId = !string.IsNullOrEmpty(m.TrelloListId)
                ? m.TrelloListId!
                : (trelloLists.FirstOrDefault()?.Id ?? "");

            var sm = await _db.SyncMaps.FirstOrDefaultAsync(
                s => s.MappingId == m.Id && s.ClickUpTaskId == clickupTaskId && s.DeletedAt == null, ct);

            var canon = CanonicalHash.FromClickUp(task, m.StatusMap);
            var hash = CanonicalHash.Compute(canon);

            if (sm != null && sm.LastHash == hash)
            {
                return new SyncResult(SyncAction.SkippedHash, null);
            }

            var payload = FieldMapper.ClickUpTaskToTrello(task, m.StatusMap, listsByName, defaultTrelloListId);
            TrelloCard card;
            SyncAction action;
            string? trelloCardId;

            if (sm?.TrelloCardId is null)
            {
                card = await _tr.CreateCardAsync(payload, ct);
                trelloCardId = card.Id;
                action = SyncAction.Created;
                if (sm is null)
                {
                    _db.SyncMaps.Add(new SyncMap
                    {
                        Id = Guid.NewGuid(),
                        MappingId = m.Id,
                        TrelloCardId = trelloCardId,
                        ClickUpTaskId = clickupTaskId,
                        LastHash = hash,
                        LastDirection = SyncDirection.ClickUpToTrello,
                        LastSyncedAt = DateTimeOffset.UtcNow,
                    });
                }
                else
                {
                    sm.TrelloCardId = trelloCardId;
                    sm.LastHash = hash;
                    sm.LastDirection = SyncDirection.ClickUpToTrello;
                    sm.LastSyncedAt = DateTimeOffset.UtcNow;
                }
            }
            else
            {
                card = await _tr.UpdateCardAsync(sm.TrelloCardId, payload, ct);
                trelloCardId = sm.TrelloCardId;
                action = SyncAction.Updated;
                sm.LastHash = hash;
                sm.LastDirection = SyncDirection.ClickUpToTrello;
                sm.LastSyncedAt = DateTimeOffset.UtcNow;
            }

            LogEvent(m.Id, source, SyncDirection.ClickUpToTrello,
                action == SyncAction.Created ? "create" : "update",
                trelloCardId, clickupTaskId, "ok", null, hash);
            await _db.SaveChangesAsync(ct);
            return new SyncResult(action, null);
        }
        catch (Exception ex)
        {
            LogEvent(m.Id, source, SyncDirection.ClickUpToTrello, "error", null, clickupTaskId, "error", ex.Message, null);
            try { await _db.SaveChangesAsync(ct); } catch { /* best effort */ }
            return new SyncResult(SyncAction.Error, ex.Message);
        }
    }

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

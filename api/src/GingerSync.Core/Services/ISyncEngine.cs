using GingerSync.Core.Entities;

namespace GingerSync.Core.Services;

/// <summary>
/// Core sync orchestrator. Reconciles a mapping using canonical-hash + timestamp-aware
/// logic so the same content on both sides never loops.
/// </summary>
public interface ISyncEngine
{
    Task<ReconcileResult> ReconcileMappingAsync(Mapping mapping, string source, CancellationToken ct = default);

    Task<SyncResult> SyncTrelloCardToClickUpAsync(Mapping mapping, string trelloCardId, string source, CancellationToken ct = default);

    Task<SyncResult> SyncClickUpTaskToTrelloAsync(Mapping mapping, string clickupTaskId, string source, CancellationToken ct = default);
}

public sealed record ReconcileResult(int TrelloToClickUp, int ClickUpToTrello, int Skipped, int Errors);

public sealed record SyncResult(SyncAction Action, string? Error);

public enum SyncAction { Created, Updated, Deleted, SkippedEcho, SkippedHash, Error }

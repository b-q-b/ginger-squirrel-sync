namespace GingerSync.Core.Entities;

/// <summary>
/// Joins a Trello card and its matching ClickUp task. The canonical ledger
/// for "this card == this task".
/// </summary>
public sealed class SyncMap
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public required Guid MappingId { get; init; }

    public string? TrelloCardId { get; set; }
    public string? ClickUpTaskId { get; set; }

    /// <summary>SHA-1 of the canonical content the last time we synced.</summary>
    public string? LastHash { get; set; }
    public SyncDirection? LastDirection { get; set; }
    public DateTimeOffset? LastSyncedAt { get; set; }
    public DateTimeOffset? DeletedAt { get; set; }
}

public enum SyncDirection
{
    TrelloToClickUp,
    ClickUpToTrello,
}

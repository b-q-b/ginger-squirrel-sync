namespace GingerSync.Core.Entities;

/// <summary>An audit-log entry for a single sync operation.</summary>
public sealed class SyncEvent
{
    public long Id { get; init; }
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
    public required string Source { get; init; }       // trello_webhook | clickup_webhook | reconcile_cron | manual
    public SyncDirection? Direction { get; init; }
    public required string Action { get; init; }       // create | update | delete | skip_echo | skip_hash | error
    public string? TrelloCardId { get; init; }
    public string? ClickUpTaskId { get; init; }
    public Guid? MappingId { get; init; }
    public required string Status { get; init; }       // ok | error | skipped
    public string? Error { get; init; }
    public string? PayloadHash { get; init; }
}

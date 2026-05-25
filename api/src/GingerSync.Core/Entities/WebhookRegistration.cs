namespace GingerSync.Core.Entities;

/// <summary>
/// What we've registered with Trello / ClickUp. Each row is one webhook on
/// the upstream platform. `target_id` is board id (Trello) or workspace id
/// (ClickUp). `external_id` is the webhook id returned by the platform —
/// used to delete it later.
/// </summary>
public sealed class WebhookRegistration
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public string Platform { get; set; } = "";       // "trello" | "clickup"
    public string ExternalId { get; set; } = "";
    public string TargetId { get; set; } = "";
    public string Status { get; set; } = "active";    // active | disabled | failed
    public DateTimeOffset? LastCheckedAt { get; set; }
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
}

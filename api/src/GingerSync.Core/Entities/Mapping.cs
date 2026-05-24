namespace GingerSync.Core.Entities;

/// <summary>
/// A pairing between a Trello board (or list) and a ClickUp list.
/// </summary>
public sealed class Mapping
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public required string Label { get; init; }

    public required string TrelloBoardId { get; init; }
    public string? TrelloListId { get; init; }    // null = whole board

    public required string ClickUpSpaceId { get; init; }
    public required string ClickUpListId { get; init; }

    /// <summary>Trello list name → ClickUp status name.</summary>
    public Dictionary<string, string> StatusMap { get; init; } = new();

    public bool IsActive { get; set; } = true;
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
}

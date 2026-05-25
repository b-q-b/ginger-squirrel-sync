namespace GingerSync.Core.Services;

/// <summary>Minimal ClickUp REST surface we need.</summary>
public interface IClickUpClient
{
    Task<ClickUpUser> GetMeAsync(CancellationToken ct = default);
    Task<IReadOnlyList<ClickUpTask>> GetListTasksAsync(string listId, bool includeSubtasks = false, CancellationToken ct = default);
    Task<ClickUpTask> GetTaskAsync(string taskId, CancellationToken ct = default);
    Task<ClickUpTask> CreateTaskAsync(string listId, ClickUpTaskWrite payload, CancellationToken ct = default);
    Task<ClickUpTask> UpdateTaskAsync(string taskId, ClickUpTaskWrite payload, CancellationToken ct = default);
    Task DeleteTaskAsync(string taskId, CancellationToken ct = default);
}

public sealed record ClickUpUser(long Id, string Username, string? Email);

public sealed record ClickUpTask(
    string Id,
    string Name,
    string? Description,
    ClickUpStatus? Status,
    long? DueDate,
    long DateUpdated,
    string? Parent,
    IReadOnlyList<ClickUpTag> Tags,
    string Url
);

public sealed record ClickUpStatus(string Status, string? Color);
public sealed record ClickUpTag(string Name, string? TagFg, string? TagBg);

public sealed class ClickUpTaskWrite
{
    public string? Name { get; init; }
    public string? Description { get; init; }
    public string? Status { get; init; }
    /// <summary>Unix milliseconds (ClickUp convention).</summary>
    public long? DueDate { get; init; }
    public bool? DueDateTime { get; init; }
    public IReadOnlyList<string>? Tags { get; init; }   // ClickUp accepts a flat list of tag names on POST/PUT
}


public sealed record TrelloMember(string Id, string Username, string FullName);
public sealed record TrelloBoard(string Id, string Name, string? ShortUrl);
public sealed record TrelloList(string Id, string Name, double Position);
public sealed record TrelloCard(
    string Id,
    string Name,
    string? Desc,
    string? Due,
    string IdList,
    IReadOnlyList<string> IdLabels,
    IReadOnlyList<TrelloLabel> Labels,
    DateTimeOffset? DateLastActivity,
    string? ShortUrl
);
public sealed record TrelloLabel(string Id, string Name, string? Color);

public sealed class TrelloCardWrite
{
    public string? Name { get; init; }
    public string? Desc { get; init; }
    public string? Due { get; init; }
    public string? IdList { get; init; }
    public IReadOnlyList<string>? IdLabels { get; init; }
}

public interface ITrelloClient
{
    Task<TrelloMember> GetMeAsync(CancellationToken ct = default);
    Task<IReadOnlyList<TrelloBoard>> GetBoardsAsync(CancellationToken ct = default);
    Task<IReadOnlyList<TrelloList>> GetListsAsync(string boardId, CancellationToken ct = default);
    Task<IReadOnlyList<TrelloCard>> GetBoardCardsAsync(string boardId, CancellationToken ct = default);
    Task<IReadOnlyList<TrelloCard>> GetListCardsAsync(string listId, CancellationToken ct = default);
    Task<TrelloCard> GetCardAsync(string cardId, CancellationToken ct = default);
    Task<TrelloCard> CreateCardAsync(TrelloCardWrite payload, CancellationToken ct = default);
    Task<TrelloCard> UpdateCardAsync(string cardId, TrelloCardWrite payload, CancellationToken ct = default);
    Task DeleteCardAsync(string cardId, CancellationToken ct = default);
}

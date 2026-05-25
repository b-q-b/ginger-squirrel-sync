using GingerSync.Core.Services;

namespace GingerSync.Core.SyncEngine;

/// <summary>
/// Translates between Trello card payloads and ClickUp task payloads.
/// Direct port of legacy-php/lib/field_mapper.php (trelloCardToClickUp / clickUpTaskToTrello).
/// </summary>
public static class FieldMapper
{
    public static ClickUpTaskWrite TrelloCardToClickUp(
        TrelloCard card,
        IReadOnlyDictionary<string, string> statusMap,
        IReadOnlyDictionary<string, string> listNamesById)
    {
        string? status = null;
        var listName = listNamesById.TryGetValue(card.IdList ?? "", out var n) ? n : "";
        if (!string.IsNullOrEmpty(listName) && statusMap.TryGetValue(listName, out var mapped))
            status = mapped;

        long? dueMs = null;
        bool dueDateTime = false;
        if (!string.IsNullOrWhiteSpace(card.Due) && DateTimeOffset.TryParse(card.Due, out var dt))
        {
            dueMs = dt.ToUnixTimeMilliseconds();
            dueDateTime = true;
        }

        var tags = (card.Labels ?? [])
            .Select(l => (l.Name ?? "").Trim())
            .Where(name => name.Length > 0)
            .Distinct(StringComparer.Ordinal)
            .ToArray();

        return new ClickUpTaskWrite
        {
            Name = card.Name ?? "",
            Description = card.Desc ?? "",
            Status = status,
            DueDate = dueMs,
            DueDateTime = dueDateTime ? true : null,
            Tags = tags.Length > 0 ? tags : null,
        };
    }

    public static TrelloCardWrite ClickUpTaskToTrello(
        ClickUpTask task,
        IReadOnlyDictionary<string, string> statusMap,
        IReadOnlyDictionary<string, string> listsByName,
        string? defaultTrelloListId = null)
    {
        // Status → Trello list: reverse the mapping (CU status → Trello list name → list id)
        string? idList = null;
        var cuStatus = (task.Status?.Status ?? "").ToLowerInvariant();
        if (!string.IsNullOrEmpty(cuStatus))
        {
            foreach (var (trelloName, mappedCuStatus) in statusMap)
            {
                if (string.Equals(mappedCuStatus, cuStatus, StringComparison.OrdinalIgnoreCase) &&
                    listsByName.TryGetValue(trelloName, out var listId))
                {
                    idList = listId;
                    break;
                }
            }
        }
        idList ??= defaultTrelloListId;

        string? due = null;
        if (task.DueDate.HasValue && task.DueDate.Value > 0)
        {
            // ClickUp ms epoch → Trello ISO 8601
            due = DateTimeOffset.FromUnixTimeMilliseconds(task.DueDate.Value).ToString("o");
        }

        return new TrelloCardWrite
        {
            Name = task.Name ?? "",
            Desc = task.Description ?? "",
            Due = due,
            IdList = idList,
            // Labels: handled separately by sync engine (find-or-create on the board)
            IdLabels = null,
        };
    }
}

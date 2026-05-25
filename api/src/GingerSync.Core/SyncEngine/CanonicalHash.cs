using System.Security.Cryptography;
using System.Text;
using System.Text.Encodings.Web;
using System.Text.Json;
using GingerSync.Core.Services;

namespace GingerSync.Core.SyncEngine;

/// <summary>
/// Canonical content extractors + SHA-1 hash. Direct port of legacy-php/lib/field_mapper.php
/// (canonicalFromTrello / canonicalFromClickUp / canonicalHash).
/// </summary>
public static class CanonicalHash
{
    // PHP uses JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES, so don't escape
    // anything more than strictly necessary on the C# side either.
    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
        WriteIndented = false,
    };

    public static CanonicalContent FromTrello(
        TrelloCard card,
        IReadOnlyDictionary<string, string> statusMap,
        IReadOnlyDictionary<string, string> listNamesById)
    {
        var listId = card.IdList ?? "";
        var listName = listNamesById.TryGetValue(listId, out var n) ? n : "";
        var statusCanon = statusMap.TryGetValue(listName, out var s)
            ? s.ToLowerInvariant()
            : "";

        var labels = (card.Labels ?? [])
            .Select(l => (l.Name ?? "").Trim().ToLowerInvariant())
            .Where(name => name.Length > 0)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(x => x, StringComparer.Ordinal)
            .ToArray();

        long due = 0;
        if (!string.IsNullOrWhiteSpace(card.Due) &&
            DateTimeOffset.TryParse(card.Due, out var dt))
        {
            due = dt.ToUnixTimeSeconds();
        }

        return new CanonicalContent
        {
            Name = (card.Name ?? "").Trim(),
            Desc = (card.Desc ?? "").Trim(),
            Due = due,
            Status = statusCanon,
            Labels = labels,
        };
    }

    public static CanonicalContent FromClickUp(
        ClickUpTask task,
        IReadOnlyDictionary<string, string> statusMap)
    {
        var cuStatus = (task.Status?.Status ?? "").ToLowerInvariant();
        var managed = new HashSet<string>(
            statusMap.Values.Select(v => v.ToLowerInvariant()),
            StringComparer.Ordinal);
        var statusCanon = managed.Contains(cuStatus) ? cuStatus : "";

        var labels = (task.Tags ?? [])
            .Select(t => (t.Name ?? "").Trim().ToLowerInvariant())
            .Where(name => name.Length > 0)
            .Distinct(StringComparer.Ordinal)
            .OrderBy(x => x, StringComparer.Ordinal)
            .ToArray();

        long due = 0;
        if (task.DueDate.HasValue && task.DueDate.Value > 0)
        {
            due = task.DueDate.Value / 1000;
        }

        return new CanonicalContent
        {
            Name = (task.Name ?? "").Trim(),
            Desc = (task.Description ?? "").Trim(),
            Due = due,
            Status = statusCanon,
            Labels = labels,
        };
    }

    /// <summary>SHA-1 hex (lowercase, 40 chars) of the JSON form. Matches v1 hashes byte-for-byte.</summary>
    public static string Compute(CanonicalContent canonical)
    {
        var json = JsonSerializer.SerializeToUtf8Bytes(canonical, JsonOpts);
        var digest = SHA1.HashData(json);
        return Convert.ToHexStringLower(digest);
    }
}

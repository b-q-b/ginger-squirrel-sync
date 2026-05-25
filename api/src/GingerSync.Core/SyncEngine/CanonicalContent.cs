using System.Text.Json.Serialization;

namespace GingerSync.Core.SyncEngine;

/// <summary>
/// Direction-agnostic content view of a task. JSON-serialized form goes into
/// SHA-1 to produce the stable hash stored in sync_map.last_hash.
///
/// IMPORTANT: property order + names + types here MUST match the v1 PHP shape
/// so existing last_hash values keep matching after a .NET cut-over:
///   { "name": str, "desc": str, "due": int, "status": str, "labels": [str, ...] }
/// </summary>
public sealed class CanonicalContent
{
    [JsonPropertyName("name")]   public string Name { get; init; } = "";
    [JsonPropertyName("desc")]   public string Desc { get; init; } = "";
    /// <summary>Unix seconds. 0 means "no due date".</summary>
    [JsonPropertyName("due")]    public long Due { get; init; }
    /// <summary>Canonicalised status — only populated when the mapping's status_map covers it.</summary>
    [JsonPropertyName("status")] public string Status { get; init; } = "";
    /// <summary>Lowercase label names, deduplicated, sorted ordinal.</summary>
    [JsonPropertyName("labels")] public IReadOnlyList<string> Labels { get; init; } = [];
}

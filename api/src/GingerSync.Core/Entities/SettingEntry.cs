namespace GingerSync.Core.Entities;

/// <summary>Key/value store for app-level configuration (password hash, field toggles, etc.).</summary>
public sealed class SettingEntry
{
    public string Key { get; init; } = "";
    /// <summary>Raw JSON value as stored in the jsonb column.</summary>
    public string Value { get; set; } = "";
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

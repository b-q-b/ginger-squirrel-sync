namespace GingerSync.Core.Entities;

/// <summary>Audio meeting + transcript + AI analysis.</summary>
public sealed class Meeting
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public string Title { get; set; } = "Untitled meeting";
    public DateTimeOffset RecordedAt { get; init; } = DateTimeOffset.UtcNow;
    public int? DurationMs { get; set; }
    public string Language { get; set; } = "en";

    public MeetingStatus Status { get; set; } = MeetingStatus.Uploaded;
    public string? ErrorMessage { get; set; }

    // Audio file
    public string? AudioPath { get; set; }
    public string? AudioMime { get; set; }
    public long? AudioSizeBytes { get; set; }
    public string? AudioExtension { get; set; }

    // Transcription
    public int? SpeakersExpected { get; set; }
    public string? Transcript { get; set; }
    public string? AssemblyAITranscriptId { get; set; }

    // Analysis result (stored as JSON document)
    public MeetingAnalysis? Analysis { get; set; }

    // Linkage
    public Guid? HotPlateItemId { get; set; }

    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? DeletedAt { get; set; }
}

public enum MeetingStatus
{
    Uploaded,
    Transcribing,
    Analyzing,
    Ready,
    Error,
    AudioOnly,
}

public sealed class MeetingAnalysis
{
    public List<string> Summary { get; init; } = [];
    public List<Decision> Decisions { get; init; } = [];
    public List<ActionItem> ActionItems { get; init; } = [];
    public List<OpenQuestion> Questions { get; init; } = [];
}

public sealed class Decision { public required string Text { get; init; } }

public sealed class ActionItem
{
    public required string Title { get; init; }
    public string? Owner { get; init; }
    public string? Due { get; init; }
    public string? Context { get; init; }
}

public sealed class OpenQuestion
{
    public required string Question { get; init; }
    public string? Context { get; init; }
}

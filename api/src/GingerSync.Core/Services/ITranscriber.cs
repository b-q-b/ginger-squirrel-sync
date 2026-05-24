namespace GingerSync.Core.Services;

public interface ITranscriber
{
    /// <summary>Upload a local audio file. Returns a public URL the provider can read.</summary>
    Task<string> UploadAsync(string localPath, CancellationToken ct = default);

    /// <summary>Kick off a transcription job. Returns the provider's transcript id.</summary>
    Task<string> CreateJobAsync(string audioUrl, int? speakersExpected, string language, CancellationToken ct = default);

    /// <summary>Poll a transcript job. Returns null while still processing; throws on terminal failure.</summary>
    Task<TranscriptionResult?> GetResultAsync(string transcriptId, CancellationToken ct = default);
}

public sealed record TranscriptionResult(string FormattedText, int DurationMs);

public interface IMeetingAnalyzer
{
    Task<Entities.MeetingAnalysis> AnalyzeAsync(string transcript, string? title, CancellationToken ct = default);
}

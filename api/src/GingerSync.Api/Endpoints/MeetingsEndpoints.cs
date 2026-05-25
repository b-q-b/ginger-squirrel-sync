using System.Text.Json;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using GingerSync.Infrastructure;
using GingerSync.Infrastructure.External;
using GingerSync.Infrastructure.Persistence;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace GingerSync.Api.Endpoints;

/// <summary>
/// Port of legacy-php/api/meetings*.php — list/get/upload/process/patch/delete/audio.
///
/// State machine:
///   uploaded     → transcribing  (call AssemblyAI: upload + create job)
///   transcribing → analyzing     (poll AssemblyAI until status=completed)
///   analyzing    → ready         (call OpenRouter for structured analysis)
///
/// /process is idempotent and advances one step per call — the browser polls
/// it every few seconds after upload. If AssemblyAI is unconfigured, audio is
/// stored and status becomes "audio_only" (terminal, no transcript).
/// </summary>
public static class MeetingsEndpoints
{
    private const long MaxAudioBytes = 200L * 1024 * 1024;
    private static readonly string[] AllowedExts = ["mp3", "m4a", "aac", "wav", "webm", "ogg", "flac", "mp4"];

    public static void MapMeetingsEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/meetings").WithTags("Meetings").RequireAuthorization();

        // ── List ─────────────────────────────────────────────────────────
        group.MapGet("/", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.Meetings
                .Where(m => m.DeletedAt == null)
                .OrderByDescending(m => m.RecordedAt)
                .Take(200)
                .Select(m => new
                {
                    id = m.Id,
                    title = m.Title,
                    recordedAt = m.RecordedAt,
                    durationMs = m.DurationMs,
                    status = m.Status,
                    errorMessage = m.ErrorMessage,
                    hotPlateItemId = m.HotPlateItemId,
                    createdAt = m.CreatedAt,
                    updatedAt = m.UpdatedAt,
                })
                .ToListAsync(ct);
            return Results.Ok(rows);
        });

        // ── Get one (full, incl. transcript + analysis) ──────────────────
        group.MapGet("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var m = await db.Meetings.AsNoTracking()
                .FirstOrDefaultAsync(x => x.Id == id && x.DeletedAt == null, ct);
            return m is null ? Results.NotFound() : Results.Ok(m);
        });

        // ── Upload ───────────────────────────────────────────────────────
        group.MapPost("/upload", async (
            HttpRequest req,
            GingerSyncDbContext db,
            IOptions<StorageOptions> storageOpts,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("MeetingsUpload");
            if (!req.HasFormContentType) return Results.BadRequest(new { error = "multipart required" });

            var form = await req.ReadFormAsync(ct);
            var file = form.Files["audio"];
            if (file is null || file.Length == 0)
                return Results.BadRequest(new { error = "audio file required" });
            if (file.Length > MaxAudioBytes)
                return Results.BadRequest(new { error = $"file too large (max {MaxAudioBytes / 1024 / 1024} MB)" });

            var ext = ResolveExtension(file);
            if (ext is null)
                return Results.BadRequest(new { error = $"unsupported audio type: {file.ContentType}" });

            var title = (string?)form["title"];
            if (string.IsNullOrWhiteSpace(title))
            {
                title = Path.GetFileNameWithoutExtension(file.FileName);
                if (string.IsNullOrWhiteSpace(title)) title = "Untitled";
                if (title.Length > 80) title = title[..80];
            }

            int? speakers = null;
            if (int.TryParse(form["speakers_expected"], out var sp) && sp is >= 1 and <= 10)
                speakers = sp;

            Guid? hotPlateItemId = null;
            if (Guid.TryParse(form["hot_plate_item_id"], out var hp) && hp != Guid.Empty)
                hotPlateItemId = hp;

            var meeting = new Meeting
            {
                Id = Guid.NewGuid(),
                Title = title!,
                Status = MeetingStatus.Uploaded,
                AudioMime = file.ContentType,
                AudioExtension = ext,
                AudioSizeBytes = file.Length,
                SpeakersExpected = speakers,
                HotPlateItemId = hotPlateItemId,
            };

            db.Meetings.Add(meeting);
            await db.SaveChangesAsync(ct);

            // Persist audio under <AudioRoot>/<id>/audio.<ext>
            var dir = Path.Combine(storageOpts.Value.AudioRoot, meeting.Id.ToString());
            Directory.CreateDirectory(dir);
            var target = Path.Combine(dir, $"audio.{ext}");

            try
            {
                await using var dst = File.Create(target);
                await using var src = file.OpenReadStream();
                await src.CopyToAsync(dst, ct);
            }
            catch (Exception ex)
            {
                meeting.Status = MeetingStatus.Error;
                meeting.ErrorMessage = $"Failed to store audio: {ex.Message}";
                meeting.UpdatedAt = DateTimeOffset.UtcNow;
                await db.SaveChangesAsync(CancellationToken.None);
                log.LogError(ex, "Audio store failed for {Id}", meeting.Id);
                return Results.Problem("Failed to store audio");
            }

            meeting.AudioPath = $"meetings/{meeting.Id}/audio.{ext}";
            meeting.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);

            return Results.Ok(new { meeting_id = meeting.Id });
        })
        .DisableAntiforgery()
        .WithDescription("Upload an audio file. Returns the new meeting id; /process advances the pipeline.");

        // ── Process (advance one step) ───────────────────────────────────
        group.MapPost("/{id:guid}/process", async (
            Guid id,
            GingerSyncDbContext db,
            ITranscriber transcriber,
            IMeetingAnalyzer analyzer,
            IOptions<StorageOptions> storageOpts,
            IOptions<AssemblyAIOptions> aaiOpts,
            IOptions<OpenRouterOptions> orOpts,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("MeetingsProcess");
            var m = await db.Meetings.FirstOrDefaultAsync(x => x.Id == id && x.DeletedAt == null, ct);
            if (m is null) return Results.NotFound();

            try
            {
                // Step 1: uploaded → transcribing
                if (m.Status == MeetingStatus.Uploaded)
                {
                    if (string.IsNullOrEmpty(aaiOpts.Value.ApiKey))
                    {
                        m.Status = MeetingStatus.AudioOnly;
                        m.ErrorMessage = "AssemblyAI not configured. Audio stored; transcription skipped.";
                        m.UpdatedAt = DateTimeOffset.UtcNow;
                        await db.SaveChangesAsync(ct);
                        return Results.Ok(new { status = "audio_only" });
                    }

                    var local = Path.Combine(storageOpts.Value.AudioRoot, m.Id.ToString(), $"audio.{m.AudioExtension}");
                    if (!File.Exists(local))
                    {
                        m.Status = MeetingStatus.Error;
                        m.ErrorMessage = $"Audio file missing on disk: {local}";
                        m.UpdatedAt = DateTimeOffset.UtcNow;
                        await db.SaveChangesAsync(ct);
                        return Results.BadRequest(new { error = "audio missing" });
                    }

                    var uploadUrl = await transcriber.UploadAsync(local, ct);
                    var transcriptId = await transcriber.CreateJobAsync(uploadUrl, m.SpeakersExpected, m.Language, ct);

                    m.Status = MeetingStatus.Transcribing;
                    m.AssemblyAITranscriptId = transcriptId;
                    m.ErrorMessage = null;
                    m.UpdatedAt = DateTimeOffset.UtcNow;
                    await db.SaveChangesAsync(ct);
                    return Results.Ok(new { status = "transcribing", transcript_id = transcriptId });
                }

                // Step 2: transcribing → analyzing
                if (m.Status == MeetingStatus.Transcribing)
                {
                    if (string.IsNullOrEmpty(m.AssemblyAITranscriptId))
                    {
                        m.Status = MeetingStatus.Error;
                        m.ErrorMessage = "missing transcript id";
                        m.UpdatedAt = DateTimeOffset.UtcNow;
                        await db.SaveChangesAsync(ct);
                        return Results.BadRequest(new { error = "missing transcript id" });
                    }

                    var result = await transcriber.GetResultAsync(m.AssemblyAITranscriptId, ct);
                    if (result is null)
                        return Results.Ok(new { status = "transcribing" }); // still queued/processing

                    m.Status = MeetingStatus.Analyzing;
                    m.Transcript = result.FormattedText;
                    m.DurationMs = result.DurationMs > 0 ? result.DurationMs : m.DurationMs;
                    m.UpdatedAt = DateTimeOffset.UtcNow;
                    await db.SaveChangesAsync(ct);
                    return Results.Ok(new { status = "analyzing" });
                }

                // Step 3: analyzing → ready
                if (m.Status == MeetingStatus.Analyzing)
                {
                    if (string.IsNullOrWhiteSpace(m.Transcript))
                    {
                        m.Status = MeetingStatus.Error;
                        m.ErrorMessage = "no transcript to analyze";
                        m.UpdatedAt = DateTimeOffset.UtcNow;
                        await db.SaveChangesAsync(ct);
                        return Results.BadRequest(new { error = "no transcript" });
                    }

                    if (string.IsNullOrEmpty(orOpts.Value.ApiKey))
                    {
                        m.Status = MeetingStatus.Ready;
                        m.ErrorMessage = "OPENROUTER_API_KEY not set; transcript saved but no analysis";
                        m.UpdatedAt = DateTimeOffset.UtcNow;
                        await db.SaveChangesAsync(ct);
                        return Results.Ok(new { status = "ready", note = "no analysis (key missing)" });
                    }

                    var analysis = await analyzer.AnalyzeAsync(m.Transcript, m.Title, ct);
                    m.Analysis = analysis;
                    m.Status = MeetingStatus.Ready;
                    m.ErrorMessage = null;
                    m.UpdatedAt = DateTimeOffset.UtcNow;
                    await db.SaveChangesAsync(ct);
                    return Results.Ok(new { status = "ready" });
                }

                // Terminal — nothing to do
                return Results.Ok(new { status = StatusToString(m.Status), note = "no work to do" });
            }
            catch (Exception ex)
            {
                log.LogError(ex, "Process failed for meeting {Id} (status={Status})", m.Id, m.Status);
                m.Status = MeetingStatus.Error;
                m.ErrorMessage = ex.Message;
                m.UpdatedAt = DateTimeOffset.UtcNow;
                await db.SaveChangesAsync(CancellationToken.None);
                return Results.Problem(ex.Message);
            }
        })
        .WithDescription("Advance the meeting through the pipeline one step (idempotent).");

        // ── Patch (title, hot plate link, speakers, language) ────────────
        group.MapPatch("/{id:guid}", async (Guid id, [FromBody] MeetingPatch body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var m = await db.Meetings.FirstOrDefaultAsync(x => x.Id == id && x.DeletedAt == null, ct);
            if (m is null) return Results.NotFound();

            if (body.Title is not null) m.Title = body.Title;
            if (body.SpeakersExpected is int s) m.SpeakersExpected = s is >= 1 and <= 10 ? s : null;
            if (body.Language is not null) m.Language = string.IsNullOrWhiteSpace(body.Language) ? "en" : body.Language;
            if (body.HotPlateItemId is not null)
                m.HotPlateItemId = body.HotPlateItemId == Guid.Empty ? null : body.HotPlateItemId;

            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        // ── Retry (re-run pipeline for a failed meeting) ─────────────────
        group.MapPost("/{id:guid}/retry", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var m = await db.Meetings.FirstOrDefaultAsync(x => x.Id == id && x.DeletedAt == null, ct);
            if (m is null) return Results.NotFound();
            m.Status = MeetingStatus.Uploaded;
            m.ErrorMessage = null;
            m.AssemblyAITranscriptId = null;
            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        // ── Delete (soft) ────────────────────────────────────────────────
        group.MapDelete("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var m = await db.Meetings.FirstOrDefaultAsync(x => x.Id == id, ct);
            if (m is null) return Results.NotFound();
            m.DeletedAt = DateTimeOffset.UtcNow;
            m.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        // ── Audio playback (byte-range supported via Results.File stream) ─
        group.MapGet("/{id:guid}/audio", async (Guid id, GingerSyncDbContext db, IOptions<StorageOptions> storageOpts, CancellationToken ct) =>
        {
            var m = await db.Meetings.AsNoTracking().FirstOrDefaultAsync(x => x.Id == id && x.DeletedAt == null, ct);
            if (m is null || string.IsNullOrEmpty(m.AudioExtension)) return Results.NotFound();

            var path = Path.Combine(storageOpts.Value.AudioRoot, m.Id.ToString(), $"audio.{m.AudioExtension}");
            if (!File.Exists(path)) return Results.NotFound();

            var mime = string.IsNullOrEmpty(m.AudioMime) ? "audio/mpeg" : m.AudioMime;
            return Results.File(path, mime, enableRangeProcessing: true);
        });
    }

    private static string? ResolveExtension(IFormFile file)
    {
        // Trust filename extension first, then content type, then a small map
        var fromName = Path.GetExtension(file.FileName)?.TrimStart('.').ToLowerInvariant();
        if (!string.IsNullOrEmpty(fromName) && AllowedExts.Contains(fromName)) return fromName;

        var fromMime = (file.ContentType ?? "").ToLowerInvariant() switch
        {
            "audio/mpeg" or "audio/mp3" => "mp3",
            "audio/mp4" or "audio/x-m4a" or "audio/aac" => "m4a",
            "audio/wav" or "audio/x-wav" or "audio/wave" => "wav",
            "audio/webm" or "video/webm" => "webm",
            "audio/ogg" or "audio/oga" => "ogg",
            "audio/flac" or "audio/x-flac" => "flac",
            "video/mp4" => "mp4",
            _ => null,
        };
        return fromMime;
    }

    private static string StatusToString(MeetingStatus s) => s switch
    {
        MeetingStatus.Uploaded => "uploaded",
        MeetingStatus.Transcribing => "transcribing",
        MeetingStatus.Analyzing => "analyzing",
        MeetingStatus.Ready => "ready",
        MeetingStatus.AudioOnly => "audio_only",
        MeetingStatus.Error => "error",
        _ => "unknown",
    };
}

public sealed record MeetingPatch(
    string? Title,
    int? SpeakersExpected,
    string? Language,
    Guid? HotPlateItemId);

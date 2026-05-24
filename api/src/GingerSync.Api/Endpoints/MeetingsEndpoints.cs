using GingerSync.Core.Entities;

namespace GingerSync.Api.Endpoints;

public static class MeetingsEndpoints
{
    public static void MapMeetingsEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/meetings").WithTags("Meetings");

        group.MapGet("/", () => Results.Ok(Array.Empty<Meeting>()))
            .WithDescription("List meetings (latest first).");

        group.MapGet("/{id:guid}", (Guid id) => Results.NotFound())
            .WithDescription("Get a single meeting with transcript + analysis.");

        group.MapPost("/upload", (IFormFile audio, string? title, int? speakersExpected, Guid? hotPlateItemId)
                => Results.Ok(new { meetingId = Guid.NewGuid() }))
            .DisableAntiforgery()  // multipart from logged-in browser; CSRF handled by cookie SameSite
            .WithDescription("Upload an audio file. Returns the new meeting id; the processor takes over async.");

        group.MapPost("/{id:guid}/process", (Guid id) => Results.Ok(new { status = "transcribing" }))
            .WithDescription("Advance the meeting through the pipeline one step (idempotent).");

        group.MapPatch("/{id:guid}", (Guid id, Meeting patch) => Results.NoContent())
            .WithDescription("Update meeting fields (title, hot plate link, speakers).");

        group.MapDelete("/{id:guid}", (Guid id) => Results.NoContent());

        group.MapGet("/{id:guid}/audio", (Guid id) => Results.NotFound())
            .WithDescription("Serve the audio file (auth-gated; supports byte-range).");
    }
}

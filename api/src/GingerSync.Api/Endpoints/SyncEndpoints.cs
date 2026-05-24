namespace GingerSync.Api.Endpoints;

public static class SyncEndpoints
{
    public static void MapSyncEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/sync").WithTags("Sync");

        group.MapGet("/events", (string? source, string? status, int page = 1, int perPage = 50)
                => Results.Ok(Array.Empty<object>()))
            .WithDescription("Paginated sync event log.");

        group.MapGet("/items", (Guid? mappingId, bool live = false) => Results.Ok(Array.Empty<object>()))
            .WithDescription("Unified items view (synced pairs + orphans per side).");

        group.MapPost("/reconcile", (string? key) => Results.Ok(new { mappings = 0 }))
            .WithDescription("Run reconcile across all active mappings. Cron-callable with ?key=secret.");
    }
}

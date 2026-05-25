using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;

namespace GingerSync.Api.Endpoints;

public static class SyncEndpoints
{
    public static void MapSyncEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/sync").WithTags("Sync").RequireAuthorization();

        group.MapGet("/events", async (
            GingerSyncDbContext db,
            string? source,
            string? status,
            string? action,
            int page,
            int perPage,
            CancellationToken ct) =>
        {
            page = page <= 0 ? 1 : page;
            perPage = perPage is <= 0 or > 200 ? 50 : perPage;

            var q = db.SyncEvents.AsNoTracking();
            if (!string.IsNullOrEmpty(source)) q = q.Where(e => e.Source == source);
            if (!string.IsNullOrEmpty(status)) q = q.Where(e => e.Status == status);
            if (!string.IsNullOrEmpty(action)) q = q.Where(e => e.Action == action);

            var total = await q.CountAsync(ct);
            var rows = await q
                .OrderByDescending(e => e.CreatedAt)
                .Skip((page - 1) * perPage)
                .Take(perPage)
                .ToListAsync(ct);

            return Results.Ok(new { total, page, perPage, rows });
        });

        group.MapGet("/stats", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var since = DateTimeOffset.UtcNow.AddHours(-24);
            var totalMappings = await db.Mappings.CountAsync(ct);
            var activeMappings = await db.Mappings.CountAsync(m => m.IsActive, ct);
            var totalEvents = await db.SyncEvents.CountAsync(ct);
            var events24h = await db.SyncEvents.CountAsync(e => e.CreatedAt >= since, ct);
            var errors24h = await db.SyncEvents.CountAsync(e => e.CreatedAt >= since && e.Status == "error", ct);
            var lastCron = await db.SyncEvents
                .Where(e => e.Source == "reconcile_cron")
                .OrderByDescending(e => e.CreatedAt)
                .Select(e => (DateTimeOffset?)e.CreatedAt)
                .FirstOrDefaultAsync(ct);

            return Results.Ok(new
            {
                totalMappings,
                activeMappings,
                totalEvents,
                events24h,
                errors24h,
                lastCronAt = lastCron,
            });
        });

        group.MapGet("/items", (Guid? mappingId, bool live) => Results.Ok(Array.Empty<object>()))
            .WithDescription("Unified items view (placeholder; populated in slice 3).");

        group.MapPost("/reconcile", (string? key) => Results.Ok(new { mappings = 0, note = "reconciler arrives in slice 3" }))
            .WithDescription("Run reconcile across all active mappings (slice 3).");
    }
}

using GingerSync.Core.Entities;
using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;

namespace GingerSync.Api.Endpoints;

public static class MappingsEndpoints
{
    public static void MapMappingsEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/mappings").WithTags("Mappings").RequireAuthorization();

        group.MapGet("/", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.Mappings
                .OrderByDescending(m => m.CreatedAt)
                .ToListAsync(ct);
            return Results.Ok(rows);
        });

        group.MapGet("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var row = await db.Mappings.AsNoTracking().FirstOrDefaultAsync(m => m.Id == id, ct);
            return row is null ? Results.NotFound() : Results.Ok(row);
        });

        group.MapPost("/", async (MappingWrite body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            if (string.IsNullOrWhiteSpace(body.Label) ||
                string.IsNullOrWhiteSpace(body.TrelloBoardId) ||
                string.IsNullOrWhiteSpace(body.ClickUpSpaceId) ||
                string.IsNullOrWhiteSpace(body.ClickUpListId))
            {
                return Results.BadRequest(new { error = "label, trelloBoardId, clickUpSpaceId, clickUpListId are required" });
            }

            var entity = new Mapping
            {
                Id = Guid.NewGuid(),
                Label = body.Label,
                TrelloBoardId = body.TrelloBoardId,
                TrelloListId = body.TrelloListId,
                ClickUpSpaceId = body.ClickUpSpaceId,
                ClickUpListId = body.ClickUpListId,
                StatusMap = body.StatusMap ?? new(),
                IsActive = body.IsActive ?? true,
                CreatedAt = DateTimeOffset.UtcNow,
            };
            db.Mappings.Add(entity);
            await db.SaveChangesAsync(ct);
            return Results.Created($"/api/mappings/{entity.Id}", entity);
        });

        group.MapPatch("/{id:guid}", async (Guid id, MappingPatch body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var entity = await db.Mappings.FirstOrDefaultAsync(m => m.Id == id, ct);
            if (entity is null) return Results.NotFound();

            if (body.Label is not null) entity.Label = body.Label;
            if (body.TrelloListId is not null) entity.TrelloListId = body.TrelloListId;
            if (body.StatusMap is not null) entity.StatusMap = body.StatusMap;
            if (body.IsActive.HasValue) entity.IsActive = body.IsActive.Value;

            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        group.MapDelete("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.Mappings.Where(m => m.Id == id).ExecuteDeleteAsync(ct);
            return rows > 0 ? Results.NoContent() : Results.NotFound();
        });

        group.MapPost("/{id:guid}/sync", async (Guid id, GingerSyncDbContext db, GingerSync.Core.Services.ISyncEngine engine, CancellationToken ct) =>
        {
            var entity = await db.Mappings.AsNoTracking().FirstOrDefaultAsync(m => m.Id == id, ct);
            if (entity is null) return Results.NotFound();

            try
            {
                var result = await engine.ReconcileMappingAsync(entity, "manual", ct);
                return Results.Ok(new
                {
                    ok = result.Errors == 0,
                    trelloToClickUp = result.TrelloToClickUp,
                    clickUpToTrello = result.ClickUpToTrello,
                    skipped = result.Skipped,
                    errors = result.Errors,
                });
            }
            catch (Exception ex)
            {
                return Results.Json(new { ok = false, error = ex.Message }, statusCode: 500);
            }
        });
    }
}

public sealed record MappingWrite(
    string Label,
    string TrelloBoardId,
    string? TrelloListId,
    string ClickUpSpaceId,
    string ClickUpListId,
    Dictionary<string, string>? StatusMap,
    bool? IsActive);

public sealed record MappingPatch(
    string? Label,
    string? TrelloListId,
    Dictionary<string, string>? StatusMap,
    bool? IsActive);

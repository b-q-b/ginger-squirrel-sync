using GingerSync.Core.Entities;
using GingerSync.Infrastructure.Persistence;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace GingerSync.Api.Endpoints;

public static class HotPlateEndpoints
{
    public static void MapHotPlateEndpoints(this IEndpointRouteBuilder app)
    {
        var items = app.MapGroup("/api/hot-plate/items").WithTags("Hot Plate").RequireAuthorization();
        var cats = app.MapGroup("/api/hot-plate/categories").WithTags("Hot Plate").RequireAuthorization();

        // ── Items ────────────────────────────────────────────────────────
        items.MapGet("/", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.HotPlateItems
                .Where(i => i.DeletedAt == null)
                .OrderBy(i => i.Position)
                .ThenBy(i => i.CreatedAt)
                .ToListAsync(ct);
            return Results.Ok(rows);
        });

        items.MapPost("/", async ([FromBody] HotPlateItemWrite body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            if (string.IsNullOrWhiteSpace(body.Title))
                return Results.BadRequest(new { error = "title is required" });

            var col = ParseColumn(body.ColumnKey) ?? HotPlateColumn.Todo;
            var maxPos = await db.HotPlateItems
                .Where(i => i.Column == col && i.DeletedAt == null)
                .Select(i => (int?)i.Position)
                .MaxAsync(ct) ?? -1;

            var entity = new HotPlateItem
            {
                Id = Guid.NewGuid(),
                Title = body.Title,
                Description = body.Description,
                Column = col,
                Priority = (Priority)Math.Clamp(body.Priority ?? 2, 1, 4),
                DueDate = ParseDate(body.DueDate),
                CategoryId = body.CategoryId,
                EnergyLevel = ParseEnergy(body.EnergyLevel),
                Position = maxPos + 1,
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow,
            };
            db.HotPlateItems.Add(entity);
            await db.SaveChangesAsync(ct);
            return Results.Created($"/api/hot-plate/items/{entity.Id}", entity);
        });

        items.MapPatch("/{id:guid}", async (Guid id, [FromBody] HotPlateItemPatch body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var entity = await db.HotPlateItems.FirstOrDefaultAsync(i => i.Id == id && i.DeletedAt == null, ct);
            if (entity is null) return Results.NotFound();

            if (body.Title is not null) entity.Title = body.Title;
            if (body.Description is not null) entity.Description = body.Description;
            if (body.ColumnKey is not null && ParseColumn(body.ColumnKey) is HotPlateColumn col) entity.Column = col;
            if (body.Priority is int p) entity.Priority = (Priority)Math.Clamp(p, 1, 4);
            if (body.DueDate is not null) entity.DueDate = ParseDate(body.DueDate);
            if (body.CategoryId is not null) entity.CategoryId = body.CategoryId == Guid.Empty ? null : body.CategoryId;
            if (body.EnergyLevel is not null) entity.EnergyLevel = ParseEnergy(body.EnergyLevel);
            if (body.Position is int pos) entity.Position = pos;

            entity.UpdatedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        items.MapDelete("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var entity = await db.HotPlateItems.FirstOrDefaultAsync(i => i.Id == id, ct);
            if (entity is null) return Results.NotFound();
            entity.DeletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        items.MapPost("/reorder", async ([FromBody] ReorderRequest[] moves, GingerSyncDbContext db, CancellationToken ct) =>
        {
            foreach (var move in moves)
            {
                var entity = await db.HotPlateItems.FirstOrDefaultAsync(i => i.Id == move.Id, ct);
                if (entity is null) continue;
                if (ParseColumn(move.ColumnKey) is HotPlateColumn col) entity.Column = col;
                entity.Position = move.Position;
                entity.UpdatedAt = DateTimeOffset.UtcNow;
            }
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        }).WithDescription("Batch-update column + position after drag-drop.");

        // ── Categories ───────────────────────────────────────────────────
        cats.MapGet("/", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.HotPlateCategories
                .OrderBy(c => c.SortOrder)
                .ThenBy(c => c.Name)
                .ToListAsync(ct);
            return Results.Ok(rows);
        });

        cats.MapPost("/", async ([FromBody] CategoryWrite body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            if (string.IsNullOrWhiteSpace(body.Name))
                return Results.BadRequest(new { error = "name is required" });

            var maxSort = await db.HotPlateCategories.Select(c => (int?)c.SortOrder).MaxAsync(ct) ?? -1;
            var entity = new HotPlateCategory
            {
                Id = Guid.NewGuid(),
                Name = body.Name,
                Color = ValidColor(body.Color) ? body.Color! : "blue",
                SortOrder = maxSort + 1,
                CreatedAt = DateTimeOffset.UtcNow,
            };
            db.HotPlateCategories.Add(entity);
            await db.SaveChangesAsync(ct);
            return Results.Created($"/api/hot-plate/categories/{entity.Id}", entity);
        });

        cats.MapPatch("/{id:guid}", async (Guid id, [FromBody] CategoryPatch body, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var entity = await db.HotPlateCategories.FirstOrDefaultAsync(c => c.Id == id, ct);
            if (entity is null) return Results.NotFound();
            if (body.Name is not null) entity.Name = body.Name;
            if (body.Color is not null && ValidColor(body.Color)) entity.Color = body.Color;
            if (body.SortOrder is int s) entity.SortOrder = s;
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });

        cats.MapDelete("/{id:guid}", async (Guid id, GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.HotPlateCategories.Where(c => c.Id == id).ExecuteDeleteAsync(ct);
            return rows > 0 ? Results.NoContent() : Results.NotFound();
        });
    }

    private static HotPlateColumn? ParseColumn(string? key) => key?.ToLowerInvariant() switch
    {
        "todo" => HotPlateColumn.Todo,
        "in_progress" => HotPlateColumn.InProgress,
        "waiting" => HotPlateColumn.Waiting,
        "done" => HotPlateColumn.Done,
        _ => null,
    };

    private static EnergyLevel? ParseEnergy(string? raw) => raw?.ToLowerInvariant() switch
    {
        null or "" => null,
        "quick" => EnergyLevel.Quick,
        "social" => EnergyLevel.Social,
        "deep" => EnergyLevel.Deep,
        "creative" => EnergyLevel.Creative,
        _ => null,
    };

    private static DateOnly? ParseDate(string? raw)
        => string.IsNullOrEmpty(raw) || raw == "null" ? null
           : DateOnly.TryParse(raw, out var d) ? d : null;

    private static readonly string[] AllowedColors =
        ["blue", "green", "purple", "orange", "amber", "red", "pink", "cyan"];
    private static bool ValidColor(string? color)
        => !string.IsNullOrEmpty(color) && AllowedColors.Contains(color, StringComparer.OrdinalIgnoreCase);
}

public sealed record HotPlateItemWrite(
    string Title,
    string? Description,
    string? ColumnKey,
    int? Priority,
    string? DueDate,        // yyyy-MM-dd
    Guid? CategoryId,
    string? EnergyLevel);

public sealed record HotPlateItemPatch(
    string? Title,
    string? Description,
    string? ColumnKey,
    int? Priority,
    string? DueDate,
    Guid? CategoryId,
    string? EnergyLevel,
    int? Position);

public sealed record CategoryWrite(string Name, string? Color);
public sealed record CategoryPatch(string? Name, string? Color, int? SortOrder);

public sealed record ReorderRequest(Guid Id, string ColumnKey, int Position);

using GingerSync.Core.Entities;

namespace GingerSync.Api.Endpoints;

public static class HotPlateEndpoints
{
    public static void MapHotPlateEndpoints(this IEndpointRouteBuilder app)
    {
        var items = app.MapGroup("/api/hot-plate/items").WithTags("Hot Plate");
        var cats = app.MapGroup("/api/hot-plate/categories").WithTags("Hot Plate");

        items.MapGet("/", () => Results.Ok(Array.Empty<HotPlateItem>()));
        items.MapPost("/", (HotPlateItem item) => Results.Created($"/api/hot-plate/items/{item.Id}", item));
        items.MapPatch("/{id:guid}", (Guid id, HotPlateItem patch) => Results.NoContent());
        items.MapDelete("/{id:guid}", (Guid id) => Results.NoContent());
        items.MapPost("/reorder", (ReorderRequest[] moves) => Results.NoContent())
            .WithDescription("Batch-update column + position after drag-drop.");

        cats.MapGet("/", () => Results.Ok(Array.Empty<HotPlateCategory>()));
        cats.MapPost("/", (HotPlateCategory cat) => Results.Created($"/api/hot-plate/categories/{cat.Id}", cat));
        cats.MapPatch("/{id:guid}", (Guid id, HotPlateCategory patch) => Results.NoContent());
        cats.MapDelete("/{id:guid}", (Guid id) => Results.NoContent());
    }
}

public sealed record ReorderRequest(Guid Id, string ColumnKey, int Position);

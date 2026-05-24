using GingerSync.Core.Entities;

namespace GingerSync.Api.Endpoints;

public static class MappingsEndpoints
{
    public static void MapMappingsEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/mappings").WithTags("Mappings");

        group.MapGet("/", () => Results.Ok(Array.Empty<Mapping>()))
            .WithDescription("List all mappings.");

        group.MapPost("/", (Mapping mapping) => Results.Created($"/api/mappings/{mapping.Id}", mapping))
            .WithDescription("Create a mapping (Trello board ↔ ClickUp list).");

        group.MapGet("/{id:guid}", (Guid id) => Results.NotFound())
            .WithDescription("Get a single mapping.");

        group.MapPatch("/{id:guid}", (Guid id, Mapping mapping) => Results.NoContent())
            .WithDescription("Update a mapping.");

        group.MapDelete("/{id:guid}", (Guid id) => Results.NoContent())
            .WithDescription("Delete a mapping.");

        group.MapPost("/{id:guid}/sync", (Guid id) => Results.Ok(new { stats = "todo" }))
            .WithDescription("Run a manual reconcile pass against this mapping.");
    }
}

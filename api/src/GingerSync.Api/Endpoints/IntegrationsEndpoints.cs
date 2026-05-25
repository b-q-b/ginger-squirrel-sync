using GingerSync.Core.Services;

namespace GingerSync.Api.Endpoints;

/// <summary>Quick connectivity probes for the third-party APIs we sync with.</summary>
public static class IntegrationsEndpoints
{
    public static void MapIntegrationsEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/integrations").WithTags("Integrations").RequireAuthorization();

        group.MapGet("/clickup", async (IClickUpClient cu, CancellationToken ct) =>
        {
            try
            {
                var me = await cu.GetMeAsync(ct);
                return Results.Ok(new { ok = true, user = me });
            }
            catch (InvalidOperationException ex)
            {
                return Results.Ok(new { ok = false, error = ex.Message });
            }
            catch (HttpRequestException ex)
            {
                return Results.Ok(new { ok = false, error = $"ClickUp {ex.StatusCode}: {ex.Message}" });
            }
        });

        group.MapGet("/trello", async (ITrelloClient tr, CancellationToken ct) =>
        {
            try
            {
                var me = await tr.GetMeAsync(ct);
                var boards = await tr.GetBoardsAsync(ct);
                return Results.Ok(new { ok = true, user = me, boards = boards.Count });
            }
            catch (InvalidOperationException ex)
            {
                return Results.Ok(new { ok = false, error = ex.Message });
            }
            catch (HttpRequestException ex)
            {
                return Results.Ok(new { ok = false, error = $"Trello {ex.StatusCode}: {ex.Message}" });
            }
        });
    }
}

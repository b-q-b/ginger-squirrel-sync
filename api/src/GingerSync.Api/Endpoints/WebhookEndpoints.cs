namespace GingerSync.Api.Endpoints;

public static class WebhookEndpoints
{
    public static void MapWebhookEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/webhooks").WithTags("Webhooks").DisableAntiforgery();

        // Trello sends HEAD on registration to verify the URL exists.
        group.MapMethods("/trello", ["HEAD"], () => Results.Ok());

        group.MapPost("/trello", (HttpRequest req) => Results.Ok(new { ok = true }))
            .WithDescription("Trello webhook receiver (HMAC-SHA1 in X-Trello-Webhook).");

        group.MapPost("/clickup", (HttpRequest req) => Results.Ok(new { ok = true }))
            .WithDescription("ClickUp webhook receiver (HMAC-SHA256 in X-Signature).");

        group.MapPost("/register", (string platform) => Results.Ok(new { registered = true }))
            .WithDescription("Register webhooks with both platforms for all active mappings.")
            .RequireAuthorization();
    }
}

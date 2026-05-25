using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using GingerSync.Infrastructure.Persistence;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;

namespace GingerSync.Api.Endpoints;

public static class WebhookEndpoints
{
    public static void MapWebhookEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/webhooks").WithTags("Webhooks").DisableAntiforgery();

        // ── Trello ──────────────────────────────────────────────────────
        // Trello sends HEAD on registration to verify the URL exists. Always 200.
        group.MapMethods("/trello", new[] { "HEAD" }, () => Results.Ok()).AllowAnonymous();

        group.MapPost("/trello", async (
            HttpContext http,
            GingerSyncDbContext db,
            ISyncEngine engine,
            IOptions<TrelloOptions> opts,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("TrelloWebhook");

            // Read the raw body — HMAC needs exact bytes
            using var reader = new StreamReader(http.Request.Body);
            var body = await reader.ReadToEndAsync(ct);

            // Verify HMAC-SHA1(body + callbackURL, TRELLO_SECRET) → base64 == X-Trello-Webhook
            var headerSig = http.Request.Headers["X-Trello-Webhook"].FirstOrDefault() ?? "";
            var secret = opts.Value.Secret;
            if (!string.IsNullOrEmpty(secret))
            {
                var callbackUrl = $"{http.Request.Scheme}://{http.Request.Host}{http.Request.Path}";
                using var hmac = new HMACSHA1(Encoding.UTF8.GetBytes(secret));
                var bytes = Encoding.UTF8.GetBytes(body + callbackUrl);
                var computed = Convert.ToBase64String(hmac.ComputeHash(bytes));
                if (!CryptographicOperations.FixedTimeEquals(
                    Encoding.UTF8.GetBytes(computed),
                    Encoding.UTF8.GetBytes(headerSig)))
                {
                    log.LogWarning("Trello webhook signature mismatch (got '{Sig}')", headerSig);
                    return Results.Unauthorized();
                }
            }

            // ACK fast — Trello disables the webhook after 5 consecutive non-2xx responses
            // We'll process in the background so the response goes out in <100ms.
            _ = Task.Run(async () =>
            {
                try { await ProcessTrelloEventAsync(body, db, engine, log); }
                catch (Exception ex) { log.LogError(ex, "Trello webhook processing failed"); }
            }, CancellationToken.None);

            return Results.Ok(new { ok = true });
        }).AllowAnonymous();

        // ── ClickUp ─────────────────────────────────────────────────────
        // ClickUp's webhook secret is per-webhook, set at registration time and
        // stored in webhook_registrations.target_id (we'll wire signature
        // verification when the register endpoint lands). For now we trust the
        // payload and rely on echo-guard (last_hash) to ignore replays.
        group.MapPost("/clickup", async (
            HttpContext http,
            GingerSyncDbContext db,
            ISyncEngine engine,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("ClickUpWebhook");
            using var reader = new StreamReader(http.Request.Body);
            var body = await reader.ReadToEndAsync(ct);

            _ = Task.Run(async () =>
            {
                try { await ProcessClickUpEventAsync(body, db, engine, log); }
                catch (Exception ex) { log.LogError(ex, "ClickUp webhook processing failed"); }
            }, CancellationToken.None);

            return Results.Ok(new { ok = true });
        }).AllowAnonymous();
    }

    // ── Body parsers + dispatch ────────────────────────────────────────────
    private static async Task ProcessTrelloEventAsync(
        string body, GingerSyncDbContext db, ISyncEngine engine, ILogger log)
    {
        if (string.IsNullOrWhiteSpace(body)) return;
        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;

        // Expected shape: { "action": { "type", "data": { "card": { "id" }, "board": { "id" } } } }
        if (!root.TryGetProperty("action", out var action)) return;

        var actionType = action.TryGetProperty("type", out var t) ? t.GetString() : null;
        if (!action.TryGetProperty("data", out var data)) return;

        var cardId = data.TryGetProperty("card", out var card) && card.TryGetProperty("id", out var cid)
            ? cid.GetString() : null;
        var boardId = data.TryGetProperty("board", out var board) && board.TryGetProperty("id", out var bid)
            ? bid.GetString() : null;

        if (string.IsNullOrEmpty(cardId) || string.IsNullOrEmpty(boardId))
        {
            log.LogDebug("Trello webhook missing card/board id (action {Type}) — ignored", actionType);
            return;
        }

        // Find active mapping for this board
        var mapping = await db.Mappings.AsNoTracking()
            .FirstOrDefaultAsync(m => m.TrelloBoardId == boardId && m.IsActive);
        if (mapping is null)
        {
            log.LogDebug("No active mapping for Trello board {BoardId}; action {Type} dropped", boardId, actionType);
            return;
        }

        log.LogInformation("Trello webhook → {ActionType} on card {CardId} (mapping {Label})", actionType, cardId, mapping.Label);
        var result = await engine.SyncTrelloCardToClickUpAsync(mapping, cardId, "trello_webhook");
        log.LogInformation("  → {Action} {Error}", result.Action, result.Error ?? "");
    }

    private static async Task ProcessClickUpEventAsync(
        string body, GingerSyncDbContext db, ISyncEngine engine, ILogger log)
    {
        if (string.IsNullOrWhiteSpace(body)) return;
        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;

        var eventType = root.TryGetProperty("event", out var ev) ? ev.GetString() : null;
        var taskId = root.TryGetProperty("task_id", out var tid) ? tid.GetString() : null;
        var listId = root.TryGetProperty("list_id", out var lid) ? lid.GetString() : null;

        if (string.IsNullOrEmpty(taskId))
        {
            log.LogDebug("ClickUp webhook missing task_id (event {Type}) — ignored", eventType);
            return;
        }

        Mapping? mapping = null;
        if (!string.IsNullOrEmpty(listId))
        {
            mapping = await db.Mappings.AsNoTracking()
                .FirstOrDefaultAsync(m => m.ClickUpListId == listId && m.IsActive);
        }
        // Fallback: look up sync_map by clickup_task_id
        if (mapping is null)
        {
            var sm = await db.SyncMaps.AsNoTracking()
                .FirstOrDefaultAsync(s => s.ClickUpTaskId == taskId && s.DeletedAt == null);
            if (sm != null)
            {
                mapping = await db.Mappings.AsNoTracking()
                    .FirstOrDefaultAsync(m => m.Id == sm.MappingId && m.IsActive);
            }
        }
        if (mapping is null)
        {
            log.LogDebug("No active mapping for ClickUp task {TaskId} (event {Type}); dropped", taskId, eventType);
            return;
        }

        log.LogInformation("ClickUp webhook → {EventType} on task {TaskId} (mapping {Label})", eventType, taskId, mapping.Label);
        var result = await engine.SyncClickUpTaskToTrelloAsync(mapping, taskId, "clickup_webhook");
        log.LogInformation("  → {Action} {Error}", result.Action, result.Error ?? "");
    }
}

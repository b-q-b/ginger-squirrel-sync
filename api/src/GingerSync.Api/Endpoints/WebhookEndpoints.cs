using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using GingerSync.Infrastructure;
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

        // ── Registration management (authed) ────────────────────────────
        var admin = app.MapGroup("/api/webhooks").WithTags("Webhooks").RequireAuthorization();

        admin.MapGet("/", async (GingerSyncDbContext db, CancellationToken ct) =>
        {
            var rows = await db.WebhookRegistrations.AsNoTracking()
                .OrderByDescending(w => w.CreatedAt)
                .ToListAsync(ct);
            return Results.Ok(rows);
        });

        admin.MapPost("/register", async (
            HttpRequest req,
            GingerSyncDbContext db,
            IHttpClientFactory http,
            IOptions<TrelloOptions> trelloOpts,
            IOptions<ClickUpOptions> clickupOpts,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("WebhookRegister");
            var baseUrl = $"{req.Scheme}://{req.Host}";
            var trelloHook = $"{baseUrl}/api/webhooks/trello";
            var clickupHook = $"{baseUrl}/api/webhooks/clickup";

            var mappings = await db.Mappings.AsNoTracking().Where(m => m.IsActive).ToListAsync(ct);
            var existing = await db.WebhookRegistrations.AsNoTracking()
                .Where(w => w.Status == "active")
                .ToListAsync(ct);
            var seenTrello = existing.Where(w => w.Platform == "trello").Select(w => w.TargetId).ToHashSet();
            var seenClickUp = existing.Where(w => w.Platform == "clickup").Select(w => w.TargetId).ToHashSet();

            var results = new List<object>();

            // ── Trello: one webhook per board ──
            using var trelloHttp = http.CreateClient();
            trelloHttp.BaseAddress = new Uri("https://api.trello.com/1/");
            trelloHttp.Timeout = TimeSpan.FromSeconds(20);

            var boardIds = mappings.Select(m => m.TrelloBoardId).Where(b => !string.IsNullOrEmpty(b)).Distinct().ToList();
            foreach (var bid in boardIds)
            {
                if (seenTrello.Contains(bid))
                {
                    results.Add(new { platform = "trello", target_id = bid, status = "already-registered" });
                    continue;
                }
                if (string.IsNullOrEmpty(trelloOpts.Value.Key) || string.IsNullOrEmpty(trelloOpts.Value.Token))
                {
                    results.Add(new { platform = "trello", target_id = bid, status = "error", error = "Trello credentials not configured" });
                    continue;
                }

                var payload = new
                {
                    callbackURL = trelloHook,
                    idModel = bid,
                    description = $"Ginger Sync — board {bid}",
                };
                var qs = $"?key={Uri.EscapeDataString(trelloOpts.Value.Key)}&token={Uri.EscapeDataString(trelloOpts.Value.Token)}";
                using var trResp = await trelloHttp.PostAsJsonAsync("webhooks" + qs, payload, ct);
                var trBody = await trResp.Content.ReadAsStringAsync(ct);
                if (!trResp.IsSuccessStatusCode)
                {
                    log.LogWarning("Trello webhook register failed for {Board}: {Status} {Body}", bid, (int)trResp.StatusCode, trBody);
                    results.Add(new { platform = "trello", target_id = bid, status = "error", error = $"{(int)trResp.StatusCode}: {trBody}" });
                    continue;
                }
                using var trDoc = JsonDocument.Parse(trBody);
                var whId = trDoc.RootElement.TryGetProperty("id", out var i) ? i.GetString() : null;
                db.WebhookRegistrations.Add(new WebhookRegistration
                {
                    Id = Guid.NewGuid(),
                    Platform = "trello",
                    ExternalId = whId ?? "",
                    TargetId = bid,
                    Status = "active",
                    LastCheckedAt = DateTimeOffset.UtcNow,
                });
                results.Add(new { platform = "trello", target_id = bid, status = "registered", webhook_id = whId });
            }

            // ── ClickUp: one webhook per team, filtered to the first mapped list ──
            using var clickupHttp = http.CreateClient();
            clickupHttp.BaseAddress = new Uri("https://api.clickup.com/api/v2/");
            clickupHttp.Timeout = TimeSpan.FromSeconds(20);
            if (!string.IsNullOrEmpty(clickupOpts.Value.Token))
                clickupHttp.DefaultRequestHeaders.TryAddWithoutValidation("Authorization", clickupOpts.Value.Token);

            if (string.IsNullOrEmpty(clickupOpts.Value.Token))
            {
                results.Add(new { platform = "clickup", status = "error", error = "ClickUp token not configured" });
            }
            else
            {
                using var teamsResp = await clickupHttp.GetAsync("team", ct);
                var teamsBody = await teamsResp.Content.ReadAsStringAsync(ct);
                if (!teamsResp.IsSuccessStatusCode)
                {
                    results.Add(new { platform = "clickup", status = "error", error = $"team list {(int)teamsResp.StatusCode}: {teamsBody}" });
                }
                else
                {
                    using var teamsDoc = JsonDocument.Parse(teamsBody);
                    var teamIds = teamsDoc.RootElement.TryGetProperty("teams", out var tArr) && tArr.ValueKind == JsonValueKind.Array
                        ? tArr.EnumerateArray().Select(t => t.TryGetProperty("id", out var id) ? id.GetString() ?? "" : "").Where(s => !string.IsNullOrEmpty(s)).ToList()
                        : new List<string>();

                    var listIds = mappings.Select(m => m.ClickUpListId).Where(l => !string.IsNullOrEmpty(l)).Distinct().ToList();

                    foreach (var teamId in teamIds)
                    {
                        if (seenClickUp.Contains(teamId))
                        {
                            results.Add(new { platform = "clickup", target_id = teamId, status = "already-registered" });
                            continue;
                        }
                        var body = new Dictionary<string, object?>
                        {
                            ["endpoint"] = clickupHook,
                            ["events"] = new[] { "taskCreated", "taskUpdated", "taskDeleted", "taskStatusUpdated" },
                        };
                        if (listIds.Count > 0) body["list_id"] = listIds[0];

                        using var cuResp = await clickupHttp.PostAsJsonAsync($"team/{Uri.EscapeDataString(teamId)}/webhook", body, ct);
                        var cuBody = await cuResp.Content.ReadAsStringAsync(ct);
                        if (!cuResp.IsSuccessStatusCode)
                        {
                            log.LogWarning("ClickUp webhook register failed for team {Team}: {Status} {Body}", teamId, (int)cuResp.StatusCode, cuBody);
                            results.Add(new { platform = "clickup", target_id = teamId, status = "error", error = $"{(int)cuResp.StatusCode}: {cuBody}" });
                            continue;
                        }
                        using var cuDoc = JsonDocument.Parse(cuBody);
                        string? whId = null;
                        if (cuDoc.RootElement.TryGetProperty("id", out var topId)) whId = topId.GetString();
                        else if (cuDoc.RootElement.TryGetProperty("webhook", out var wh) && wh.TryGetProperty("id", out var wId))
                            whId = wId.GetString();
                        db.WebhookRegistrations.Add(new WebhookRegistration
                        {
                            Id = Guid.NewGuid(),
                            Platform = "clickup",
                            ExternalId = whId ?? "",
                            TargetId = teamId,
                            Status = "active",
                            LastCheckedAt = DateTimeOffset.UtcNow,
                        });
                        results.Add(new { platform = "clickup", target_id = teamId, status = "registered", webhook_id = whId });
                    }
                }
            }

            await db.SaveChangesAsync(ct);
            return Results.Ok(new { ok = true, results });
        })
        .WithDescription("Register webhooks with Trello + ClickUp for all active mappings. Idempotent.");

        admin.MapDelete("/{id:guid}", async (
            Guid id,
            GingerSyncDbContext db,
            IHttpClientFactory http,
            IOptions<TrelloOptions> trelloOpts,
            IOptions<ClickUpOptions> clickupOpts,
            ILoggerFactory loggerFactory,
            CancellationToken ct) =>
        {
            var log = loggerFactory.CreateLogger("WebhookDelete");
            var w = await db.WebhookRegistrations.FirstOrDefaultAsync(x => x.Id == id, ct);
            if (w is null) return Results.NotFound();

            try
            {
                if (w.Platform == "trello" && !string.IsNullOrEmpty(w.ExternalId))
                {
                    using var trelloHttp = http.CreateClient();
                    trelloHttp.BaseAddress = new Uri("https://api.trello.com/1/");
                    var qs = $"?key={Uri.EscapeDataString(trelloOpts.Value.Key)}&token={Uri.EscapeDataString(trelloOpts.Value.Token)}";
                    using var resp = await trelloHttp.DeleteAsync($"webhooks/{Uri.EscapeDataString(w.ExternalId)}{qs}", ct);
                    if (!resp.IsSuccessStatusCode)
                        log.LogWarning("Trello DELETE webhook {Id} → {Status}", w.ExternalId, (int)resp.StatusCode);
                }
                else if (w.Platform == "clickup" && !string.IsNullOrEmpty(w.ExternalId) && !string.IsNullOrEmpty(clickupOpts.Value.Token))
                {
                    using var cuHttp = http.CreateClient();
                    cuHttp.BaseAddress = new Uri("https://api.clickup.com/api/v2/");
                    cuHttp.DefaultRequestHeaders.TryAddWithoutValidation("Authorization", clickupOpts.Value.Token);
                    using var resp = await cuHttp.DeleteAsync($"webhook/{Uri.EscapeDataString(w.ExternalId)}", ct);
                    if (!resp.IsSuccessStatusCode)
                        log.LogWarning("ClickUp DELETE webhook {Id} → {Status}", w.ExternalId, (int)resp.StatusCode);
                }
            }
            catch (Exception ex)
            {
                log.LogWarning(ex, "Failed to delete upstream webhook {Id}", w.ExternalId);
            }

            db.WebhookRegistrations.Remove(w);
            await db.SaveChangesAsync(ct);
            return Results.NoContent();
        });
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

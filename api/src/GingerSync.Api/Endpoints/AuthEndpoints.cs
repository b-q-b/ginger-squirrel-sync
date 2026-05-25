using GingerSync.Core.Services;
using GingerSync.Infrastructure.Auth;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Options;

namespace GingerSync.Api.Endpoints;

public static class AuthEndpoints
{
    public static void MapAuthEndpoints(this IEndpointRouteBuilder app)
    {
        var group = app.MapGroup("/api/auth").WithTags("Auth");

        group.MapPost("/login", async (
            [FromBody] LoginRequest body,
            IAuthService auth,
            IOptions<AuthOptions> opts,
            HttpContext http,
            CancellationToken ct) =>
        {
            var result = await auth.LoginAsync(body?.Password ?? "", ct);
            if (!result.Ok)
            {
                return Results.Json(new { ok = false, error = result.Error }, statusCode: 401);
            }

            http.Response.Cookies.Append(opts.Value.CookieName, result.Token!, new CookieOptions
            {
                HttpOnly = true,
                Secure = !http.Request.Host.Host.Equals("localhost", StringComparison.OrdinalIgnoreCase),
                SameSite = SameSiteMode.Lax,
                Expires = result.ExpiresAt,
                Path = "/",
            });
            return Results.Ok(new { ok = true, expiresAt = result.ExpiresAt });
        }).AllowAnonymous();

        group.MapPost("/logout", (HttpContext http, IOptions<AuthOptions> opts) =>
        {
            http.Response.Cookies.Delete(opts.Value.CookieName, new CookieOptions { Path = "/" });
            return Results.Ok(new { ok = true });
        }).AllowAnonymous();

        group.MapGet("/me", (HttpContext http) =>
        {
            var user = http.User;
            if (user?.Identity?.IsAuthenticated != true)
                return Results.Json(new { ok = false }, statusCode: 401);

            return Results.Ok(new
            {
                ok = true,
                sub = user.FindFirst("sub")?.Value,
            });
        }).RequireAuthorization();
    }
}

public sealed record LoginRequest(string Password);

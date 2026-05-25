using System.Text;
using GingerSync.Api.Endpoints;
using GingerSync.Infrastructure;
using GingerSync.Infrastructure.Auth;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.IdentityModel.Tokens;

var builder = WebApplication.CreateBuilder(args);

// ── Configuration sources ──────────────────────────────────────
builder.Configuration
    .AddJsonFile("appsettings.json", optional: false)
    .AddJsonFile($"appsettings.{builder.Environment.EnvironmentName}.json", optional: true)
    .AddEnvironmentVariables(prefix: "GINGERSYNC_");

// ── Services ───────────────────────────────────────────────────
builder.Services.AddOpenApi();
builder.Services.AddProblemDetails();
builder.Services.AddGingerSyncInfrastructure(builder.Configuration);

// Meeting audio uploads can be large (long-form recordings).
const long MaxUploadBytes = 200L * 1024 * 1024;
builder.Services.Configure<Microsoft.AspNetCore.Http.Features.FormOptions>(o =>
{
    o.MultipartBodyLengthLimit = MaxUploadBytes;
    o.ValueLengthLimit = int.MaxValue;
});
builder.Services.Configure<Microsoft.AspNetCore.Server.Kestrel.Core.KestrelServerOptions>(o =>
{
    o.Limits.MaxRequestBodySize = MaxUploadBytes;
});

// Auth — JWT bearer reading from the gss_auth HttpOnly cookie
var authOpts = builder.Configuration.GetSection("Auth").Get<AuthOptions>() ?? new AuthOptions();
builder.Services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
    .AddJwtBearer(opts =>
    {
        opts.TokenValidationParameters = new TokenValidationParameters
        {
            ValidateIssuer = true,
            ValidIssuer = authOpts.Issuer,
            ValidateAudience = true,
            ValidAudience = authOpts.Audience,
            ValidateLifetime = true,
            ValidateIssuerSigningKey = true,
            IssuerSigningKey = string.IsNullOrEmpty(authOpts.JwtSecret)
                ? new SymmetricSecurityKey(new byte[32])  // placeholder — will reject all tokens
                : new SymmetricSecurityKey(Encoding.UTF8.GetBytes(authOpts.JwtSecret)),
            ClockSkew = TimeSpan.FromMinutes(2),
        };
        // Read JWT from the cookie instead of the Authorization header
        opts.Events = new JwtBearerEvents
        {
            OnMessageReceived = ctx =>
            {
                if (ctx.Request.Cookies.TryGetValue(authOpts.CookieName, out var token))
                    ctx.Token = token;
                return Task.CompletedTask;
            },
        };
    });
builder.Services.AddAuthorization();

// CORS — only needed for cross-origin dev. Production runs api + web on the same host.
var allowedOrigins = builder.Configuration.GetSection("AllowedOrigins").Get<string[]>() ?? [];
builder.Services.AddCors(opts =>
    opts.AddDefaultPolicy(p => p
        .WithOrigins(allowedOrigins)
        .AllowAnyHeader()
        .AllowAnyMethod()
        .AllowCredentials()));

var app = builder.Build();

// ── Pipeline ──────────────────────────────────────────────────
if (app.Environment.IsDevelopment())
{
    app.MapOpenApi();
}

app.UseCors();
app.UseAuthentication();
app.UseAuthorization();
app.UseExceptionHandler();
app.UseStatusCodePages();

// ── Endpoints ─────────────────────────────────────────────────
app.MapGet("/health", () => Results.Ok(new { ok = true, version = "0.1.0" }))
    .WithName("Health")
    .AllowAnonymous();

app.MapAuthEndpoints();
app.MapMappingsEndpoints();
app.MapHotPlateEndpoints();
app.MapMeetingsEndpoints();
app.MapSyncEndpoints();
app.MapWebhookEndpoints();
app.MapIntegrationsEndpoints();

app.Run();

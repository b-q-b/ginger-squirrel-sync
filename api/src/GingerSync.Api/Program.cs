using GingerSync.Api.Endpoints;
using GingerSync.Infrastructure;

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

// CORS — React frontend in dev (Vite/Next) and the deployed origin
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
app.UseExceptionHandler();
app.UseStatusCodePages();

// ── Endpoints ─────────────────────────────────────────────────
app.MapGet("/health", () => Results.Ok(new { ok = true, version = "0.1.0" }))
    .WithName("Health")
    .WithDescription("Liveness probe.");

app.MapMappingsEndpoints();
app.MapHotPlateEndpoints();
app.MapMeetingsEndpoints();
app.MapSyncEndpoints();
app.MapWebhookEndpoints();
app.MapIntegrationsEndpoints();

app.Run();

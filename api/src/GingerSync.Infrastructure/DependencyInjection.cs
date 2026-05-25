using GingerSync.Core.Services;
using GingerSync.Infrastructure.Auth;
using GingerSync.Infrastructure.External;
using GingerSync.Infrastructure.Hosting;
using GingerSync.Infrastructure.Persistence;
using GingerSync.Infrastructure.Sync;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;

namespace GingerSync.Infrastructure;

public static class DependencyInjection
{
    public static IServiceCollection AddGingerSyncInfrastructure(this IServiceCollection services, IConfiguration config)
    {
        // Database
        services.AddDbContext<GingerSyncDbContext>(opts =>
            opts.UseNpgsql(config.GetConnectionString("Postgres")
                ?? throw new InvalidOperationException("ConnectionStrings:Postgres is required.")));

        // Options binding
        services.Configure<ClickUpOptions>(config.GetSection("ClickUp"));
        services.Configure<TrelloOptions>(config.GetSection("Trello"));
        services.Configure<AssemblyAIOptions>(config.GetSection("AssemblyAI"));
        services.Configure<OpenRouterOptions>(config.GetSection("OpenRouter"));
        services.Configure<StorageOptions>(config.GetSection("Storage"));
        services.Configure<SyncOptions>(config.GetSection("Sync"));
        services.Configure<AuthOptions>(config.GetSection("Auth"));

        // Auth
        services.AddScoped<IAuthService, JwtAuthService>();

        // Sync engine + background reconciler (replaces the v1 PHP cron)
        services.AddScoped<ISyncEngine, SyncEngine>();
        services.AddHostedService<ReconcileWorker>();

        // Typed HTTP clients
        services.AddHttpClient<IClickUpClient, ClickUpClient>();
        services.AddHttpClient<ITrelloClient, TrelloClient>();
        services.AddHttpClient<ITranscriber, AssemblyAIClient>();
        services.AddHttpClient<IMeetingAnalyzer, OpenRouterAnalyzer>();

        // Sync engine (TODO: implement in Phase B)
        // services.AddScoped<ISyncEngine, SyncEngine>();

        return services;
    }
}

public sealed class ClickUpOptions { public string Token { get; set; } = ""; }
public sealed class TrelloOptions { public string Key { get; set; } = ""; public string Token { get; set; } = ""; public string Secret { get; set; } = ""; }
public sealed class AssemblyAIOptions { public string ApiKey { get; set; } = ""; }
public sealed class OpenRouterOptions { public string ApiKey { get; set; } = ""; public string Model { get; set; } = "anthropic/claude-sonnet-4.5"; }
public sealed class StorageOptions { public string AudioRoot { get; set; } = "data/meetings"; }
public sealed class SyncOptions { public int ReconcileIntervalMinutes { get; set; } = 5; public int EchoDebounceSeconds { get; set; } = 60; }

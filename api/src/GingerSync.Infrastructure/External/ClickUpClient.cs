using System.Net.Http.Json;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>ClickUp v2 REST client. Direct port of legacy-php/config/clickup.php.</summary>
public sealed class ClickUpClient : IClickUpClient
{
    private readonly HttpClient _http;
    private readonly ClickUpOptions _opts;

    public ClickUpClient(HttpClient http, IOptions<ClickUpOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.clickup.com/api/v2/");
        _http.DefaultRequestHeaders.Add("Authorization", _opts.Token);
        _http.Timeout = TimeSpan.FromSeconds(15);
    }

    public async Task<ClickUpUser> GetMeAsync(CancellationToken ct = default)
    {
        // TODO: parse the actual ClickUp /user response shape (it wraps under "user")
        await Task.CompletedTask;
        throw new NotImplementedException("Implement in Phase B.");
    }

    public Task<IReadOnlyList<ClickUpTask>> GetListTasksAsync(string listId, bool includeSubtasks = false, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<ClickUpTask> GetTaskAsync(string taskId, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<ClickUpTask> CreateTaskAsync(string listId, ClickUpTaskWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<ClickUpTask> UpdateTaskAsync(string taskId, ClickUpTaskWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task DeleteTaskAsync(string taskId, CancellationToken ct = default)
        => throw new NotImplementedException();
}

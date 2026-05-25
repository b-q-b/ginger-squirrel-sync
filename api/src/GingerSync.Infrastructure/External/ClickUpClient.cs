using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>ClickUp v2 REST client. Initial coverage: /user + listing helpers
/// needed for the integration test endpoint and the mappings picker.
/// Full task CRUD lands with the sync engine in slice 3.</summary>
public sealed class ClickUpClient : IClickUpClient
{
    private readonly HttpClient _http;
    private readonly ClickUpOptions _opts;
    private static readonly JsonSerializerOptions JsonOpts = new(JsonSerializerDefaults.Web)
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    public ClickUpClient(HttpClient http, IOptions<ClickUpOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.clickup.com/api/v2/");
        if (!string.IsNullOrEmpty(_opts.Token))
        {
            _http.DefaultRequestHeaders.TryAddWithoutValidation("Authorization", _opts.Token);
        }
        _http.Timeout = TimeSpan.FromSeconds(15);
    }

    public bool Configured => !string.IsNullOrEmpty(_opts.Token);

    public async Task<ClickUpUser> GetMeAsync(CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("ClickUp token not configured.");
        // ClickUp's /user wraps the user under { "user": { ... } }
        using var res = await _http.GetAsync("user", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var wrapper = await res.Content.ReadFromJsonAsync<UserWrapper>(JsonOpts, ct).ConfigureAwait(false);
        var u = wrapper?.User ?? throw new InvalidOperationException("ClickUp /user returned no user.");
        return new ClickUpUser(u.Id, u.Username ?? "", u.Email);
    }

    public Task<IReadOnlyList<ClickUpTask>> GetListTasksAsync(string listId, bool includeSubtasks = false, CancellationToken ct = default)
        => throw new NotImplementedException("Lands in slice 3 (sync engine).");
    public Task<ClickUpTask> GetTaskAsync(string taskId, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<ClickUpTask> CreateTaskAsync(string listId, ClickUpTaskWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<ClickUpTask> UpdateTaskAsync(string taskId, ClickUpTaskWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task DeleteTaskAsync(string taskId, CancellationToken ct = default)
        => throw new NotImplementedException();

    private sealed record UserWrapper([property: JsonPropertyName("user")] UserDto? User);
    private sealed record UserDto(long Id, string? Username, string? Email);
}

using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>ClickUp v2 REST client.</summary>
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
        _http.Timeout = TimeSpan.FromSeconds(20);
    }

    public bool Configured => !string.IsNullOrEmpty(_opts.Token);

    private void EnsureConfigured()
    {
        if (!Configured) throw new InvalidOperationException("ClickUp token not configured.");
    }

    public async Task<ClickUpUser> GetMeAsync(CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync("user", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var w = await res.Content.ReadFromJsonAsync<UserWrapper>(JsonOpts, ct).ConfigureAwait(false);
        var u = w?.User ?? throw new InvalidOperationException("ClickUp /user returned no user.");
        return new ClickUpUser(u.Id, u.Username ?? "", u.Email);
    }

    public async Task<IReadOnlyList<ClickUpTask>> GetListTasksAsync(string listId, bool includeSubtasks = false, CancellationToken ct = default)
    {
        EnsureConfigured();
        var all = new List<TaskDto>();
        int page = 0;
        while (true)
        {
            var url = $"list/{Uri.EscapeDataString(listId)}/task?archived=false&subtasks={(includeSubtasks ? "true" : "false")}&page={page}";
            using var res = await _http.GetAsync(url, ct).ConfigureAwait(false);
            res.EnsureSuccessStatusCode();
            var w = await res.Content.ReadFromJsonAsync<TaskListWrapper>(JsonOpts, ct).ConfigureAwait(false);
            var batch = w?.Tasks ?? [];
            if (batch.Count == 0) break;
            all.AddRange(batch);
            if (batch.Count < 100) break;  // ClickUp pages at 100
            page++;
            if (page > 200) break;          // safety brake
        }
        return all.Select(ToDomain).ToList();
    }

    public async Task<ClickUpTask> GetTaskAsync(string taskId, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync($"task/{Uri.EscapeDataString(taskId)}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var dto = await res.Content.ReadFromJsonAsync<TaskDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("ClickUp returned no task.");
        return ToDomain(dto);
    }

    public async Task<ClickUpTask> CreateTaskAsync(string listId, ClickUpTaskWrite payload, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.PostAsJsonAsync($"list/{Uri.EscapeDataString(listId)}/task", payload, JsonOpts, ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"ClickUp create-task failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
        var dto = await res.Content.ReadFromJsonAsync<TaskDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("ClickUp returned no task on create.");
        return ToDomain(dto);
    }

    public async Task<ClickUpTask> UpdateTaskAsync(string taskId, ClickUpTaskWrite payload, CancellationToken ct = default)
    {
        EnsureConfigured();
        // ClickUp's PUT /task/{id} doesn't accept `tags` — those need a separate POST per tag.
        // For now we update name/description/status/due_date; label sync we'll add in 3b.
        var basic = new
        {
            payload.Name,
            payload.Description,
            payload.Status,
            payload.DueDate,
            due_date_time = payload.DueDateTime,
        };
        using var req = new HttpRequestMessage(HttpMethod.Put, $"task/{Uri.EscapeDataString(taskId)}")
        {
            Content = JsonContent.Create(basic, options: JsonOpts),
        };
        using var res = await _http.SendAsync(req, ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"ClickUp update-task failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
        var dto = await res.Content.ReadFromJsonAsync<TaskDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("ClickUp returned no task on update.");
        return ToDomain(dto);
    }

    public async Task DeleteTaskAsync(string taskId, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.DeleteAsync($"task/{Uri.EscapeDataString(taskId)}", ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"ClickUp delete-task failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
    }

    private static ClickUpTask ToDomain(TaskDto t)
    {
        return new ClickUpTask(
            Id: t.Id ?? "",
            Name: t.Name ?? "",
            Description: t.Description ?? t.TextContent,
            Status: t.Status is null ? null : new ClickUpStatus(t.Status.Status ?? "", t.Status.Color),
            DueDate: ParseLong(t.DueDate),
            DateUpdated: ParseLong(t.DateUpdated) ?? 0,
            Parent: t.Parent,
            Tags: (t.Tags ?? []).Select(tg => new ClickUpTag(tg.Name ?? "", tg.TagFg, tg.TagBg)).ToList(),
            Url: t.Url ?? ""
        );
    }

    private static long? ParseLong(string? s)
    {
        if (string.IsNullOrEmpty(s)) return null;
        return long.TryParse(s, out var v) ? v : null;
    }

    private sealed record UserWrapper([property: JsonPropertyName("user")] UserDto? User);
    private sealed record UserDto(long Id, string? Username, string? Email);

    private sealed record TaskListWrapper([property: JsonPropertyName("tasks")] List<TaskDto>? Tasks);

    private sealed class TaskDto
    {
        public string? Id { get; set; }
        public string? Name { get; set; }
        [JsonPropertyName("description")] public string? Description { get; set; }
        [JsonPropertyName("text_content")] public string? TextContent { get; set; }
        public StatusDto? Status { get; set; }
        [JsonPropertyName("due_date")] public string? DueDate { get; set; }
        [JsonPropertyName("date_updated")] public string? DateUpdated { get; set; }
        public string? Parent { get; set; }
        public List<TagDto>? Tags { get; set; }
        public string? Url { get; set; }
    }

    private sealed class StatusDto
    {
        public string? Status { get; set; }
        public string? Color { get; set; }
    }

    private sealed class TagDto
    {
        public string? Name { get; set; }
        [JsonPropertyName("tag_fg")] public string? TagFg { get; set; }
        [JsonPropertyName("tag_bg")] public string? TagBg { get; set; }
    }
}

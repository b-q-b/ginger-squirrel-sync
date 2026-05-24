using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>Calls OpenRouter → Claude with json_object response format to produce
/// MeetingAnalysis (summary / decisions / action_items / questions).</summary>
public sealed class OpenRouterAnalyzer : IMeetingAnalyzer
{
    private readonly HttpClient _http;
    private readonly OpenRouterOptions _opts;

    public OpenRouterAnalyzer(HttpClient http, IOptions<OpenRouterOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://openrouter.ai/api/v1/");
        if (!string.IsNullOrWhiteSpace(_opts.ApiKey))
            _http.DefaultRequestHeaders.Add("Authorization", $"Bearer {_opts.ApiKey}");
        _http.DefaultRequestHeaders.Add("HTTP-Referer", "https://bqbstudio.com");
        _http.DefaultRequestHeaders.Add("X-Title", "Ginger Sync");
    }

    public Task<MeetingAnalysis> AnalyzeAsync(string transcript, string? title, CancellationToken ct = default)
        => throw new NotImplementedException("Port legacy-php/lib/analyze.php prompt + json schema.");
}

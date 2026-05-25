using System.Text;
using System.Text.Json;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>
/// Calls OpenRouter → Claude with response_format=json_object to produce
/// MeetingAnalysis (summary / decisions / action_items / questions).
/// Port of legacy-php/lib/analyze.php — same prompt, same schema.
/// </summary>
public sealed class OpenRouterAnalyzer : IMeetingAnalyzer
{
    private const string Prompt = """
You analyze meeting transcripts and return structured JSON.

Output ONLY a JSON object (no prose) with exactly these keys:
  - "summary": array of 3 to 5 short bullet strings, each a self-contained sentence
  - "decisions": array of { "text": string }  (concrete decisions made)
  - "action_items": array of { "title": string, "owner": string|null, "due": string|null, "context": string|null }
  - "questions": array of { "question": string, "context": string|null }  (open questions raised but not answered)

If a section has no entries, return an empty array. Do not invent owners or due dates not present in the transcript.
Avoid filler words ("leverage", "streamline", "empower"). Use plain language. Do not use em-dashes.
""";

    private readonly HttpClient _http;
    private readonly OpenRouterOptions _opts;

    public OpenRouterAnalyzer(HttpClient http, IOptions<OpenRouterOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://openrouter.ai/api/v1/");
        _http.Timeout = TimeSpan.FromSeconds(120);
        if (!string.IsNullOrWhiteSpace(_opts.ApiKey))
            _http.DefaultRequestHeaders.Add("Authorization", $"Bearer {_opts.ApiKey}");
        _http.DefaultRequestHeaders.Add("HTTP-Referer", "https://ginger-sync.bqbstudio.com");
        _http.DefaultRequestHeaders.Add("X-Title", "Ginger Sync");
    }

    public bool Configured => !string.IsNullOrWhiteSpace(_opts.ApiKey);

    public async Task<MeetingAnalysis> AnalyzeAsync(string transcript, string? title, CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("OPENROUTER_API_KEY not set");
        if (string.IsNullOrWhiteSpace(transcript)) throw new ArgumentException("empty transcript");

        var user = string.IsNullOrWhiteSpace(title)
            ? $"Transcript:\n\n{transcript}"
            : $"Meeting title: {title}\n\nTranscript:\n\n{transcript}";

        var payload = new
        {
            model = string.IsNullOrWhiteSpace(_opts.Model) ? "anthropic/claude-sonnet-4.5" : _opts.Model,
            messages = new object[]
            {
                new { role = "system", content = Prompt },
                new { role = "user",   content = user },
            },
            response_format = new { type = "json_object" },
            temperature = 0.2,
            max_tokens = 2000,
        };

        using var req = new HttpRequestMessage(HttpMethod.Post, "chat/completions")
        {
            Content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json"),
        };
        using var resp = await _http.SendAsync(req, ct);
        var body = await resp.Content.ReadAsStringAsync(ct);
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"OpenRouter {(int)resp.StatusCode}: {body}");

        using var doc = JsonDocument.Parse(body);
        var content = doc.RootElement
            .GetProperty("choices")[0]
            .GetProperty("message")
            .GetProperty("content")
            .GetString();
        if (string.IsNullOrWhiteSpace(content))
            throw new InvalidOperationException("OpenRouter returned an empty completion");

        return ParseAnalysis(content);
    }

    private static MeetingAnalysis ParseAnalysis(string json)
    {
        using var doc = JsonDocument.Parse(json);
        var root = doc.RootElement;

        var summary = new List<string>();
        if (root.TryGetProperty("summary", out var sArr) && sArr.ValueKind == JsonValueKind.Array)
            foreach (var s in sArr.EnumerateArray())
                if (s.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(s.GetString()))
                    summary.Add(s.GetString()!);

        var decisions = new List<Decision>();
        if (root.TryGetProperty("decisions", out var dArr) && dArr.ValueKind == JsonValueKind.Array)
            foreach (var d in dArr.EnumerateArray())
            {
                var text = d.TryGetProperty("text", out var t) ? t.GetString() : null;
                if (!string.IsNullOrWhiteSpace(text)) decisions.Add(new Decision { Text = text! });
            }

        var actions = new List<ActionItem>();
        if (root.TryGetProperty("action_items", out var aArr) && aArr.ValueKind == JsonValueKind.Array)
            foreach (var a in aArr.EnumerateArray())
            {
                var title = a.TryGetProperty("title", out var t) ? t.GetString() : null;
                if (string.IsNullOrWhiteSpace(title)) continue;
                actions.Add(new ActionItem
                {
                    Title = title!,
                    Owner = a.TryGetProperty("owner", out var o) ? o.GetString() : null,
                    Due = a.TryGetProperty("due", out var dv) ? dv.GetString() : null,
                    Context = a.TryGetProperty("context", out var c) ? c.GetString() : null,
                });
            }

        var questions = new List<OpenQuestion>();
        if (root.TryGetProperty("questions", out var qArr) && qArr.ValueKind == JsonValueKind.Array)
            foreach (var q in qArr.EnumerateArray())
            {
                var qtext = q.TryGetProperty("question", out var qv) ? qv.GetString() : null;
                if (string.IsNullOrWhiteSpace(qtext)) continue;
                questions.Add(new OpenQuestion
                {
                    Question = qtext!,
                    Context = q.TryGetProperty("context", out var c) ? c.GetString() : null,
                });
            }

        return new MeetingAnalysis
        {
            Summary = summary,
            Decisions = decisions,
            ActionItems = actions,
            Questions = questions,
        };
    }
}

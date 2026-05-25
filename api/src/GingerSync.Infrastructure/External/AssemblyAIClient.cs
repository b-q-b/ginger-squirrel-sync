using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>
/// AssemblyAI v2 client. Same three-step flow as legacy lib/transcribe.php:
///   1) POST /v2/upload (raw bytes)            → upload_url
///   2) POST /v2/transcript                    → transcript_id (status=queued)
///   3) GET  /v2/transcript/{id}               → poll until completed | error
///
/// speech_models is a non-empty array (universal-3-pro). speaker_labels=true.
/// </summary>
public sealed class AssemblyAIClient : ITranscriber
{
    private readonly HttpClient _http;
    private readonly AssemblyAIOptions _opts;

    private static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web);

    public AssemblyAIClient(HttpClient http, IOptions<AssemblyAIOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.assemblyai.com/v2/");
        _http.Timeout = TimeSpan.FromSeconds(180);
        if (!string.IsNullOrWhiteSpace(_opts.ApiKey))
            _http.DefaultRequestHeaders.Add("Authorization", _opts.ApiKey);
    }

    public bool Configured => !string.IsNullOrWhiteSpace(_opts.ApiKey);

    public async Task<string> UploadAsync(string localPath, CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("ASSEMBLYAI_API_KEY not set");
        if (!File.Exists(localPath)) throw new FileNotFoundException("Audio file not found", localPath);

        await using var fs = File.OpenRead(localPath);
        using var content = new StreamContent(fs);
        content.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");

        using var resp = await _http.PostAsync("upload", content, ct);
        var body = await resp.Content.ReadAsStringAsync(ct);
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"AssemblyAI upload {(int)resp.StatusCode}: {body}");

        using var doc = JsonDocument.Parse(body);
        var url = doc.RootElement.TryGetProperty("upload_url", out var u) ? u.GetString() : null;
        return url ?? throw new InvalidOperationException("AssemblyAI upload: no upload_url in response");
    }

    public async Task<string> CreateJobAsync(string audioUrl, int? speakersExpected, string language, CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("ASSEMBLYAI_API_KEY not set");

        var payload = new Dictionary<string, object?>
        {
            ["audio_url"] = audioUrl,
            ["speech_models"] = new[] { "universal-3-pro" },
            ["speaker_labels"] = true,
            ["language_code"] = string.IsNullOrWhiteSpace(language) ? "en" : language,
        };
        if (speakersExpected is int n && n > 0)
            payload["speakers_expected"] = Math.Clamp(n, 1, 10);

        using var req = new HttpRequestMessage(HttpMethod.Post, "transcript")
        {
            Content = new StringContent(JsonSerializer.Serialize(payload, Json), Encoding.UTF8, "application/json"),
        };
        using var resp = await _http.SendAsync(req, ct);
        var body = await resp.Content.ReadAsStringAsync(ct);
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"AssemblyAI transcript {(int)resp.StatusCode}: {body}");

        using var doc = JsonDocument.Parse(body);
        var id = doc.RootElement.TryGetProperty("id", out var i) ? i.GetString() : null;
        return id ?? throw new InvalidOperationException("AssemblyAI transcript: no id in response");
    }

    public async Task<TranscriptionResult?> GetResultAsync(string transcriptId, CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("ASSEMBLYAI_API_KEY not set");

        using var resp = await _http.GetAsync("transcript/" + Uri.EscapeDataString(transcriptId), ct);
        var body = await resp.Content.ReadAsStringAsync(ct);
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"AssemblyAI poll {(int)resp.StatusCode}: {body}");

        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;
        var status = root.TryGetProperty("status", out var s) ? s.GetString() : null;

        if (status == "queued" || status == "processing") return null;

        if (status == "error")
        {
            var err = root.TryGetProperty("error", out var e) ? e.GetString() : "unknown transcription error";
            throw new InvalidOperationException(err ?? "AssemblyAI: error status without message");
        }

        if (status != "completed")
            throw new InvalidOperationException($"AssemblyAI: unexpected status '{status}'");

        var text = FormatTranscript(root);
        var durationSec = root.TryGetProperty("audio_duration", out var d) && d.ValueKind == JsonValueKind.Number
            ? d.GetDouble() : 0;
        return new TranscriptionResult(text, (int)(durationSec * 1000));
    }

    /// <summary>
    /// Merge consecutive same-speaker utterances and prefix with [Speaker X].
    /// Falls back to flat `text` when no diarization.
    /// </summary>
    private static string FormatTranscript(JsonElement root)
    {
        if (!root.TryGetProperty("utterances", out var utts) || utts.ValueKind != JsonValueKind.Array || utts.GetArrayLength() == 0)
            return root.TryGetProperty("text", out var t) ? (t.GetString() ?? "") : "";

        var blocks = new List<string>();
        string? lastSpeaker = null;
        var buf = new List<string>();

        foreach (var u in utts.EnumerateArray())
        {
            var sp = u.TryGetProperty("speaker", out var sv) ? sv.GetString() : null;
            var txt = u.TryGetProperty("text", out var tv) ? tv.GetString()?.Trim() : null;
            if (string.IsNullOrEmpty(sp) || string.IsNullOrEmpty(txt)) continue;

            if (sp != lastSpeaker && buf.Count > 0)
            {
                blocks.Add($"[Speaker {lastSpeaker}] " + string.Join(' ', buf));
                buf.Clear();
            }
            lastSpeaker = sp;
            buf.Add(txt);
        }
        if (buf.Count > 0) blocks.Add($"[Speaker {lastSpeaker}] " + string.Join(' ', buf));
        return string.Join("\n\n", blocks);
    }
}

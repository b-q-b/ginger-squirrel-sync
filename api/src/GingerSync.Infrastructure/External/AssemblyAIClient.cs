using GingerSync.Core.Entities;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

public sealed class AssemblyAIClient : ITranscriber
{
    private readonly HttpClient _http;
    private readonly AssemblyAIOptions _opts;

    public AssemblyAIClient(HttpClient http, IOptions<AssemblyAIOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.assemblyai.com/v2/");
        if (!string.IsNullOrWhiteSpace(_opts.ApiKey))
            _http.DefaultRequestHeaders.Add("Authorization", _opts.ApiKey);
    }

    public Task<string> UploadAsync(string localPath, CancellationToken ct = default)
        => throw new NotImplementedException("POST /upload with raw audio bytes; returns { upload_url }.");

    public Task<string> CreateJobAsync(string audioUrl, int? speakersExpected, string language, CancellationToken ct = default)
        => throw new NotImplementedException("POST /transcript with speech_models: [\"universal-3-pro\"], speaker_labels: true.");

    public Task<TranscriptionResult?> GetResultAsync(string transcriptId, CancellationToken ct = default)
        => throw new NotImplementedException("GET /transcript/{id}; null while queued|processing.");
}

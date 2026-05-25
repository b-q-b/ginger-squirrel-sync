using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>Trello REST client. Auth via key + token query params on every call.</summary>
public sealed class TrelloClient : ITrelloClient
{
    private readonly HttpClient _http;
    private readonly TrelloOptions _opts;
    private static readonly JsonSerializerOptions JsonOpts = new(JsonSerializerDefaults.Web)
    {
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    public TrelloClient(HttpClient http, IOptions<TrelloOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.trello.com/1/");
        _http.Timeout = TimeSpan.FromSeconds(15);
    }

    public bool Configured => !string.IsNullOrEmpty(_opts.Key) && !string.IsNullOrEmpty(_opts.Token);

    private string Auth => $"key={Uri.EscapeDataString(_opts.Key)}&token={Uri.EscapeDataString(_opts.Token)}";

    public async Task<TrelloMember> GetMeAsync(CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("Trello key/token not configured.");
        using var res = await _http.GetAsync($"members/me?{Auth}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var dto = await res.Content.ReadFromJsonAsync<MemberDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Trello /members/me returned no body.");
        return new TrelloMember(dto.Id ?? "", dto.Username ?? "", dto.FullName ?? "");
    }

    public async Task<IReadOnlyList<TrelloBoard>> GetBoardsAsync(CancellationToken ct = default)
    {
        if (!Configured) throw new InvalidOperationException("Trello key/token not configured.");
        using var res = await _http.GetAsync($"members/me/boards?fields=name,closed,shortUrl&{Auth}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var arr = await res.Content.ReadFromJsonAsync<List<BoardDto>>(JsonOpts, ct).ConfigureAwait(false) ?? [];
        return arr
            .Where(b => !b.Closed)
            .Select(b => new TrelloBoard(b.Id ?? "", b.Name ?? "", b.ShortUrl))
            .ToList();
    }

    public Task<IReadOnlyList<TrelloList>> GetListsAsync(string boardId, CancellationToken ct = default)
        => throw new NotImplementedException("Lands with the mappings picker.");
    public Task<IReadOnlyList<TrelloCard>> GetBoardCardsAsync(string boardId, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<TrelloCard> GetCardAsync(string cardId, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<TrelloCard> CreateCardAsync(TrelloCardWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task<TrelloCard> UpdateCardAsync(string cardId, TrelloCardWrite payload, CancellationToken ct = default)
        => throw new NotImplementedException();
    public Task DeleteCardAsync(string cardId, CancellationToken ct = default)
        => throw new NotImplementedException();

    private sealed record MemberDto(string? Id, string? Username, string? FullName);
    private sealed record BoardDto(string? Id, string? Name, bool Closed, string? ShortUrl);
}

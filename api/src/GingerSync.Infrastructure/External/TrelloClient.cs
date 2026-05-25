using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

public sealed class TrelloClient : ITrelloClient
{
    private readonly HttpClient _http;
    private readonly TrelloOptions _opts;
    private static readonly JsonSerializerOptions JsonOpts = new(JsonSerializerDefaults.Web)
    {
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };

    private const string CardFields = "name,desc,due,dueComplete,idList,idLabels,labels,dateLastActivity,closed,shortUrl";

    public TrelloClient(HttpClient http, IOptions<TrelloOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.trello.com/1/");
        _http.Timeout = TimeSpan.FromSeconds(20);
    }

    public bool Configured => !string.IsNullOrEmpty(_opts.Key) && !string.IsNullOrEmpty(_opts.Token);

    private void EnsureConfigured()
    {
        if (!Configured) throw new InvalidOperationException("Trello key/token not configured.");
    }

    private string Auth() => $"key={Uri.EscapeDataString(_opts.Key)}&token={Uri.EscapeDataString(_opts.Token)}";

    public async Task<TrelloMember> GetMeAsync(CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync($"members/me?{Auth()}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var dto = await res.Content.ReadFromJsonAsync<MemberDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Trello /members/me returned no body.");
        return new TrelloMember(dto.Id ?? "", dto.Username ?? "", dto.FullName ?? "");
    }

    public async Task<IReadOnlyList<TrelloBoard>> GetBoardsAsync(CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync($"members/me/boards?fields=name,closed,shortUrl&{Auth()}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var arr = await res.Content.ReadFromJsonAsync<List<BoardDto>>(JsonOpts, ct).ConfigureAwait(false) ?? [];
        return arr.Where(b => !b.Closed).Select(b => new TrelloBoard(b.Id ?? "", b.Name ?? "", b.ShortUrl)).ToList();
    }

    public async Task<IReadOnlyList<TrelloList>> GetListsAsync(string boardId, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync($"boards/{Uri.EscapeDataString(boardId)}/lists?fields=name,pos,closed&{Auth()}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var arr = await res.Content.ReadFromJsonAsync<List<ListDto>>(JsonOpts, ct).ConfigureAwait(false) ?? [];
        return arr.Where(l => !l.Closed).Select(l => new TrelloList(l.Id ?? "", l.Name ?? "", l.Pos)).ToList();
    }

    public Task<IReadOnlyList<TrelloCard>> GetBoardCardsAsync(string boardId, CancellationToken ct = default)
        => FetchCards($"boards/{Uri.EscapeDataString(boardId)}/cards?fields={CardFields}&{Auth()}", ct);

    public Task<IReadOnlyList<TrelloCard>> GetListCardsAsync(string listId, CancellationToken ct = default)
        => FetchCards($"lists/{Uri.EscapeDataString(listId)}/cards?fields={CardFields}&{Auth()}", ct);

    private async Task<IReadOnlyList<TrelloCard>> FetchCards(string url, CancellationToken ct)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync(url, ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var arr = await res.Content.ReadFromJsonAsync<List<CardDto>>(JsonOpts, ct).ConfigureAwait(false) ?? [];
        return arr.Where(c => !c.Closed).Select(ToDomain).ToList();
    }

    public async Task<TrelloCard> GetCardAsync(string cardId, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.GetAsync($"cards/{Uri.EscapeDataString(cardId)}?fields={CardFields}&{Auth()}", ct).ConfigureAwait(false);
        res.EnsureSuccessStatusCode();
        var dto = await res.Content.ReadFromJsonAsync<CardDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Trello returned no card.");
        return ToDomain(dto);
    }

    public async Task<TrelloCard> CreateCardAsync(TrelloCardWrite payload, CancellationToken ct = default)
    {
        EnsureConfigured();
        var query = new List<string> { Auth() };
        if (!string.IsNullOrEmpty(payload.Name)) query.Add($"name={Uri.EscapeDataString(payload.Name)}");
        if (!string.IsNullOrEmpty(payload.Desc)) query.Add($"desc={Uri.EscapeDataString(payload.Desc)}");
        if (!string.IsNullOrEmpty(payload.Due)) query.Add($"due={Uri.EscapeDataString(payload.Due)}");
        if (!string.IsNullOrEmpty(payload.IdList)) query.Add($"idList={Uri.EscapeDataString(payload.IdList)}");
        if (payload.IdLabels is { Count: > 0 }) query.Add($"idLabels={string.Join(",", payload.IdLabels)}");

        using var res = await _http.PostAsync($"cards?{string.Join("&", query)}", null, ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"Trello create-card failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
        var dto = await res.Content.ReadFromJsonAsync<CardDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Trello returned no card on create.");
        return ToDomain(dto);
    }

    public async Task<TrelloCard> UpdateCardAsync(string cardId, TrelloCardWrite payload, CancellationToken ct = default)
    {
        EnsureConfigured();
        var query = new List<string> { Auth() };
        if (payload.Name is not null) query.Add($"name={Uri.EscapeDataString(payload.Name)}");
        if (payload.Desc is not null) query.Add($"desc={Uri.EscapeDataString(payload.Desc)}");
        if (payload.Due is not null) query.Add($"due={Uri.EscapeDataString(payload.Due)}");
        if (payload.IdList is not null) query.Add($"idList={Uri.EscapeDataString(payload.IdList)}");
        if (payload.IdLabels is not null) query.Add($"idLabels={string.Join(",", payload.IdLabels)}");

        using var req = new HttpRequestMessage(HttpMethod.Put, $"cards/{Uri.EscapeDataString(cardId)}?{string.Join("&", query)}");
        using var res = await _http.SendAsync(req, ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"Trello update-card failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
        var dto = await res.Content.ReadFromJsonAsync<CardDto>(JsonOpts, ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Trello returned no card on update.");
        return ToDomain(dto);
    }

    public async Task DeleteCardAsync(string cardId, CancellationToken ct = default)
    {
        EnsureConfigured();
        using var res = await _http.DeleteAsync($"cards/{Uri.EscapeDataString(cardId)}?{Auth()}", ct).ConfigureAwait(false);
        if (!res.IsSuccessStatusCode)
        {
            var body = await res.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            throw new HttpRequestException($"Trello delete-card failed: {(int)res.StatusCode} {body}", null, res.StatusCode);
        }
    }

    private static TrelloCard ToDomain(CardDto c)
    {
        return new TrelloCard(
            Id: c.Id ?? "",
            Name: c.Name ?? "",
            Desc: c.Desc,
            Due: c.Due,
            IdList: c.IdList ?? "",
            IdLabels: c.IdLabels ?? [],
            Labels: (c.Labels ?? []).Select(l => new TrelloLabel(l.Id ?? "", l.Name ?? "", l.Color)).ToList(),
            DateLastActivity: c.DateLastActivity,
            ShortUrl: c.ShortUrl
        );
    }

    private sealed record MemberDto(string? Id, string? Username, string? FullName);
    private sealed record BoardDto(string? Id, string? Name, bool Closed, string? ShortUrl);
    private sealed record ListDto(string? Id, string? Name, double Pos, bool Closed);

    private sealed class CardDto
    {
        public string? Id { get; set; }
        public string? Name { get; set; }
        public string? Desc { get; set; }
        public string? Due { get; set; }
        public string? IdList { get; set; }
        public List<string>? IdLabels { get; set; }
        public List<LabelDto>? Labels { get; set; }
        public DateTimeOffset? DateLastActivity { get; set; }
        public bool Closed { get; set; }
        public string? ShortUrl { get; set; }
    }

    private sealed record LabelDto(string? Id, string? Name, string? Color);
}

using GingerSync.Core.Services;
using Microsoft.Extensions.Options;

namespace GingerSync.Infrastructure.External;

/// <summary>Trello REST client (auth via key + token query params).</summary>
public sealed class TrelloClient : ITrelloClient
{
    private readonly HttpClient _http;
    private readonly TrelloOptions _opts;

    public TrelloClient(HttpClient http, IOptions<TrelloOptions> opts)
    {
        _http = http;
        _opts = opts.Value;
        _http.BaseAddress = new Uri("https://api.trello.com/1/");
        _http.Timeout = TimeSpan.FromSeconds(15);
    }

    internal string Auth() => $"key={_opts.Key}&token={_opts.Token}";

    public Task<TrelloMember> GetMeAsync(CancellationToken ct = default) => throw new NotImplementedException();
    public Task<IReadOnlyList<TrelloBoard>> GetBoardsAsync(CancellationToken ct = default) => throw new NotImplementedException();
    public Task<IReadOnlyList<TrelloList>> GetListsAsync(string boardId, CancellationToken ct = default) => throw new NotImplementedException();
    public Task<IReadOnlyList<TrelloCard>> GetBoardCardsAsync(string boardId, CancellationToken ct = default) => throw new NotImplementedException();
    public Task<TrelloCard> GetCardAsync(string cardId, CancellationToken ct = default) => throw new NotImplementedException();
    public Task<TrelloCard> CreateCardAsync(TrelloCardWrite payload, CancellationToken ct = default) => throw new NotImplementedException();
    public Task<TrelloCard> UpdateCardAsync(string cardId, TrelloCardWrite payload, CancellationToken ct = default) => throw new NotImplementedException();
    public Task DeleteCardAsync(string cardId, CancellationToken ct = default) => throw new NotImplementedException();
}

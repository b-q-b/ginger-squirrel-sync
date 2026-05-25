namespace GingerSync.Core.Services;

public interface IAuthService
{
    /// <summary>Verify password against stored hash, issue a JWT on success.</summary>
    Task<AuthResult> LoginAsync(string password, CancellationToken ct = default);
}

public sealed record AuthResult(bool Ok, string? Token, DateTimeOffset? ExpiresAt, string? Error);

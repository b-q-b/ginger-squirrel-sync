using System.Security.Claims;
using System.Text;
using GingerSync.Core.Services;
using GingerSync.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.JsonWebTokens;
using Microsoft.IdentityModel.Tokens;

namespace GingerSync.Infrastructure.Auth;

public sealed class JwtAuthService : IAuthService
{
    private readonly GingerSyncDbContext _db;
    private readonly AuthOptions _opts;
    private readonly ILogger<JwtAuthService> _log;
    private readonly JsonWebTokenHandler _jwt = new();

    public JwtAuthService(GingerSyncDbContext db, IOptions<AuthOptions> opts, ILogger<JwtAuthService> log)
    {
        _db = db;
        _opts = opts.Value;
        _log = log;
    }

    public async Task<AuthResult> LoginAsync(string password, CancellationToken ct = default)
    {
        if (string.IsNullOrEmpty(password))
            return new AuthResult(false, null, null, "password required");
        if (string.IsNullOrEmpty(_opts.JwtSecret) || _opts.JwtSecret.Length < 32)
            return new AuthResult(false, null, null, "auth misconfigured (JwtSecret too short)");

        // v1 stored the bcrypt hash as a JSON-encoded string (jsonb column).
        // It comes back like  "$2y$12$...."  with literal surrounding quotes.
        var rawValue = await _db.Settings
            .Where(s => s.Key == "dashboard_password_hash")
            .Select(s => s.Value)
            .FirstOrDefaultAsync(ct);
        if (string.IsNullOrEmpty(rawValue))
        {
            _log.LogWarning("No dashboard_password_hash in settings table.");
            return new AuthResult(false, null, null, "no password configured");
        }

        var hash = rawValue.Trim().Trim('"');
        bool ok;
        try { ok = BCrypt.Net.BCrypt.Verify(password, hash); }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "Bcrypt verify threw — likely malformed hash in settings.");
            return new AuthResult(false, null, null, "stored hash invalid");
        }
        if (!ok)
        {
            // Defeat user-enumeration timing — sleep a touch on failure.
            await Task.Delay(300, ct);
            return new AuthResult(false, null, null, "incorrect password");
        }

        var expires = DateTime.UtcNow.AddHours(Math.Max(1, _opts.TokenLifetimeHours));
        var token = _jwt.CreateToken(new SecurityTokenDescriptor
        {
            Subject = new ClaimsIdentity([
                new Claim(JwtRegisteredClaimNames.Sub, "admin"),
                new Claim(JwtRegisteredClaimNames.Jti, Guid.NewGuid().ToString("N")),
            ]),
            Expires = expires,
            Issuer = _opts.Issuer,
            Audience = _opts.Audience,
            SigningCredentials = new SigningCredentials(
                new SymmetricSecurityKey(Encoding.UTF8.GetBytes(_opts.JwtSecret)),
                SecurityAlgorithms.HmacSha256),
        });

        return new AuthResult(true, token, expires, null);
    }
}

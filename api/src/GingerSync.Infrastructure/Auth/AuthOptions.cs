namespace GingerSync.Infrastructure.Auth;

public sealed class AuthOptions
{
    /// <summary>Symmetric signing secret for JWT (min 32 chars).</summary>
    public string JwtSecret { get; set; } = "";
    public int TokenLifetimeHours { get; set; } = 8;
    public string Issuer { get; set; } = "ginger-sync";
    public string Audience { get; set; } = "ginger-sync-web";
    public string CookieName { get; set; } = "gss_auth";
}

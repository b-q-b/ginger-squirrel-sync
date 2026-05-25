# ── Build stage ──────────────────────────────────────────
FROM mcr.microsoft.com/dotnet/sdk:9.0-alpine AS build
WORKDIR /src

# Copy only what's needed for `restore` first, to maximize Docker layer cache
COPY GingerSync.sln global.json Directory.Build.props ./
COPY src/GingerSync.Core/GingerSync.Core.csproj           src/GingerSync.Core/
COPY src/GingerSync.Infrastructure/GingerSync.Infrastructure.csproj src/GingerSync.Infrastructure/
COPY src/GingerSync.Api/GingerSync.Api.csproj             src/GingerSync.Api/
RUN dotnet restore

# Now copy the rest of the source
COPY src/ src/
RUN dotnet publish src/GingerSync.Api/GingerSync.Api.csproj \
    -c Release \
    -o /app/publish \
    --no-restore \
    /p:UseAppHost=false

# ── Runtime stage ────────────────────────────────────────
FROM mcr.microsoft.com/dotnet/aspnet:9.0-alpine AS runtime
WORKDIR /app

# The `app` non-root user (UID 1654) is pre-created in the aspnet:9-alpine base
# image (since .NET 8), so just switch to it.
USER app

COPY --from=build --chown=app:app /app/publish ./

ENV ASPNETCORE_URLS=http://+:8080 \
    ASPNETCORE_ENVIRONMENT=Production \
    DOTNET_RUNNING_IN_CONTAINER=true \
    DOTNET_NOLOGO=1

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -q --spider http://localhost:8080/health || exit 1

ENTRYPOINT ["dotnet", "GingerSync.Api.dll"]

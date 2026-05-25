using GingerSync.Core.Entities;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Storage.ValueConversion;

namespace GingerSync.Infrastructure.Persistence;

/// <summary>
/// EF Core context targeting the existing v1 PHP schema verbatim — same column
/// names, same lowercase-snake_case enum values. This lets `pg_dump` from the
/// v1 Supabase project restore byte-for-byte into the v2 project, and the
/// .NET API + the PHP app can read each other's writes during cut-over.
/// </summary>
public sealed class GingerSyncDbContext : DbContext
{
    public GingerSyncDbContext(DbContextOptions<GingerSyncDbContext> options) : base(options) { }

    public DbSet<Mapping> Mappings => Set<Mapping>();
    public DbSet<SyncMap> SyncMaps => Set<SyncMap>();
    public DbSet<SyncEvent> SyncEvents => Set<SyncEvent>();
    public DbSet<HotPlateItem> HotPlateItems => Set<HotPlateItem>();
    public DbSet<HotPlateCategory> HotPlateCategories => Set<HotPlateCategory>();
    public DbSet<Meeting> Meetings => Set<Meeting>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        // All tables live in the v1 `ginger_sync` Postgres schema.
        modelBuilder.HasDefaultSchema("ginger_sync");

        // ── Enum converters: v1-style lowercase strings ───────────────────
        // NOTE: ValueConverter takes Expression<Func<>>, so the body must use
        // only expression-tree-safe constructs (no switch / no ?. / no out-var).
        var syncDirectionConv = new ValueConverter<SyncDirection?, string?>(
            v => v == null ? null
               : v == SyncDirection.TrelloToClickUp ? "trello_to_clickup"
               : "clickup_to_trello",
            v => v == "trello_to_clickup" ? (SyncDirection?)SyncDirection.TrelloToClickUp
               : v == "clickup_to_trello" ? (SyncDirection?)SyncDirection.ClickUpToTrello
               : null);

        var hotPlateColumnConv = new ValueConverter<HotPlateColumn, string>(
            v => v == HotPlateColumn.InProgress ? "in_progress"
               : v == HotPlateColumn.Waiting   ? "waiting"
               : v == HotPlateColumn.Done       ? "done"
               : "todo",
            v => v == "in_progress" ? HotPlateColumn.InProgress
               : v == "waiting"     ? HotPlateColumn.Waiting
               : v == "done"        ? HotPlateColumn.Done
               : HotPlateColumn.Todo);

        var energyLevelConv = new ValueConverter<EnergyLevel?, string?>(
            v => v == EnergyLevel.Quick    ? "quick"
               : v == EnergyLevel.Social   ? "social"
               : v == EnergyLevel.Deep     ? "deep"
               : v == EnergyLevel.Creative ? "creative"
               : null,
            v => v == "quick"    ? (EnergyLevel?)EnergyLevel.Quick
               : v == "social"   ? (EnergyLevel?)EnergyLevel.Social
               : v == "deep"     ? (EnergyLevel?)EnergyLevel.Deep
               : v == "creative" ? (EnergyLevel?)EnergyLevel.Creative
               : null);

        var meetingStatusConv = new ValueConverter<MeetingStatus, string>(
            v => v == MeetingStatus.Transcribing ? "transcribing"
               : v == MeetingStatus.Analyzing   ? "analyzing"
               : v == MeetingStatus.Ready       ? "ready"
               : v == MeetingStatus.Error       ? "error"
               : v == MeetingStatus.AudioOnly   ? "audio_only"
               : "uploaded",
            v => v == "transcribing" ? MeetingStatus.Transcribing
               : v == "analyzing"   ? MeetingStatus.Analyzing
               : v == "ready"       ? MeetingStatus.Ready
               : v == "error"       ? MeetingStatus.Error
               : v == "audio_only"  ? MeetingStatus.AudioOnly
               : MeetingStatus.Uploaded);

        // ── Tables ─────────────────────────────────────────────────────────
        // ── JSON converter for Dictionary<string,string> stored as jsonb ───
        var statusMapConv = new ValueConverter<Dictionary<string, string>, string>(
            v => System.Text.Json.JsonSerializer.Serialize(v ?? new(), (System.Text.Json.JsonSerializerOptions?)null),
            v => string.IsNullOrEmpty(v)
                ? new Dictionary<string, string>()
                : System.Text.Json.JsonSerializer.Deserialize<Dictionary<string, string>>(v, (System.Text.Json.JsonSerializerOptions?)null) ?? new());

        modelBuilder.Entity<Mapping>(e =>
        {
            e.ToTable("mappings");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.Label).HasColumnName("label");
            e.Property(x => x.TrelloBoardId).HasColumnName("trello_board_id");
            e.Property(x => x.TrelloListId).HasColumnName("trello_list_id");
            e.Property(x => x.ClickUpSpaceId).HasColumnName("clickup_space_id");
            e.Property(x => x.ClickUpListId).HasColumnName("clickup_list_id");
            e.Property(x => x.StatusMap).HasColumnName("status_map").HasColumnType("jsonb").HasConversion(statusMapConv);
            e.Property(x => x.IsActive).HasColumnName("is_active");
            e.Property(x => x.CreatedAt).HasColumnName("created_at");
        });

        modelBuilder.Entity<SyncMap>(e =>
        {
            e.ToTable("sync_map");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.MappingId).HasColumnName("mapping_id");
            e.Property(x => x.TrelloCardId).HasColumnName("trello_card_id");
            e.Property(x => x.ClickUpTaskId).HasColumnName("clickup_task_id");
            e.Property(x => x.LastHash).HasColumnName("last_hash");
            e.Property(x => x.LastDirection).HasColumnName("last_direction").HasConversion(syncDirectionConv);
            e.Property(x => x.LastSyncedAt).HasColumnName("last_synced_at");
            e.Property(x => x.DeletedAt).HasColumnName("deleted_at");
            e.HasIndex(x => x.TrelloCardId);
            e.HasIndex(x => x.ClickUpTaskId);
        });

        modelBuilder.Entity<SyncEvent>(e =>
        {
            e.ToTable("sync_events");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.CreatedAt).HasColumnName("created_at");
            e.Property(x => x.Source).HasColumnName("source");
            e.Property(x => x.Direction).HasColumnName("direction").HasConversion(syncDirectionConv);
            e.Property(x => x.Action).HasColumnName("action");
            e.Property(x => x.TrelloCardId).HasColumnName("trello_card_id");
            e.Property(x => x.ClickUpTaskId).HasColumnName("clickup_task_id");
            e.Property(x => x.MappingId).HasColumnName("mapping_id");
            e.Property(x => x.Status).HasColumnName("status");
            e.Property(x => x.Error).HasColumnName("error");
            e.Property(x => x.PayloadHash).HasColumnName("payload_hash");
            e.HasIndex(x => x.CreatedAt).IsDescending();
        });

        modelBuilder.Entity<HotPlateCategory>(e =>
        {
            e.ToTable("hot_plate_categories");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.Name).HasColumnName("name");
            e.Property(x => x.Color).HasColumnName("color");
            e.Property(x => x.SortOrder).HasColumnName("sort_order");
            e.Property(x => x.CreatedAt).HasColumnName("created_at");
        });

        modelBuilder.Entity<HotPlateItem>(e =>
        {
            e.ToTable("hot_plate_items");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.Title).HasColumnName("title");
            e.Property(x => x.Description).HasColumnName("description");
            // v1 uses `column_key` (not `column` which is a SQL reserved word)
            e.Property(x => x.Column).HasColumnName("column_key").HasConversion(hotPlateColumnConv);
            e.Property(x => x.Priority).HasColumnName("priority").HasConversion<int>();
            e.Property(x => x.DueDate).HasColumnName("due_date");
            e.Property(x => x.Position).HasColumnName("position");
            e.Property(x => x.CategoryId).HasColumnName("category_id");
            e.Property(x => x.EnergyLevel).HasColumnName("energy_level").HasConversion(energyLevelConv);
            e.Property(x => x.CreatedAt).HasColumnName("created_at");
            e.Property(x => x.UpdatedAt).HasColumnName("updated_at");
            e.Property(x => x.DeletedAt).HasColumnName("deleted_at");
        });

        modelBuilder.Entity<Meeting>(e =>
        {
            e.ToTable("meetings");
            e.HasKey(x => x.Id);
            e.Property(x => x.Id).HasColumnName("id");
            e.Property(x => x.Title).HasColumnName("title");
            e.Property(x => x.RecordedAt).HasColumnName("recorded_at");
            e.Property(x => x.DurationMs).HasColumnName("duration_ms");
            e.Property(x => x.Language).HasColumnName("language");
            e.Property(x => x.Status).HasColumnName("status").HasConversion(meetingStatusConv);
            e.Property(x => x.ErrorMessage).HasColumnName("error_message");
            e.Property(x => x.AudioPath).HasColumnName("audio_path");
            e.Property(x => x.AudioMime).HasColumnName("audio_mime");
            e.Property(x => x.AudioSizeBytes).HasColumnName("audio_size_bytes");
            e.Property(x => x.AudioExtension).HasColumnName("audio_extension");
            e.Property(x => x.SpeakersExpected).HasColumnName("speakers_expected");
            e.Property(x => x.Transcript).HasColumnName("transcript");
            e.Property(x => x.AssemblyAITranscriptId).HasColumnName("assemblyai_transcript_id");
            e.Property(x => x.Analysis).HasColumnName("analysis").HasColumnType("jsonb");
            e.Property(x => x.HotPlateItemId).HasColumnName("hot_plate_item_id");
            e.Property(x => x.CreatedAt).HasColumnName("created_at");
            e.Property(x => x.UpdatedAt).HasColumnName("updated_at");
            e.Property(x => x.DeletedAt).HasColumnName("deleted_at");
        });
    }
}

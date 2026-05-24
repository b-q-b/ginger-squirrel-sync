using GingerSync.Core.Entities;
using Microsoft.EntityFrameworkCore;

namespace GingerSync.Infrastructure.Persistence;

public sealed class GingerSyncDbContext : DbContext
{
    public GingerSyncDbContext(DbContextOptions<GingerSyncDbContext> options) : base(options) { }

    public DbSet<Mapping> Mappings => Set<Mapping>();
    public DbSet<SyncMap> SyncMaps => Set<SyncMap>();
    public DbSet<SyncEvent> SyncEvents => Set<SyncEvent>();
    public DbSet<HotPlateItem> HotPlateItems => Set<HotPlateItem>();
    public DbSet<HotPlateCategory> HotPlateCategories => Set<HotPlateCategory>();
    public DbSet<Meeting> Meetings => Set<Meeting>();

    protected override void OnModelCreating(ModelBuilder b)
    {
        // All tables live in the `ginger_sync` Postgres schema.
        b.HasDefaultSchema("ginger_sync");

        b.Entity<Mapping>(e =>
        {
            e.ToTable("mappings");
            e.HasKey(x => x.Id);
            e.Property(x => x.StatusMap).HasColumnType("jsonb");
        });

        b.Entity<SyncMap>(e =>
        {
            e.ToTable("sync_map");
            e.HasKey(x => x.Id);
            e.HasIndex(x => x.TrelloCardId).IsUnique();
            e.HasIndex(x => x.ClickUpTaskId).IsUnique();
            e.Property(x => x.LastDirection).HasConversion<string>();
        });

        b.Entity<SyncEvent>(e =>
        {
            e.ToTable("sync_events");
            e.HasKey(x => x.Id);
            e.Property(x => x.Direction).HasConversion<string>();
            e.HasIndex(x => x.CreatedAt).IsDescending();
        });

        b.Entity<HotPlateCategory>(e =>
        {
            e.ToTable("hot_plate_categories");
            e.HasKey(x => x.Id);
        });

        b.Entity<HotPlateItem>(e =>
        {
            e.ToTable("hot_plate_items");
            e.HasKey(x => x.Id);
            e.Property(x => x.Column).HasConversion<string>();
            e.Property(x => x.Priority).HasConversion<int>();
            e.Property(x => x.EnergyLevel).HasConversion<string?>();
        });

        b.Entity<Meeting>(e =>
        {
            e.ToTable("meetings");
            e.HasKey(x => x.Id);
            e.Property(x => x.Status).HasConversion<string>();
            e.Property(x => x.Analysis).HasColumnType("jsonb");
        });
    }
}

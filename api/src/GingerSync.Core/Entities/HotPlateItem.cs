namespace GingerSync.Core.Entities;

/// <summary>Kanban-style personal task.</summary>
public sealed class HotPlateItem
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public required string Title { get; set; }
    public string? Description { get; set; }
    public HotPlateColumn Column { get; set; } = HotPlateColumn.Todo;
    public Priority Priority { get; set; } = Priority.Medium;
    public DateOnly? DueDate { get; set; }
    public int Position { get; set; }
    public Guid? CategoryId { get; set; }
    public EnergyLevel? EnergyLevel { get; set; }
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? DeletedAt { get; set; }
}

public enum HotPlateColumn { Todo, InProgress, Waiting, Done }
public enum Priority { Low = 1, Medium = 2, High = 3, Critical = 4 }
public enum EnergyLevel { Quick, Social, Deep, Creative }

public sealed class HotPlateCategory
{
    public Guid Id { get; init; } = Guid.NewGuid();
    public required string Name { get; set; }
    public string Color { get; set; } = "blue";
    public int SortOrder { get; set; }
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
}

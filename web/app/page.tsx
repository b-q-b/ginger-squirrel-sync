import Link from "next/link";

export default function HomePage() {
  return (
    <main className="mx-auto max-w-4xl p-8">
      <h1 className="text-3xl font-bold mb-4">Ginger Sync</h1>
      <p className="text-(--color-muted) mb-6">ClickUp ↔ Trello two-way sync, Hot Plate Kanban, and meeting transcription.</p>

      <nav className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        {[
          { href: "/hot-plate", label: "Hot Plate" },
          { href: "/meetings", label: "Meetings" },
          { href: "/mappings", label: "Mappings" },
          { href: "/items", label: "Items" },
          { href: "/logs", label: "Logs" },
          { href: "/settings", label: "Settings" },
        ].map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className="rounded-xl border border-(--color-border) bg-(--color-surface) px-4 py-3 text-sm font-medium hover:border-(--color-accent) hover:text-(--color-accent) transition-colors"
          >
            {item.label} →
          </Link>
        ))}
      </nav>
    </main>
  );
}

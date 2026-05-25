"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "@/lib/api";
import type { Mapping } from "@/lib/types";

export default function MappingsPage() {
  const q = useQuery({
    queryKey: ["mappings"],
    queryFn: () => apiGet<Mapping[]>("/api/mappings"),
  });

  return (
    <main className="mx-auto max-w-5xl p-8">
      <div className="flex items-center justify-between mb-6">
        <div>
          <Link href="/" className="text-sm text-(--color-muted) hover:text-(--color-accent)">← home</Link>
          <h1 className="text-2xl font-bold mt-1">Mappings</h1>
          <p className="text-sm text-(--color-muted) mt-1">Trello boards paired with ClickUp lists. Each pair runs through the sync engine.</p>
        </div>
      </div>

      {q.isLoading && <div className="text-(--color-muted)">Loading…</div>}
      {q.isError && (
        <div className="rounded-lg border border-(--color-danger) bg-red-50 px-4 py-3 text-sm text-(--color-danger)">
          Failed to load mappings: {String((q.error as Error).message)}
        </div>
      )}

      {q.data && q.data.length === 0 && (
        <div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-8 text-center text-(--color-muted)">
          No mappings yet.
        </div>
      )}

      {q.data && q.data.length > 0 && (
        <div className="rounded-xl border border-(--color-border) bg-(--color-surface) overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-(--color-border) text-xs uppercase tracking-wider text-(--color-muted)">
                <th className="px-4 py-3 text-left">Label</th>
                <th className="px-4 py-3 text-left">Trello</th>
                <th className="px-4 py-3 text-left">ClickUp</th>
                <th className="px-4 py-3 text-left">Status map</th>
                <th className="px-4 py-3 text-left">Active</th>
              </tr>
            </thead>
            <tbody>
              {q.data.map((m) => (
                <tr key={m.id} className="border-t border-(--color-border)">
                  <td className="px-4 py-3 font-medium">{m.label}</td>
                  <td className="px-4 py-3 font-mono text-xs text-(--color-muted)">
                    {m.trelloBoardId.slice(0, 8)}…{m.trelloListId ? ` / list` : " / whole board"}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-(--color-muted)">{m.clickUpListId.slice(0, 12)}…</td>
                  <td className="px-4 py-3 text-xs text-(--color-muted)">
                    {Object.keys(m.statusMap || {}).length === 0
                      ? "—"
                      : Object.entries(m.statusMap).map(([k, v]) => `${k}→${v}`).join(" · ")}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-medium ${
                        m.isActive ? "bg-emerald-50 text-emerald-700" : "bg-gray-100 text-gray-500"
                      }`}
                    >
                      {m.isActive ? "active" : "paused"}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </main>
  );
}

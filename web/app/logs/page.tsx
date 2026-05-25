"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "@/lib/api";
import type { SyncEvent } from "@/lib/types";

interface LogsPage {
  total: number;
  page: number;
  perPage: number;
  rows: SyncEvent[];
}

export default function LogsPage() {
  const [page, setPage] = useState(1);
  const [source, setSource] = useState<string>("");
  const [status, setStatus] = useState<string>("");

  const params = new URLSearchParams();
  params.set("page", String(page));
  params.set("perPage", "50");
  if (source) params.set("source", source);
  if (status) params.set("status", status);

  const q = useQuery({
    queryKey: ["sync-events", page, source, status],
    queryFn: () => apiGet<LogsPage>(`/api/sync/events?${params.toString()}`),
    refetchInterval: 15_000,
  });

  const totalPages = q.data ? Math.max(1, Math.ceil(q.data.total / q.data.perPage)) : 1;

  return (
    <main className="mx-auto max-w-6xl p-8">
      <div className="mb-6">
        <Link href="/" className="text-sm text-(--color-muted) hover:text-(--color-accent)">← home</Link>
        <h1 className="text-2xl font-bold mt-1">Sync logs</h1>
        <p className="text-sm text-(--color-muted) mt-1">
          {q.data ? `${q.data.total.toLocaleString()} events total` : "Loading…"}
        </p>
      </div>

      <div className="flex gap-2 mb-4 items-center bg-(--color-surface) border border-(--color-border) rounded-xl px-4 py-3">
        <label className="text-xs uppercase tracking-wider text-(--color-muted)">Source</label>
        <select
          className="text-sm px-2 py-1 border border-(--color-border) rounded bg-(--color-bg)"
          value={source}
          onChange={(e) => { setSource(e.target.value); setPage(1); }}
        >
          <option value="">all</option>
          <option value="reconcile_cron">reconcile_cron</option>
          <option value="manual">manual</option>
          <option value="trello_webhook">trello_webhook</option>
          <option value="clickup_webhook">clickup_webhook</option>
        </select>
        <label className="text-xs uppercase tracking-wider text-(--color-muted) ml-2">Status</label>
        <select
          className="text-sm px-2 py-1 border border-(--color-border) rounded bg-(--color-bg)"
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
        >
          <option value="">all</option>
          <option value="ok">ok</option>
          <option value="error">error</option>
          <option value="skipped">skipped</option>
        </select>
      </div>

      <div className="rounded-xl border border-(--color-border) bg-(--color-surface) overflow-hidden">
        <table className="w-full text-xs">
          <thead className="text-[10px] uppercase tracking-wider text-(--color-muted) border-b border-(--color-border)">
            <tr>
              <th className="px-3 py-2 text-left">When</th>
              <th className="px-3 py-2 text-left">Source</th>
              <th className="px-3 py-2 text-left">Action</th>
              <th className="px-3 py-2 text-left">Direction</th>
              <th className="px-3 py-2 text-left">Trello</th>
              <th className="px-3 py-2 text-left">ClickUp</th>
              <th className="px-3 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody>
            {q.data?.rows.map((e) => (
              <tr key={e.id} className="border-t border-(--color-border)">
                <td className="px-3 py-2 font-mono text-(--color-muted)">{new Date(e.createdAt).toLocaleString()}</td>
                <td className="px-3 py-2">{e.source}</td>
                <td className="px-3 py-2"><span className={pillClass(e.action)}>{e.action}</span></td>
                <td className="px-3 py-2 font-mono text-(--color-muted)">
                  {e.direction === "TrelloToClickUp" ? "T→CU" : e.direction === "ClickUpToTrello" ? "CU→T" : "—"}
                </td>
                <td className="px-3 py-2 font-mono text-(--color-muted)">{e.trelloCardId?.slice(0, 10) ?? "—"}{e.trelloCardId ? "…" : ""}</td>
                <td className="px-3 py-2 font-mono text-(--color-muted)">{e.clickUpTaskId?.slice(0, 10) ?? "—"}{e.clickUpTaskId ? "…" : ""}</td>
                <td className="px-3 py-2">
                  <span className={statusClass(e.status)}>{e.status}</span>
                  {e.error && <div className="text-(--color-danger) text-[10px] mt-0.5">{e.error.slice(0, 80)}</div>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex justify-between items-center mt-4 text-sm">
        <span className="text-(--color-muted)">Page {page} of {totalPages}</span>
        <div className="flex gap-2">
          <button
            onClick={() => setPage(p => Math.max(1, p - 1))}
            disabled={page <= 1}
            className="px-3 py-1 border border-(--color-border) rounded bg-(--color-surface) disabled:opacity-40 hover:border-(--color-accent)"
          >
            ← Prev
          </button>
          <button
            onClick={() => setPage(p => Math.min(totalPages, p + 1))}
            disabled={page >= totalPages}
            className="px-3 py-1 border border-(--color-border) rounded bg-(--color-surface) disabled:opacity-40 hover:border-(--color-accent)"
          >
            Next →
          </button>
        </div>
      </div>
    </main>
  );
}

function pillClass(action: string): string {
  const base = "inline-block rounded-full px-2 py-0.5 text-[10px] font-medium";
  if (action === "create") return `${base} bg-emerald-50 text-emerald-700`;
  if (action === "update") return `${base} bg-blue-50 text-blue-700`;
  if (action === "delete") return `${base} bg-red-50 text-red-700`;
  if (action.startsWith("skip")) return `${base} bg-gray-100 text-gray-500`;
  return `${base} bg-gray-100 text-gray-600`;
}

function statusClass(s: string): string {
  const base = "inline-block";
  if (s === "ok") return `${base} text-(--color-ok)`;
  if (s === "error") return `${base} text-(--color-danger)`;
  return `${base} text-(--color-muted)`;
}

"use client";

import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiSend } from "@/lib/api";
import { useAuthGuard } from "@/lib/auth";

interface WebhookRow {
  id: string;
  platform: "trello" | "clickup" | string;
  externalId: string;
  targetId: string;
  status: string;
  lastCheckedAt: string | null;
  createdAt: string;
}

interface RegisterResult {
  ok: boolean;
  results: Array<{
    platform: string;
    target_id?: string;
    status: string;
    webhook_id?: string;
    error?: string;
  }>;
}

export default function WebhooksPage() {
  const auth = useAuthGuard();
  const qc = useQueryClient();

  const list = useQuery({
    queryKey: ["webhooks"],
    queryFn: () => apiGet<WebhookRow[]>("/api/webhooks/"),
    enabled: auth.authenticated,
  });

  const register = useMutation({
    mutationFn: () => apiSend<RegisterResult>("POST", "/api/webhooks/register"),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["webhooks"] }),
  });

  const remove = useMutation({
    mutationFn: (id: string) => apiSend<void>("DELETE", `/api/webhooks/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["webhooks"] }),
  });

  if (auth.loading) return <main className="p-8 text-(--color-muted)">Loading…</main>;
  if (!auth.authenticated) return null;

  return (
    <main className="mx-auto max-w-4xl p-8">
      <header className="mb-6">
        <Link href="/" className="text-xs text-(--color-muted) hover:text-(--color-accent)">← Dashboard</Link>
        <h1 className="text-3xl font-bold mt-1">Webhooks</h1>
        <p className="text-(--color-muted) text-sm mt-1">
          Register live event delivery from Trello + ClickUp. Falls back to the 5-min reconcile cron when missing.
        </p>
      </header>

      <section className="mb-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-4 flex items-center justify-between">
        <div className="text-sm">
          <div className="font-semibold">Register webhooks</div>
          <div className="text-xs text-(--color-muted) mt-1">
            Creates one webhook per Trello board + one per ClickUp workspace, for all <em>active</em> mappings.
            Idempotent — re-running skips ones already attached.
          </div>
        </div>
        <button
          onClick={() => register.mutate()}
          disabled={register.isPending}
          className="text-sm px-3 py-1.5 rounded border border-(--color-accent) text-(--color-accent) hover:bg-(--color-accent) hover:text-(--color-bg) disabled:opacity-50"
        >
          {register.isPending ? "Registering…" : "Register now"}
        </button>
      </section>

      {register.data && (
        <section className="mb-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-4">
          <div className="text-sm font-semibold mb-2">Last run</div>
          <ul className="text-xs space-y-1">
            {register.data.results.map((r, i) => (
              <li key={i} className={r.status === "error" ? "text-(--color-danger)" : "text-(--color-muted)"}>
                <span className="font-mono">[{r.platform}{r.target_id ? ` ${r.target_id}` : ""}]</span> {r.status}
                {r.webhook_id ? ` · id=${r.webhook_id}` : ""}
                {r.error ? ` — ${r.error}` : ""}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <div className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-3">Active</div>
        {list.isLoading && <div className="text-(--color-muted) text-sm">Loading…</div>}
        {list.data && list.data.length === 0 && (
          <div className="text-(--color-muted) text-sm">No webhooks registered yet.</div>
        )}
        <ul className="grid grid-cols-1 gap-2">
          {list.data?.map((w) => (
            <li
              key={w.id}
              className="rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-3 flex items-center justify-between gap-3"
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2 text-sm">
                  <span className="uppercase text-[10px] tracking-wider px-2 py-0.5 rounded border border-(--color-border) text-(--color-muted)">
                    {w.platform}
                  </span>
                  <span className="font-mono text-xs truncate">{w.targetId}</span>
                </div>
                <div className="text-xs text-(--color-muted) mt-1">
                  id={w.externalId || "—"} · status={w.status}
                  {w.lastCheckedAt ? ` · checked ${new Date(w.lastCheckedAt).toLocaleString()}` : ""}
                </div>
              </div>
              <button
                onClick={() => {
                  if (confirm(`Delete the ${w.platform} webhook on ${w.targetId}?`)) remove.mutate(w.id);
                }}
                className="text-xs px-2 py-1 rounded border border-(--color-border) hover:border-(--color-danger) hover:text-(--color-danger)"
              >
                Delete
              </button>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}

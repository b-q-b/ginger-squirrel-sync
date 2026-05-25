"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "@/lib/api";
import { useAuthGuard, logout } from "@/lib/auth";
import { useRouter } from "next/navigation";
import type { SyncStats, IntegrationResult } from "@/lib/types";

function relTime(iso: string | null): string {
  if (!iso) return "never";
  const ms = Date.now() - new Date(iso).getTime();
  if (ms < 60_000) return `${Math.round(ms / 1000)}s ago`;
  if (ms < 3_600_000) return `${Math.round(ms / 60_000)}m ago`;
  if (ms < 86_400_000) return `${Math.round(ms / 3_600_000)}h ago`;
  return `${Math.round(ms / 86_400_000)}d ago`;
}

export default function HomePage() {
  const router = useRouter();
  const auth = useAuthGuard();

  const stats = useQuery({
    queryKey: ["sync-stats"],
    queryFn: () => apiGet<SyncStats>("/api/sync/stats"),
    refetchInterval: 30_000,
    enabled: auth.authenticated,
  });

  const clickup = useQuery({
    queryKey: ["integrations", "clickup"],
    queryFn: () => apiGet<IntegrationResult>("/api/integrations/clickup"),
    staleTime: 60_000,
    enabled: auth.authenticated,
  });

  const trello = useQuery({
    queryKey: ["integrations", "trello"],
    queryFn: () => apiGet<IntegrationResult>("/api/integrations/trello"),
    staleTime: 60_000,
    enabled: auth.authenticated,
  });

  if (auth.loading) return <main className="p-8 text-(--color-muted)">Loading…</main>;
  if (!auth.authenticated) return null;

  async function onLogout() {
    await logout();
    router.replace("/login");
  }

  return (
    <main className="mx-auto max-w-5xl p-8">
      <header className="mb-8 flex items-start justify-between">
        <div>
          <h1 className="text-3xl font-bold mb-1">Ginger Sync</h1>
          <p className="text-(--color-muted)">ClickUp ↔ Trello two-way sync · Hot Plate · Meetings</p>
        </div>
        <button
          onClick={onLogout}
          className="text-xs text-(--color-muted) hover:text-(--color-danger) px-3 py-1 rounded border border-(--color-border) hover:border-(--color-danger)"
        >
          Sign out
        </button>
      </header>

      <section className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <StatCard label="Active mappings" value={stats.data?.activeMappings} sub={stats.data ? `${stats.data.totalMappings} total` : undefined} loading={stats.isLoading} />
        <StatCard label="Events (24h)" value={stats.data?.events24h} sub={stats.data ? `${stats.data.totalEvents.toLocaleString()} all time` : undefined} loading={stats.isLoading} />
        <StatCard label="Errors (24h)" value={stats.data?.errors24h} variant={(stats.data?.errors24h ?? 0) > 0 ? "warn" : undefined} loading={stats.isLoading} />
        <StatCard label="Last cron" value={relTime(stats.data?.lastCronAt ?? null)} loading={stats.isLoading} />
      </section>

      <section className="grid grid-cols-1 md:grid-cols-2 gap-3 mb-8">
        <IntegrationCard name="ClickUp" result={clickup.data} loading={clickup.isLoading} />
        <IntegrationCard name="Trello" result={trello.data} loading={trello.isLoading} />
      </section>

      <section>
        <h2 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-3">Modules</h2>
        <nav className="grid grid-cols-2 sm:grid-cols-3 gap-3">
          {[
            { href: "/mappings", label: "Mappings", desc: "Pair Trello boards with ClickUp lists" },
            { href: "/logs", label: "Logs", desc: "Sync event history" },
            { href: "/items", label: "Items", desc: "Synced pairs + orphans" },
            { href: "/hot-plate", label: "Hot Plate", desc: "Personal Kanban" },
            { href: "/meetings", label: "Meetings", desc: "Upload audio → AI summary" },
            { href: "/settings", label: "Settings", desc: "Tokens, password, webhooks" },
          ].map((m) => (
            <Link
              key={m.href}
              href={m.href}
              className="block rounded-xl border border-(--color-border) bg-(--color-surface) px-4 py-3 hover:border-(--color-accent) hover:text-(--color-accent) transition-colors"
            >
              <div className="text-sm font-medium">{m.label} →</div>
              <div className="text-xs text-(--color-muted) mt-0.5">{m.desc}</div>
            </Link>
          ))}
        </nav>
      </section>
    </main>
  );
}

function StatCard({ label, value, sub, variant, loading }: { label: string; value: string | number | undefined; sub?: string; variant?: "warn" | "ok"; loading?: boolean }) {
  const valueColor = variant === "warn" ? "text-(--color-warn)" : variant === "ok" ? "text-(--color-ok)" : "text-(--color-text)";
  return (
    <div className="rounded-xl border border-(--color-border) bg-(--color-surface) px-4 py-3">
      <div className="text-[11px] uppercase tracking-wider text-(--color-muted)">{label}</div>
      <div className={`text-2xl font-semibold mt-1 ${valueColor}`}>{loading ? "·" : (value ?? "—")}</div>
      {sub && <div className="text-[11px] text-(--color-muted) mt-0.5">{sub}</div>}
    </div>
  );
}

function IntegrationCard({ name, result, loading }: { name: string; result?: IntegrationResult; loading: boolean }) {
  const ok = result?.ok === true;
  const dotClass = loading ? "bg-(--color-muted)" : ok ? "bg-(--color-ok)" : "bg-(--color-danger)";
  return (
    <div className="rounded-xl border border-(--color-border) bg-(--color-surface) px-4 py-3 flex items-center justify-between">
      <div>
        <div className="flex items-center gap-2">
          <span className={`inline-block w-2 h-2 rounded-full ${dotClass}`} />
          <span className="font-semibold">{name}</span>
        </div>
        {loading ? (
          <div className="text-xs text-(--color-muted) mt-1">checking…</div>
        ) : ok ? (
          <div className="text-xs text-(--color-muted) mt-1">
            {result?.user?.username || result?.user?.fullName || "connected"}
            {typeof result?.boards === "number" && ` · ${result.boards} boards`}
          </div>
        ) : (
          <div className="text-xs text-(--color-danger) mt-1">{result?.error || "not connected"}</div>
        )}
      </div>
    </div>
  );
}

"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRef, useState } from "react";
import { apiGet, apiSend } from "@/lib/api";
import { useAuthGuard } from "@/lib/auth";
import type { Meeting, MeetingStatus } from "@/lib/types";

interface MeetingRow {
  id: string;
  title: string;
  recordedAt: string;
  durationMs: number | null;
  status: MeetingStatus;
  errorMessage: string | null;
  hotPlateItemId: string | null;
}

export default function MeetingsListPage() {
  const auth = useAuthGuard();
  const router = useRouter();
  const qc = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [pendingTitle, setPendingTitle] = useState("");
  const [pendingSpeakers, setPendingSpeakers] = useState<number | "">("");
  const [busy, setBusy] = useState(false);

  const meetings = useQuery({
    queryKey: ["meetings"],
    queryFn: () => apiGet<MeetingRow[]>("/api/meetings/"),
    enabled: auth.authenticated,
    refetchInterval: (q) => {
      const data = q.state.data as MeetingRow[] | undefined;
      if (!data) return 10_000;
      const active = data.some((m) => m.status === "Uploaded" || m.status === "Transcribing" || m.status === "Analyzing");
      return active ? 4_000 : 30_000;
    },
  });

  const delMutation = useMutation({
    mutationFn: (id: string) => apiSend<void>("DELETE", `/api/meetings/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["meetings"] }),
  });

  const retryMutation = useMutation({
    mutationFn: (id: string) => apiSend<void>("POST", `/api/meetings/${id}/retry`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["meetings"] }),
  });

  if (auth.loading) return <main className="p-8 text-(--color-muted)">Loading…</main>;
  if (!auth.authenticated) return null;

  async function onUpload(file: File) {
    setUploadError(null);
    setBusy(true);
    try {
      const form = new FormData();
      form.set("audio", file);
      if (pendingTitle.trim()) form.set("title", pendingTitle.trim());
      if (typeof pendingSpeakers === "number" && pendingSpeakers > 0) form.set("speakers_expected", String(pendingSpeakers));

      const res = await fetch("/api/meetings/upload", { method: "POST", credentials: "include", body: form });
      if (!res.ok) throw new Error(await res.text());
      const data = (await res.json()) as { meeting_id: string };
      setPendingTitle("");
      setPendingSpeakers("");
      if (fileInput.current) fileInput.current.value = "";
      router.push(`/meetings/${data.meeting_id}`);
    } catch (e) {
      setUploadError((e as Error).message || "Upload failed");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="mx-auto max-w-5xl p-8">
      <header className="mb-6 flex items-start justify-between gap-4">
        <div>
          <Link href="/" className="text-xs text-(--color-muted) hover:text-(--color-accent)">← Dashboard</Link>
          <h1 className="text-3xl font-bold mt-1">Meetings</h1>
          <p className="text-(--color-muted) text-sm mt-1">Upload audio → transcribe (AssemblyAI) → analyze (Claude via OpenRouter).</p>
        </div>
      </header>

      <section className="mb-8 rounded-xl border border-(--color-border) bg-(--color-surface) p-4">
        <div className="text-sm font-semibold mb-3">New meeting</div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <label className="text-xs">
            <span className="block text-(--color-muted) mb-1">Title (optional)</span>
            <input
              value={pendingTitle}
              onChange={(e) => setPendingTitle(e.target.value)}
              placeholder="Otherwise inferred from filename"
              className="w-full px-3 py-2 rounded border border-(--color-border) bg-(--color-bg) text-sm"
            />
          </label>
          <label className="text-xs">
            <span className="block text-(--color-muted) mb-1">Speakers expected (1–10)</span>
            <input
              type="number"
              min={1}
              max={10}
              value={pendingSpeakers}
              onChange={(e) => setPendingSpeakers(e.target.value === "" ? "" : Number(e.target.value))}
              className="w-full px-3 py-2 rounded border border-(--color-border) bg-(--color-bg) text-sm"
            />
          </label>
          <label className="text-xs">
            <span className="block text-(--color-muted) mb-1">Audio file</span>
            <input
              ref={fileInput}
              type="file"
              accept="audio/*,video/mp4,video/webm,.mp3,.m4a,.aac,.wav,.webm,.ogg,.flac,.mp4"
              disabled={busy}
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) void onUpload(f);
              }}
              className="w-full text-xs file:mr-3 file:rounded file:border-0 file:bg-(--color-accent) file:text-(--color-bg) file:px-3 file:py-2 file:text-xs file:font-semibold"
            />
          </label>
        </div>
        {busy && <div className="text-xs text-(--color-muted) mt-3">Uploading…</div>}
        {uploadError && <div className="text-xs text-(--color-danger) mt-3">{uploadError}</div>}
      </section>

      <section>
        <div className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-3">Recent</div>
        {meetings.isLoading && <div className="text-(--color-muted) text-sm">Loading…</div>}
        {meetings.data && meetings.data.length === 0 && (
          <div className="text-(--color-muted) text-sm">No meetings yet. Upload an audio file above.</div>
        )}
        <ul className="grid grid-cols-1 gap-2">
          {meetings.data?.map((m) => (
            <li
              key={m.id}
              className="rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-3 flex items-center justify-between gap-3"
            >
              <Link href={`/meetings/${m.id}`} className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-0.5">
                  <StatusPill status={m.status} />
                  <span className="font-medium truncate">{m.title}</span>
                </div>
                <div className="text-xs text-(--color-muted)">
                  {new Date(m.recordedAt).toLocaleString()}
                  {m.durationMs ? ` · ${fmtDuration(m.durationMs)}` : ""}
                  {m.errorMessage ? ` · ${m.errorMessage}` : ""}
                </div>
              </Link>
              <div className="flex items-center gap-2 shrink-0">
                {m.status === "Error" && (
                  <button
                    onClick={() => retryMutation.mutate(m.id)}
                    className="text-xs px-2 py-1 rounded border border-(--color-border) hover:border-(--color-accent) hover:text-(--color-accent)"
                  >
                    Retry
                  </button>
                )}
                <button
                  onClick={() => {
                    if (confirm(`Delete "${m.title}"?`)) delMutation.mutate(m.id);
                  }}
                  className="text-xs px-2 py-1 rounded border border-(--color-border) hover:border-(--color-danger) hover:text-(--color-danger)"
                >
                  Delete
                </button>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}

function StatusPill({ status }: { status: MeetingStatus }) {
  const map: Record<MeetingStatus, { label: string; cls: string }> = {
    Uploaded: { label: "queued", cls: "bg-(--color-bg) text-(--color-muted) border-(--color-border)" },
    Transcribing: { label: "transcribing", cls: "bg-yellow-500/10 text-yellow-300 border-yellow-500/40" },
    Analyzing: { label: "analyzing", cls: "bg-blue-500/10 text-blue-300 border-blue-500/40" },
    Ready: { label: "ready", cls: "bg-emerald-500/10 text-emerald-300 border-emerald-500/40" },
    AudioOnly: { label: "audio only", cls: "bg-(--color-bg) text-(--color-muted) border-(--color-border)" },
    Error: { label: "error", cls: "bg-red-500/10 text-red-300 border-red-500/40" },
  };
  const m = map[status] ?? { label: status, cls: "" };
  return <span className={`inline-block px-2 py-0.5 text-[10px] uppercase tracking-wider rounded border ${m.cls}`}>{m.label}</span>;
}

function fmtDuration(ms: number): string {
  const s = Math.round(ms / 1000);
  const m = Math.floor(s / 60);
  const r = s % 60;
  return `${m}:${String(r).padStart(2, "0")}`;
}

export type { Meeting };

"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { apiGet, apiSend } from "@/lib/api";
import { useAuthGuard } from "@/lib/auth";
import type { Meeting, MeetingStatus } from "@/lib/types";

export default function MeetingDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const router = useRouter();
  const auth = useAuthGuard();
  const qc = useQueryClient();

  const [titleDraft, setTitleDraft] = useState<string | null>(null);

  const meeting = useQuery({
    queryKey: ["meeting", id],
    queryFn: () => apiGet<Meeting>(`/api/meetings/${id}`),
    enabled: auth.authenticated,
    refetchInterval: (q) => {
      const data = q.state.data as Meeting | undefined;
      if (!data) return 4_000;
      const live = data.status === "Uploaded" || data.status === "Transcribing" || data.status === "Analyzing";
      return live ? 4_000 : false;
    },
  });

  const patch = useMutation({
    mutationFn: (body: Partial<Pick<Meeting, "title" | "speakersExpected" | "language" | "hotPlateItemId">>) =>
      apiSend<void>("PATCH", `/api/meetings/${id}`, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["meeting", id] }),
  });

  const retry = useMutation({
    mutationFn: () => apiSend<void>("POST", `/api/meetings/${id}/retry`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["meeting", id] }),
  });

  const del = useMutation({
    mutationFn: () => apiSend<void>("DELETE", `/api/meetings/${id}`),
    onSuccess: () => router.push("/meetings"),
  });

  if (auth.loading) return <main className="p-8 text-(--color-muted)">Loading…</main>;
  if (!auth.authenticated) return null;
  if (meeting.isLoading) return <main className="p-8 text-(--color-muted)">Loading meeting…</main>;
  if (meeting.isError || !meeting.data) return <main className="p-8 text-(--color-danger)">Not found.</main>;

  const m = meeting.data;
  const live = m.status === "Uploaded" || m.status === "Transcribing" || m.status === "Analyzing";

  return (
    <main className="mx-auto max-w-5xl p-8">
      <header className="mb-6">
        <Link href="/meetings" className="text-xs text-(--color-muted) hover:text-(--color-accent)">← Meetings</Link>
        <div className="mt-2 flex items-start justify-between gap-3">
          <div className="flex-1 min-w-0">
            {titleDraft === null ? (
              <h1
                className="text-3xl font-bold cursor-text hover:bg-(--color-surface)/50 rounded px-1 -mx-1"
                onClick={() => setTitleDraft(m.title)}
                title="Click to rename"
              >
                {m.title}
              </h1>
            ) : (
              <input
                value={titleDraft}
                autoFocus
                onChange={(e) => setTitleDraft(e.target.value)}
                onBlur={() => {
                  const t = titleDraft.trim();
                  if (t && t !== m.title) patch.mutate({ title: t });
                  setTitleDraft(null);
                }}
                onKeyDown={(e) => {
                  if (e.key === "Enter") (e.target as HTMLInputElement).blur();
                  if (e.key === "Escape") setTitleDraft(null);
                }}
                className="text-3xl font-bold w-full bg-(--color-bg) border border-(--color-border) rounded px-2 py-1"
              />
            )}
            <div className="mt-2 flex items-center gap-3 text-xs text-(--color-muted)">
              <StatusPill status={m.status} />
              <span>{new Date(m.recordedAt).toLocaleString()}</span>
              {m.durationMs ? <span>{fmtDuration(m.durationMs)}</span> : null}
              {m.speakersExpected ? <span>{m.speakersExpected} speakers</span> : null}
              {live && <span className="text-(--color-accent)">processing…</span>}
            </div>
            {m.errorMessage && (
              <div className="mt-2 text-xs text-(--color-danger)">{m.errorMessage}</div>
            )}
          </div>
          <div className="flex gap-2 shrink-0">
            {m.status === "Error" && (
              <button
                onClick={() => retry.mutate()}
                className="text-xs px-3 py-1 rounded border border-(--color-border) hover:border-(--color-accent) hover:text-(--color-accent)"
              >
                Retry
              </button>
            )}
            <button
              onClick={() => {
                if (confirm("Delete this meeting?")) del.mutate();
              }}
              className="text-xs px-3 py-1 rounded border border-(--color-border) hover:border-(--color-danger) hover:text-(--color-danger)"
            >
              Delete
            </button>
          </div>
        </div>
      </header>

      <section className="mb-8">
        <audio controls className="w-full" src={`/api/meetings/${id}/audio`} />
      </section>

      {m.analysis && (
        <section className="mb-8 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-3">Summary</h2>
          <ul className="list-disc pl-5 space-y-1 mb-6">
            {m.analysis.summary.map((s, i) => (
              <li key={i} className="text-sm">{s}</li>
            ))}
            {m.analysis.summary.length === 0 && <li className="text-sm text-(--color-muted) list-none">No summary.</li>}
          </ul>

          {m.analysis.decisions.length > 0 && (
            <>
              <h3 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-2">Decisions</h3>
              <ul className="list-disc pl-5 space-y-1 mb-6">
                {m.analysis.decisions.map((d, i) => (
                  <li key={i} className="text-sm">{d.text}</li>
                ))}
              </ul>
            </>
          )}

          {m.analysis.actionItems.length > 0 && (
            <>
              <h3 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-2">Action items</h3>
              <ul className="space-y-2 mb-6">
                {m.analysis.actionItems.map((a, i) => (
                  <li key={i} className="text-sm border-l-2 border-(--color-accent) pl-3">
                    <div className="font-medium">{a.title}</div>
                    <div className="text-xs text-(--color-muted) mt-0.5">
                      {a.owner ? `Owner: ${a.owner}` : "Owner: —"}
                      {a.due ? ` · Due: ${a.due}` : ""}
                    </div>
                    {a.context && <div className="text-xs text-(--color-muted) mt-1 italic">{a.context}</div>}
                  </li>
                ))}
              </ul>
            </>
          )}

          {m.analysis.questions.length > 0 && (
            <>
              <h3 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-2">Open questions</h3>
              <ul className="space-y-2">
                {m.analysis.questions.map((q, i) => (
                  <li key={i} className="text-sm">
                    <div>{q.question}</div>
                    {q.context && <div className="text-xs text-(--color-muted) mt-1 italic">{q.context}</div>}
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>
      )}

      {m.transcript && (
        <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
          <h2 className="text-sm font-semibold uppercase tracking-wider text-(--color-muted) mb-3">Transcript</h2>
          <pre className="whitespace-pre-wrap text-sm leading-relaxed font-sans text-(--color-text)">{m.transcript}</pre>
        </section>
      )}

      {!m.transcript && live && (
        <section className="rounded-xl border border-dashed border-(--color-border) p-8 text-center text-(--color-muted) text-sm">
          {m.status === "Uploaded" && "Queued. Sending to AssemblyAI…"}
          {m.status === "Transcribing" && "Transcribing…"}
          {m.status === "Analyzing" && "Analyzing transcript with Claude…"}
        </section>
      )}
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

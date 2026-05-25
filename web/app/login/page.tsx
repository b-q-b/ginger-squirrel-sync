"use client";

import { Suspense, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { apiSend, ApiError } from "@/lib/api";

export default function LoginPage() {
  return (
    <Suspense fallback={<LoginShell><div className="text-(--color-muted) text-sm">Loading…</div></LoginShell>}>
      <LoginForm />
    </Suspense>
  );
}

function LoginShell({ children }: { children: React.ReactNode }) {
  return (
    <main className="min-h-screen flex items-center justify-center bg-(--color-bg) p-8">
      <div className="w-full max-w-sm bg-(--color-surface) border border-(--color-border) rounded-2xl p-8 shadow-sm">
        <h1 className="text-2xl font-bold mb-1">Ginger Sync</h1>
        <p className="text-sm text-(--color-muted) mb-6">Sign in to continue.</p>
        {children}
      </div>
    </main>
  );
}

function LoginForm() {
  const router = useRouter();
  const search = useSearchParams();
  const next = search.get("next") || "/";

  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await apiSend("POST", "/api/auth/login", { password });
      router.replace(next);
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        try {
          const j = JSON.parse(err.body);
          setError(j.error || "Incorrect password");
        } catch {
          setError("Incorrect password");
        }
      } else {
        setError((err as Error).message || "Login failed");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <LoginShell>
      <form onSubmit={onSubmit}>
        <label className="block text-xs uppercase tracking-wider text-(--color-muted) mb-2">Password</label>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoFocus
          autoComplete="current-password"
          className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg) focus:outline-none focus:ring-2 focus:ring-(--color-accent)"
        />

        {error && (
          <div className="mt-3 text-sm text-(--color-danger) bg-red-50 border border-red-200 rounded-md px-3 py-2">
            {error}
          </div>
        )}

        <button
          type="submit"
          disabled={submitting || !password}
          className="mt-6 w-full px-4 py-2 rounded-lg bg-(--color-accent) text-white font-medium hover:bg-(--color-accent-hover) disabled:opacity-50"
        >
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </LoginShell>
  );
}

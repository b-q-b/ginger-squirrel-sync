"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { logout } from "@/lib/auth";

export function TopBar({ title, subtitle }: { title?: string; subtitle?: string }) {
  const router = useRouter();
  async function onLogout() {
    await logout();
    router.replace("/login");
  }

  return (
    <header className="flex items-center justify-between mb-6 pb-4 border-b border-(--color-border)">
      <div>
        <Link href="/" className="text-sm text-(--color-muted) hover:text-(--color-accent)">
          ← Ginger Sync
        </Link>
        {title && <h1 className="text-2xl font-bold mt-1">{title}</h1>}
        {subtitle && <p className="text-sm text-(--color-muted) mt-1">{subtitle}</p>}
      </div>
      <button
        onClick={onLogout}
        className="text-xs text-(--color-muted) hover:text-(--color-danger) px-3 py-1 rounded border border-(--color-border) hover:border-(--color-danger)"
      >
        Sign out
      </button>
    </header>
  );
}

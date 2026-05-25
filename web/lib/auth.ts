"use client";

import { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import { ApiError, apiGet, apiSend } from "./api";

export interface AuthState {
  loading: boolean;
  authenticated: boolean;
  sub?: string;
}

/**
 * Client-side auth guard. Calls /api/auth/me; redirects to /login on 401.
 * Use in any page component that requires a logged-in user.
 */
export function useAuthGuard(): AuthState {
  const router = useRouter();
  const pathname = usePathname();
  const [state, setState] = useState<AuthState>({ loading: true, authenticated: false });

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const me = await apiGet<{ ok: true; sub: string }>("/api/auth/me");
        if (alive) setState({ loading: false, authenticated: true, sub: me.sub });
      } catch (e) {
        if (alive) setState({ loading: false, authenticated: false });
        if (e instanceof ApiError && e.isUnauthorized) {
          const next = pathname && pathname !== "/login" ? `?next=${encodeURIComponent(pathname)}` : "";
          router.replace(`/login${next}`);
        }
      }
    })();
    return () => { alive = false; };
  }, [router, pathname]);

  return state;
}

export async function logout() {
  try { await apiSend("POST", "/api/auth/logout"); }
  catch { /* ignore */ }
}

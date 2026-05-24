/**
 * Tiny fetch wrapper. Server-side env var NEXT_PUBLIC_API_BASE points to the .NET API.
 * In dev, next.config.ts rewrites /api/* to the API server so the browser sees same-origin.
 */
const BASE = process.env.NEXT_PUBLIC_API_BASE ?? "";

export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${BASE}${path}`, { credentials: "include" });
  if (!res.ok) throw new ApiError(res.status, await res.text());
  return res.json();
}

export async function apiSend<T>(method: "POST" | "PATCH" | "DELETE", path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method,
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new ApiError(res.status, await res.text());
  // 204 No Content
  if (res.status === 204) return undefined as T;
  return res.json();
}

export class ApiError extends Error {
  constructor(public status: number, public body: string) {
    super(`API ${status}`);
  }
}

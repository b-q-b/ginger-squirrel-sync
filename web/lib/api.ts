/**
 * Tiny fetch wrapper. Always sends cookies (HttpOnly JWT lives there) and
 * surfaces 401s through ApiError so callers can redirect to /login.
 */
const BASE = process.env.NEXT_PUBLIC_API_BASE ?? "";

export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${BASE}${path}`, { credentials: "include" });
  if (!res.ok) throw await toError(res);
  return res.json();
}

export async function apiSend<T>(
  method: "POST" | "PATCH" | "PUT" | "DELETE",
  path: string,
  body?: unknown,
): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method,
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw await toError(res);
  if (res.status === 204) return undefined as T;
  return res.json();
}

async function toError(res: Response): Promise<ApiError> {
  let body: string;
  try { body = await res.text(); } catch { body = ""; }
  return new ApiError(res.status, body);
}

export class ApiError extends Error {
  constructor(public status: number, public body: string) {
    super(`API ${status}`);
  }
  get isUnauthorized() { return this.status === 401; }
}

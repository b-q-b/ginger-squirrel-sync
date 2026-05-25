import { NextRequest, NextResponse } from "next/server";

/**
 * Light gate: if no auth cookie is present, redirect to /login.
 * Real verification still happens on the API for every request (JWT signature
 * + expiry). This is just to avoid serving the SPA pages to a totally
 * unauthenticated visitor.
 */
const PUBLIC_PATHS = ["/login"];
const COOKIE_NAME = "gss_auth";

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Skip static assets, _next, public files
  if (
    pathname.startsWith("/_next") ||
    pathname.startsWith("/favicon") ||
    pathname.includes(".") // any file with extension (.css, .png, etc.)
  ) {
    return NextResponse.next();
  }

  // Public routes (login, health, etc.)
  if (PUBLIC_PATHS.some((p) => pathname.startsWith(p))) {
    return NextResponse.next();
  }

  const hasAuthCookie = request.cookies.has(COOKIE_NAME);
  if (!hasAuthCookie) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    url.searchParams.set("next", pathname);
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!api|_next|favicon).*)"],
};

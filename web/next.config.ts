import type { NextConfig } from "next";

const config: NextConfig = {
  reactStrictMode: true,
  // Emit a minimal Node-runnable server in .next/standalone so the Docker image stays small.
  output: "standalone",
  async rewrites() {
    // In dev, forward /api/* to the .NET API (next dev only) to avoid CORS.
    // In prod, Traefik routes /api/* to the api container directly — Next.js never sees these.
    if (process.env.NODE_ENV !== "production") {
      return [
        { source: "/api/:path*", destination: `${process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:5081"}/api/:path*` },
      ];
    }
    return [];
  },
};

export default config;

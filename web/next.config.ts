import type { NextConfig } from "next";

const config: NextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      // Forward /api/* in dev to the .NET API to avoid CORS in development.
      { source: "/api/:path*", destination: `${process.env.NEXT_PUBLIC_API_BASE ?? "http://localhost:5081"}/api/:path*` },
    ];
  },
};

export default config;

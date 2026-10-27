import type { NextConfig } from "next";

// The browser only ever talks to Next. /api and /sanctum are proxied to Laravel so its session and
// XSRF cookies are first-party (docs/05-api-spec.md §1). In Docker the backend is http://backend:8000.
const backend = process.env.BACKEND_INTERNAL_URL ?? "http://localhost:8000";

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${backend}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${backend}/sanctum/:path*` },
    ];
  },
};

export default nextConfig;

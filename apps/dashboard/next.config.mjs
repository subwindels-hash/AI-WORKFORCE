/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // The browser never talks to the API host directly — relative /api calls are
  // proxied server-side to the AEGIS API process.
  async rewrites() {
    const target = process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:4000';
    return [{ source: '/api/:path*', destination: `${target}/api/:path*` }];
  },
};

export default nextConfig;

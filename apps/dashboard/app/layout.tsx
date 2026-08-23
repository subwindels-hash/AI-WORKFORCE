import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'AEGIS — AI Trading Intelligence',
  description:
    'Standalone AI Trading Intelligence platform. Phase 1: ANALYSIS_ONLY — multi-agent market analysis with risk governance. No orders are placed.',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}

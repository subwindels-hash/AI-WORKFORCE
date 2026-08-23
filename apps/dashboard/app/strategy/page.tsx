'use client';

import { HeaderBarShim, Nav } from '@/components/strategy-shell';
import StrategyLab from '@/components/strategy-lab';

export default function StrategyPage() {
  return (
    <div className="min-h-screen">
      <HeaderBarShim />
      <Nav />
      <StrategyLab />
    </div>
  );
}

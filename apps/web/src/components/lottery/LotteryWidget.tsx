'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import type { LiDashboard, LiDraw } from './types';

interface LotteryWidgetProps {
  dashboard?: LiDashboard;
  loading?: boolean;
}

export const LotteryWidget: React.FC<LotteryWidgetProps> = ({ dashboard, loading = false }) => {
  const [countdown, setCountdown] = useState({ days: 0, hours: 0, minutes: 0 });

  useEffect(() => {
    if (!dashboard?.nextEstimated) return;

    const updateCountdown = () => {
      const target = new Date(dashboard.nextEstimated!).getTime();
      const now = Date.now();
      const diff = Math.max(0, target - now);

      const days = Math.floor(diff / (1000 * 60 * 60 * 24));
      const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

      setCountdown({ days, hours, minutes });
    };

    updateCountdown();
    const interval = setInterval(updateCountdown, 60000);
    return () => clearInterval(interval);
  }, [dashboard?.nextEstimated]);

  if (loading) {
    return (
      <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <div className="animate-pulse space-y-4">
          <div className="h-8 w-48 rounded bg-slate-800" />
          <div className="h-20 rounded bg-slate-800" />
          <div className="grid grid-cols-3 gap-4">
            <div className="h-16 rounded bg-slate-800" />
            <div className="h-16 rounded bg-slate-800" />
            <div className="h-16 rounded bg-slate-800" />
          </div>
        </div>
      </div>
    );
  }

  if (!dashboard || dashboard.status === 'NO_DATA') {
    return (
      <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <div className="text-center">
          <div className="mb-4 text-4xl">🎰</div>
          <h3 className="mb-2 text-lg font-semibold text-white">Lottery Intelligence</h3>
          <p className="mb-4 text-sm text-slate-400">No lottery data available</p>
          <Link href="/lottery" className="inline-flex items-center gap-2 rounded-xl bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
            Open Lottery Intel
          </Link>
        </div>
      </div>
    );
  }

  const formatJackpot = (jackpot: number | null): string => {
    if (jackpot === null) return '—';
    if (jackpot >= 1000000) return `€${(jackpot / 1000000).toFixed(1)}M`;
    if (jackpot >= 1000) return `€${(jackpot / 1000).toFixed(0)}K`;
    return `€${jackpot.toFixed(0)}`;
  };

  return (
    <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
      {/* Header */}
      <div className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h3 className="mb-1 text-xl font-bold text-white">🎰 EuroMillions Lottery</h3>
          <p className="text-sm text-slate-400">AI-powered lottery analysis & verified draws</p>
        </div>
        <div className="flex gap-2">
          <Link href="/lottery" className="rounded-xl bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
            Lottery Intel
          </Link>
          <Link href="/lottery#generator" className="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-white transition hover:border-cyan-600">
            Generate
          </Link>
        </div>
      </div>

      {/* Jackpot & Countdown */}
      <div className="mb-6 grid gap-4 lg:grid-cols-2">
        {/* Jackpot Card */}
        <div className="rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-600/20 p-6 backdrop-blur">
          <div className="mb-2 flex items-center gap-2">
            <span className="text-2xl">🏆</span>
            <span className="text-xs font-semibold uppercase tracking-wider text-amber-300">Current Jackpot</span>
          </div>
          <div className="mb-1 text-4xl font-bold text-white">
            {formatJackpot(dashboard.jackpot)}
          </div>
          <div className="text-xs text-slate-400">
            {dashboard.imported} verified draws imported
          </div>
        </div>

        {/* Countdown Card */}
        <div className="rounded-xl border border-slate-800 bg-slate-950 p-6">
          <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-cyan-400">
            Next Draw
          </div>
          <div className="mb-4 text-sm text-slate-300">
            {dashboard.nextEstimated ? new Date(dashboard.nextEstimated).toLocaleString() : 'Not available'}
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div className="text-center">
              <div className="text-3xl font-bold text-cyan-400">{countdown.days}</div>
              <div className="text-xs uppercase text-slate-500">Days</div>
            </div>
            <div className="text-center">
              <div className="text-3xl font-bold text-cyan-400">{countdown.hours}</div>
              <div className="text-xs uppercase text-slate-500">Hours</div>
            </div>
            <div className="text-center">
              <div className="text-3xl font-bold text-cyan-400">{countdown.minutes}</div>
              <div className="text-xs uppercase text-slate-500">Minutes</div>
            </div>
          </div>
        </div>
      </div>

      {/* Recent Results */}
      {dashboard.recentDraws && dashboard.recentDraws.length > 0 && (
        <div className="mb-6">
          <h4 className="mb-3 text-sm font-semibold text-white">Recent Results</h4>
          <div className="space-y-2">
            {dashboard.recentDraws.slice(0, 3).map((draw) => (
              <DrawCard key={draw.id} draw={draw} />
            ))}
          </div>
        </div>
      )}

      {/* Quick Actions */}
      <div className="grid gap-2 sm:grid-cols-2">
        <Link href="/lottery#draws" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
          <span>📊</span> View All Draws
        </Link>
        {dashboard.myTicketsCount > 0 && (
          <Link href="/lottery#tickets" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
            <span>🎫</span> My Tickets ({dashboard.myTicketsCount})
          </Link>
        )}
      </div>
    </div>
  );
};

const DrawCard: React.FC<{ draw: LiDraw }> = ({ draw }) => {
  const mainNumbers = draw.mainNumbers || draw.numbers?.main || [];
  const starNumbers = draw.stars || draw.numbers?.stars || draw.bonusNumbers || [];
  const drawDate = draw.draw_date || draw.drawDate || '';

  return (
    <div className="rounded-xl border border-slate-800 bg-slate-950 p-3">
      <div className="mb-2 text-xs text-slate-500">
        {drawDate.substring(0, 10)}
      </div>
      <div className="flex flex-wrap gap-1.5">
        {mainNumbers.map((num, idx) => (
          <span key={idx} className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-amber-500/20 text-xs font-bold text-amber-300">
            {num}
          </span>
        ))}
        {starNumbers.map((num, idx) => (
          <span key={`star-${idx}`} className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-cyan-500/20 text-xs font-bold text-cyan-300">
            ★{num}
          </span>
        ))}
      </div>
    </div>
  );
};

export default LotteryWidget;

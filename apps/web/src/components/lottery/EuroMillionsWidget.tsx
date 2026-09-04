'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import type { LiDashboard, LiDraw, EuroMillionsData } from './types';

interface EuroMillionsWidgetProps {
  dashboard?: LiDashboard;
  loading?: boolean;
  hotNumbers?: number[];
  coldNumbers?: number[];
}

export const EuroMillionsWidget: React.FC<EuroMillionsWidgetProps> = ({ 
  dashboard, 
  loading = false,
  hotNumbers = [],
  coldNumbers = []
}) => {
  const [countdown, setCountdown] = useState({ days: 0, hours: 0, minutes: 0, seconds: 0 });
  const [generatedNumbers, setGeneratedNumbers] = useState<{ mains: number[]; stars: number[] } | null>(null);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    if (!dashboard?.nextEstimated) return;

    const updateCountdown = () => {
      const target = new Date(dashboard.nextEstimated!).getTime();
      const now = Date.now();
      const diff = Math.max(0, target - now);

      const days = Math.floor(diff / (1000 * 60 * 60 * 24));
      const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
      const seconds = Math.floor((diff % (1000 * 60)) / 1000);

      setCountdown({ days, hours, minutes, seconds });
    };

    updateCountdown();
    const interval = setInterval(updateCountdown, 1000);
    return () => clearInterval(interval);
  }, [dashboard?.nextEstimated]);

  const generateNumbers = async () => {
    setGenerating(true);
    try {
      // Simulate AI generation - in production, call your API
      const rules = dashboard?.rules || { mains: 5, mainMax: 50, stars: 2, starMax: 12 };
      
      // Generate random main numbers (1-50)
      const mains: number[] = [];
      while (mains.length < rules.mains) {
        const num = Math.floor(Math.random() * rules.mainMax) + 1;
        if (!mains.includes(num)) mains.push(num);
      }
      mains.sort((a, b) => a - b);

      // Generate random star numbers (1-12)
      const stars: number[] = [];
      while (stars.length < rules.stars) {
        const num = Math.floor(Math.random() * rules.starMax) + 1;
        if (!stars.includes(num)) stars.push(num);
      }
      stars.sort((a, b) => a - b);

      setGeneratedNumbers({ mains, stars });
    } catch (error) {
      console.error('Failed to generate numbers:', error);
    } finally {
      setGenerating(false);
    }
  };

  if (loading) {
    return (
      <div className="overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-900 to-slate-950 p-6">
        <div className="animate-pulse space-y-4">
          <div className="h-8 w-64 rounded bg-slate-800" />
          <div className="h-24 rounded bg-gradient-to-r from-amber-500/20 to-orange-600/20" />
          <div className="h-20 rounded bg-slate-800" />
          <div className="grid grid-cols-2 gap-4">
            <div className="h-16 rounded bg-slate-800" />
            <div className="h-16 rounded bg-slate-800" />
          </div>
        </div>
      </div>
    );
  }

  if (!dashboard || dashboard.status === 'NO_DATA') {
    return (
      <div className="overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-900 to-slate-950 p-6">
        <div className="text-center">
          <div className="mb-4 text-5xl">🎰</div>
          <h3 className="mb-2 text-xl font-bold text-white">EuroMillions Intelligence</h3>
          <p className="mb-4 text-sm text-slate-400">AI-powered lottery analysis with verified draw data</p>
          <Link href="/lottery" className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:from-amber-300 hover:to-orange-400">
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

  const latestDraw = dashboard.lastDraw;
  const latestMainNumbers = latestDraw?.mainNumbers || latestDraw?.numbers?.main || [];
  const latestStarNumbers = latestDraw?.stars || latestDraw?.numbers?.stars || latestDraw?.bonusNumbers || [];

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-900 to-slate-950">
      {/* Header with gradient */}
      <div className="bg-gradient-to-r from-amber-500/10 to-orange-600/10 p-6">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h3 className="mb-1 text-2xl font-bold text-white">🎰 EuroMillions</h3>
            <p className="text-sm text-slate-400">AI-powered lottery intelligence</p>
          </div>
          <Link href="/lottery" className="rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-4 py-2 text-sm font-bold text-slate-950 transition hover:from-amber-300 hover:to-orange-400">
            Full Analysis
          </Link>
        </div>

        {/* Jackpot Display */}
        <div className="rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-600/30 p-6 backdrop-blur">
          <div className="mb-2 flex items-center gap-2">
            <span className="text-3xl">🏆</span>
            <span className="text-xs font-bold uppercase tracking-wider text-amber-300">Current Jackpot</span>
          </div>
          <div className="mb-2 text-5xl font-black text-white">
            {formatJackpot(dashboard.jackpot)}
          </div>
          <div className="text-xs text-slate-300">
            {dashboard.imported} verified draws • Real data, no predictions
          </div>
        </div>
      </div>

      <div className="p-6">
        {/* Countdown Timer */}
        <div className="mb-6 rounded-xl border border-slate-800 bg-slate-950 p-5">
          <div className="mb-3 flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-cyan-400">Next Draw Countdown</span>
            <span className="text-xs text-slate-500">
              {dashboard.nextEstimated ? new Date(dashboard.nextEstimated).toLocaleDateString() : ''}
            </span>
          </div>
          <div className="grid grid-cols-4 gap-3">
            <div className="rounded-lg bg-slate-900 p-3 text-center">
              <div className="text-3xl font-black text-cyan-400">{countdown.days}</div>
              <div className="text-xs uppercase text-slate-500">Days</div>
            </div>
            <div className="rounded-lg bg-slate-900 p-3 text-center">
              <div className="text-3xl font-black text-cyan-400">{countdown.hours}</div>
              <div className="text-xs uppercase text-slate-500">Hours</div>
            </div>
            <div className="rounded-lg bg-slate-900 p-3 text-center">
              <div className="text-3xl font-black text-cyan-400">{countdown.minutes}</div>
              <div className="text-xs uppercase text-slate-500">Minutes</div>
            </div>
            <div className="rounded-lg bg-slate-900 p-3 text-center">
              <div className="text-3xl font-black text-cyan-400">{countdown.seconds}</div>
              <div className="text-xs uppercase text-slate-500">Seconds</div>
            </div>
          </div>
        </div>

        {/* Latest Draw Results */}
        {latestDraw && (
          <div className="mb-6 rounded-xl border border-slate-800 bg-slate-950 p-5">
            <div className="mb-3 flex items-center justify-between">
              <h4 className="text-sm font-bold text-white">Latest Draw Results</h4>
              <span className="text-xs text-slate-500">
                {(latestDraw.draw_date || latestDraw.drawDate || '').substring(0, 10)}
              </span>
            </div>
            <div className="space-y-3">
              <div>
                <div className="mb-2 text-xs font-semibold uppercase text-amber-400">Main Numbers (1-50)</div>
                <div className="flex flex-wrap gap-2">
                  {latestMainNumbers.map((num, idx) => (
                    <div key={idx} className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-sm font-black text-slate-950 shadow-lg">
                      {num}
                    </div>
                  ))}
                </div>
              </div>
              {latestStarNumbers.length > 0 && (
                <div>
                  <div className="mb-2 text-xs font-semibold uppercase text-cyan-400">Lucky Stars (1-12)</div>
                  <div className="flex flex-wrap gap-2">
                    {latestStarNumbers.map((num, idx) => (
                      <div key={idx} className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 text-sm font-black text-slate-950 shadow-lg">
                        ★{num}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* AI Number Generator */}
        <div className="mb-6 rounded-xl border border-cyan-800/50 bg-gradient-to-br from-cyan-950/30 to-blue-950/30 p-5">
          <div className="mb-3 flex items-center gap-2">
            <span className="text-2xl">🤖</span>
            <div>
              <h4 className="text-sm font-bold text-white">AI Number Generator</h4>
              <p className="text-xs text-slate-400">Generate balanced random numbers</p>
            </div>
          </div>
          <button
            onClick={generateNumbers}
            disabled={generating}
            className="w-full rounded-xl bg-gradient-to-r from-cyan-400 to-blue-500 px-4 py-3 text-sm font-bold text-slate-950 transition hover:from-cyan-300 hover:to-blue-400 disabled:opacity-50"
          >
            {generating ? 'Generating...' : 'Generate Lucky Numbers'}
          </button>
          {generatedNumbers && (
            <div className="mt-4 space-y-3">
              <div>
                <div className="mb-2 text-xs font-semibold uppercase text-amber-400">Your Numbers</div>
                <div className="flex flex-wrap gap-2">
                  {generatedNumbers.mains.map((num, idx) => (
                    <div key={idx} className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/20 text-sm font-black text-amber-300">
                      {num}
                    </div>
                  ))}
                </div>
              </div>
              <div>
                <div className="mb-2 text-xs font-semibold uppercase text-cyan-400">Lucky Stars</div>
                <div className="flex flex-wrap gap-2">
                  {generatedNumbers.stars.map((num, idx) => (
                    <div key={idx} className="flex h-10 w-10 items-center justify-center rounded-full bg-cyan-500/20 text-sm font-black text-cyan-300">
                      ★{num}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Hot/Cold Statistics */}
        {(hotNumbers.length > 0 || coldNumbers.length > 0) && (
          <div className="mb-6 grid gap-4 sm:grid-cols-2">
            {hotNumbers.length > 0 && (
              <div className="rounded-xl border border-red-800/50 bg-red-950/20 p-4">
                <div className="mb-2 flex items-center gap-2">
                  <span className="text-lg">🔥</span>
                  <h4 className="text-xs font-bold uppercase text-red-400">Hot Numbers</h4>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {hotNumbers.slice(0, 5).map((num, idx) => (
                    <span key={idx} className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-red-500/20 text-xs font-bold text-red-300">
                      {num}
                    </span>
                  ))}
                </div>
              </div>
            )}
            {coldNumbers.length > 0 && (
              <div className="rounded-xl border border-blue-800/50 bg-blue-950/20 p-4">
                <div className="mb-2 flex items-center gap-2">
                  <span className="text-lg">❄️</span>
                  <h4 className="text-xs font-bold uppercase text-blue-400">Cold Numbers</h4>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {coldNumbers.slice(0, 5).map((num, idx) => (
                    <span key={idx} className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-500/20 text-xs font-bold text-blue-300">
                      {num}
                    </span>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* Quick Actions */}
        <div className="grid gap-2 sm:grid-cols-2">
          <Link href="/lottery#generator" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
            <span>🎲</span> Number Generator
          </Link>
          <Link href="/lottery#draws" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
            <span>📊</span> Past Results
          </Link>
          <Link href="/lottery#statistics" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
            <span>📈</span> Statistics
          </Link>
          {dashboard.myTicketsCount > 0 && (
            <Link href="/lottery#tickets" className="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:border-cyan-600">
              <span>🎫</span> My Tickets ({dashboard.myTicketsCount})
            </Link>
          )}
        </div>
      </div>
    </div>
  );
};

export default EuroMillionsWidget;

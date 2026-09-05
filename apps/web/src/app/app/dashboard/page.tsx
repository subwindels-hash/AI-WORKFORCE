'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { EuroMillionsWidget } from '../../../components/lottery';
import type { LiDashboard } from '../../../components/lottery/types';

export default function DashboardPage() {
  const [lotteryDashboard, setLotteryDashboard] = useState<LiDashboard | null>(null);
  const [hotNumbers, setHotNumbers] = useState<number[]>([]);
  const [coldNumbers, setColdNumbers] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchLotteryData = async () => {
      try {
        const response = await fetch('/api/lottery/dashboard');
        if (response.ok) {
          const data: LiDashboard = await response.json();
          setLotteryDashboard(data);
        }
      } catch (error) {
        console.error('Failed to fetch lottery data:', error);
      } finally {
        setLoading(false);
      }
    };

    const fetchStatistics = async () => {
      try {
        const hotResponse = await fetch('/api/lottery/statistics/hot-cold?window=0');
        if (hotResponse.ok) {
          const data = await hotResponse.json();
          setHotNumbers(Array.isArray(data.hot) ? data.hot : []);
          setColdNumbers(Array.isArray(data.cold) ? data.cold : []);
        }
      } catch (error) {
        console.error('Failed to fetch statistics:', error);
      }
    };

    fetchLotteryData();
    fetchStatistics();
  }, []);

  return (
    <div className="min-h-screen bg-slate-950">
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="mb-2 text-3xl font-bold text-white">Dashboard</h1>
          <p className="text-slate-400">Welcome to your WINDELS AI Workforce workspace</p>
        </div>

        {/* Quick Stats */}
        <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <QuickStatCard
            icon="🤖"
            label="AI Runs"
            value="—"
            link="/intelligence"
            color="cyan"
          />
          <QuickStatCard
            icon="💱"
            label="Trading"
            value="—"
            link="/app/trading"
            color="green"
          />
          <QuickStatCard
            icon="🎰"
            label="Lottery"
            value={lotteryDashboard ? `${lotteryDashboard.imported} draws` : '—'}
            link="/lottery"
            color="amber"
          />
          <QuickStatCard
            icon="🎯"
            label="Leads"
            value="—"
            link="/app/leads"
            color="purple"
          />
        </div>

        {/* Main Content Grid */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Lottery Widget - EuroMillions Enhanced */}
          <div>
            <h2 className="mb-4 text-lg font-semibold text-white">Lottery Intelligence</h2>
            <EuroMillionsWidget
              dashboard={lotteryDashboard || undefined}
              loading={loading}
              hotNumbers={hotNumbers}
              coldNumbers={coldNumbers}
            />
          </div>

          {/* Quick Actions */}
          <div>
            <h2 className="mb-4 text-lg font-semibold text-white">Quick Actions</h2>
            <div className="grid gap-3 sm:grid-cols-2">
              <QuickActionCard
                icon="🧠"
                title="AI Analysis"
                description="Run market analysis"
                href="/intelligence"
              />
              <QuickActionCard
                icon="💹"
                title="Trading"
                description="View trading dashboard"
                href="/app/trading"
              />
              <QuickActionCard
                icon="🎲"
                title="Generate Numbers"
                description="AI lottery numbers"
                href="/lottery#generator"
              />
              <QuickActionCard
                icon="📊"
                title="Statistics"
                description="Lottery analytics"
                href="/lottery#statistics"
              />
              <QuickActionCard
                icon="🎫"
                title="My Tickets"
                description="View saved tickets"
                href="/lottery#tickets"
              />
              <QuickActionCard
                icon="🔍"
                title="Lead Discovery"
                description="Find new leads"
                href="/app/leads"
              />
            </div>
          </div>
        </div>

        {/* Recent Activity */}
        <div className="mt-8">
          <h2 className="mb-4 text-lg font-semibold text-white">Recent Activity</h2>
          <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
            <p className="text-sm text-slate-400">
              Your recent AI analyses, trades, and lottery activity will appear here.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

function QuickStatCard({ icon, label, value, link, color }: {
  icon: string;
  label: string;
  value: string;
  link: string;
  color: string;
}) {
  const colorClasses = {
    cyan: 'from-cyan-500/10 to-blue-500/10 border-cyan-800/50',
    green: 'from-green-500/10 to-emerald-500/10 border-green-800/50',
    amber: 'from-amber-500/10 to-orange-500/10 border-amber-800/50',
    purple: 'from-purple-500/10 to-pink-500/10 border-purple-800/50',
  };

  return (
    <Link
      href={link}
      className={`rounded-2xl border bg-gradient-to-br p-5 transition hover:scale-105 ${colorClasses[color as keyof typeof colorClasses]}`}
    >
      <div className="mb-2 text-2xl">{icon}</div>
      <div className="mb-1 text-xs font-semibold uppercase text-slate-400">{label}</div>
      <div className="text-2xl font-bold text-white">{value}</div>
    </Link>
  );
}

function QuickActionCard({ icon, title, description, href }: {
  icon: string;
  title: string;
  description: string;
  href: string;
}) {
  return (
    <Link
      href={href}
      className="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-900 p-4 transition hover:border-cyan-600"
    >
      <div className="text-2xl">{icon}</div>
      <div className="flex-1">
        <div className="text-sm font-semibold text-white">{title}</div>
        <div className="text-xs text-slate-400">{description}</div>
      </div>
    </Link>
  );
}

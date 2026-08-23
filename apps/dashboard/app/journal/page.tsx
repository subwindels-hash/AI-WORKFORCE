'use client';

import { useCallback, useEffect, useState } from 'react';
import { Nav } from '@/components/panels';
import { api, formatPrice } from '@/lib/api';
import type { AnalyticsSummaryView, CalibrationView, JournalEntryView } from '@/lib/types';

const GROUPS = [
  { key: 'strategy', label: 'By strategy' },
  { key: 'symbol', label: 'By symbol' },
  { key: 'market', label: 'By market' },
  { key: 'confidence', label: 'By AI confidence' },
];

export default function JournalPage() {
  const [entries, setEntries] = useState<JournalEntryView[]>([]);
  const [source, setSource] = useState<string>('backtest');
  const [summary, setSummary] = useState<AnalyticsSummaryView | null>(null);
  const [groupBy, setGroupBy] = useState('strategy');
  const [calibration, setCalibration] = useState<CalibrationView | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [journal, analytics, cal] = await Promise.all([
        api.journal(source ? `source=${source}&limit=200` : 'limit=200'),
        api.analytics(groupBy),
        api.calibration(),
      ]);
      setEntries(journal.entries);
      setSummary(analytics);
      setCalibration(cal);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'failed to load journal');
    }
  }, [source, groupBy]);

  useEffect(() => { void load(); }, [load]);

  return (
    <div className="min-h-screen">
      <Nav />
      <div className="mx-auto max-w-[1500px] space-y-3 px-4 py-4">
        <div className="flex flex-wrap items-end justify-between gap-2">
          <div>
            <h1 className="text-lg font-bold text-white">Trade Journal &amp; Performance Analytics</h1>
            <p className="text-xs text-slate-500">
              Every simulated or manual trade is journaled with fees, slippage, reason and confidence — the basis for
              &ldquo;do high-confidence decisions actually perform better?&rdquo;
            </p>
          </div>
          {error && <div className="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-300">{error}</div>}
        </div>

        {/* overall + calibration */}
        {summary && (
          <div className="grid gap-3 lg:grid-cols-2">
            <div className="panel">
              <div className="flex items-center gap-2 px-4 pt-3">
                <div className="panel-title !p-0">Overall</div>
                <div className="ml-auto flex gap-1">
                  {GROUPS.map((g) => (
                    <button
                      key={g.key}
                      onClick={() => setGroupBy(g.key)}
                      className={`rounded px-2 py-0.5 text-[10px] font-semibold ${groupBy === g.key ? 'bg-sky-500/20 text-sky-300' : 'bg-[#141926] text-slate-500 hover:text-slate-300'}`}
                    >
                      {g.label}
                    </button>
                  ))}
                </div>
              </div>
              <div className="grid grid-cols-3 gap-2 px-4 py-3 sm:grid-cols-6">
                <Stat label="Closed trades" value={String(summary.overall.closedTrades)} />
                <Stat label="Win rate" value={summary.overall.winRate === null ? '—' : `${(summary.overall.winRate * 100).toFixed(1)}%`} />
                <Stat label="Profit factor" value={summary.overall.profitFactor === null ? '—' : summary.overall.profitFactor.toFixed(2)} />
                <Stat label="Total P&L" value={`$${summary.overall.totalPnl.toFixed(0)}`} tone={summary.overall.totalPnl >= 0 ? 'text-emerald-400' : 'text-rose-400'} />
                <Stat label="Avg R" value={summary.overall.avgRMultiple === null ? '—' : summary.overall.avgRMultiple.toFixed(2)} />
                <Stat label="Max DD ($)" value={summary.overall.maxDrawdownPct.toFixed(0)} tone="text-amber-400" />
              </div>
              <div className="scroll-thin max-h-64 overflow-auto border-t border-[#1d2333] px-4 py-2">
                <table className="w-full text-[11px]">
                  <thead className="text-left text-slate-500">
                    <tr>
                      <th className="py-1 pr-2">{groupBy}</th><th className="pr-2 text-right">Trades</th>
                      <th className="pr-2 text-right">Win%</th><th className="pr-2 text-right">PF</th>
                      <th className="pr-2 text-right">E[P&L]</th><th className="pr-2 text-right">Total P&L</th>
                    </tr>
                  </thead>
                  <tbody className="font-mono">
                    {summary.groups.map((g) => (
                      <tr key={g.key} className="border-t border-[#141926]">
                        <td className="py-1 pr-2 text-slate-300">{g.key}</td>
                        <td className="pr-2 text-right text-slate-400">{g.metrics.count}</td>
                        <td className="pr-2 text-right text-slate-300">{g.metrics.winRate === null ? '—' : `${(g.metrics.winRate * 100).toFixed(0)}%`}</td>
                        <td className="pr-2 text-right text-slate-300">{g.metrics.profitFactor === null ? '—' : g.metrics.profitFactor.toFixed(2)}</td>
                        <td className={`pr-2 text-right ${(g.metrics.expectancyPnl ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{g.metrics.expectancyPnl === null ? '—' : g.metrics.expectancyPnl.toFixed(1)}</td>
                        <td className={`pr-2 text-right ${g.metrics.totalPnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{g.metrics.totalPnl.toFixed(0)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {summary.note && <div className="py-2 text-[11px] text-amber-300/80">{summary.note}</div>}
              </div>
            </div>

            <div className="panel">
              <div className="panel-title">Confidence calibration — the key question</div>
              <div className="px-4 pb-3">
                <p className={`mb-3 rounded-lg border p-2.5 text-[11px] leading-relaxed ${calibration?.sufficientData ? 'border-[#1d2333] bg-[#0f131c] text-slate-300' : 'border-amber-500/30 bg-amber-500/5 text-amber-300/90'}`}>
                  {calibration?.verdict ?? '…'}
                </p>
                {calibration && calibration.buckets.length > 0 ? (
                  <div className="space-y-2">
                    {calibration.buckets.map((b) => (
                      <div key={b.key}>
                        <div className="mb-0.5 flex justify-between text-[11px] text-slate-400">
                          <span>{b.key}</span>
                          <span className="font-mono">
                            {b.count} trades · win {b.winRate === null ? '—' : `${(b.winRate * 100).toFixed(0)}%`} · E[R] {b.expectancyR === null ? '—' : b.expectancyR.toFixed(2)}
                          </span>
                        </div>
                        <div className="h-2 overflow-hidden rounded bg-[#141926]">
                          <div className={`h-full rounded ${(b.winRate ?? 0) >= 0.5 ? 'bg-emerald-500' : 'bg-rose-500'}`} style={{ width: `${Math.min(100, (b.winRate ?? 0) * 100)}%` }} />
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-[11px] text-slate-500">No confidence-tagged closed trades yet — run backtests (strategy signals tag confidence) or record manual entries.</div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* journal table */}
        <div className="panel">
          <div className="flex items-center gap-2 px-4 pt-3">
            <div className="panel-title !p-0">Journal entries</div>
            <div className="ml-auto flex gap-1">
              {['backtest', 'manual', 'paper', 'live', ''].map((s) => (
                <button
                  key={s || 'all'}
                  onClick={() => setSource(s)}
                  className={`rounded px-2 py-0.5 text-[10px] font-semibold uppercase ${source === s ? 'bg-sky-500/20 text-sky-300' : 'bg-[#141926] text-slate-500 hover:text-slate-300'}`}
                >
                  {s || 'all'}
                </button>
              ))}
            </div>
          </div>
          <div className="scroll-thin max-h-[480px] overflow-auto px-4 pb-3">
            <table className="w-full text-[11px]">
              <thead className="text-left text-slate-500">
                <tr>
                  <th className="py-1 pr-2">Time</th><th className="pr-2">Symbol</th><th className="pr-2">Dir</th>
                  <th className="pr-2">Strategy</th><th className="pr-2 text-right">Entry</th>
                  <th className="pr-2 text-right">Exit</th><th className="pr-2 text-right">Size</th>
                  <th className="pr-2 text-right">Fees</th><th className="pr-2 text-right">P&L</th>
                  <th className="pr-2 text-right">R</th><th className="pr-2 text-right">Conf</th>
                  <th className="pr-2">Reason</th>
                </tr>
              </thead>
              <tbody className="font-mono">
                {entries.map((e) => (
                  <tr key={e.id} className="border-t border-[#141926]">
                    <td className="py-1 pr-2 text-slate-400">{e.entry.time.slice(0, 16).replace('T', ' ')}</td>
                    <td className="pr-2 text-slate-300">{e.symbol}</td>
                    <td className={`pr-2 font-bold ${e.direction === 'LONG' ? 'text-emerald-400' : 'text-rose-400'}`}>{e.direction === 'LONG' ? '▲' : '▼'}</td>
                    <td className="pr-2 text-slate-400">{e.strategy ?? '—'}</td>
                    <td className="pr-2 text-right text-slate-300">{formatPrice(e.entry.price)}</td>
                    <td className="pr-2 text-right text-slate-300">{e.exit ? formatPrice(e.exit.price) : 'open'}</td>
                    <td className="pr-2 text-right text-slate-500">{Math.round(e.positionSize).toLocaleString()}</td>
                    <td className="pr-2 text-right text-slate-500">{e.fees.toFixed(2)}</td>
                    <td className={`pr-2 text-right ${(e.pnl ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{e.pnl === null ? '—' : e.pnl.toFixed(1)}</td>
                    <td className="pr-2 text-right text-slate-300">{e.rMultiple === null ? '—' : e.rMultiple.toFixed(2)}</td>
                    <td className="pr-2 text-right text-slate-400">{e.aiConfidence === null ? '—' : `${(e.aiConfidence * 100).toFixed(0)}%`}</td>
                    <td className="pr-2 max-w-[260px] truncate text-slate-500" title={e.reasonForTrade}>{e.reasonForTrade}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {entries.length === 0 && <div className="py-3 text-[11px] text-slate-600">no entries for this filter yet</div>}
          </div>
          <div className="border-t border-[#1d2333] px-4 py-2 text-[10px] text-slate-600">
            Backtest entries are recorded automatically; manual entries via <span className="font-mono text-slate-500">POST /api/journal</span>;
            paper-trading entries will be recorded automatically in Phase 3.
          </div>
        </div>
      </div>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div>
      <div className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</div>
      <div className={`mt-0.5 font-mono text-sm font-bold ${tone ?? 'text-slate-200'}`}>{value}</div>
    </div>
  );
}

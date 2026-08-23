'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, formatPrice } from '@/lib/api';
import type { BacktestResult, BacktestSummary, StrategyRecord } from '@/lib/types';

const SYMBOLS: { symbol: string; marketClass: string }[] = [
  { symbol: 'BTCUSDT', marketClass: 'crypto' },
  { symbol: 'ETHUSDT', marketClass: 'crypto' },
  { symbol: 'SOLUSDT', marketClass: 'crypto' },
  { symbol: 'EURUSD', marketClass: 'forex' },
  { symbol: 'GBPUSD', marketClass: 'forex' },
  { symbol: 'USDJPY', marketClass: 'forex' },
  { symbol: 'XAUUSD', marketClass: 'forex' },
];
const TIMEFRAMES = ['15m', '1h', '4h', '1d'];

const LIFECYCLE_STAGES: { key: string; label: string }[] = [
  { key: 'DRAFT', label: 'Draft' },
  { key: 'BACKTESTED', label: 'Backtested' },
  { key: 'VALIDATED', label: 'Validated' },
  { key: 'RISK_REVIEWED', label: 'Risk reviewed' },
  { key: 'PAPER_TRADING', label: 'Paper (Phase 3)' },
  { key: 'APPROVED', label: 'Live (Phase 5)' },
];

function lifecycleColor(l: string): string {
  switch (l) {
    case 'APPROVED': case 'PAPER_TRADING': case 'RISK_REVIEWED': return 'bg-violet-500/15 text-violet-300';
    case 'VALIDATED': case 'BACKTESTED': return 'bg-sky-500/15 text-sky-300';
    case 'RETIRED': return 'bg-rose-500/15 text-rose-400';
    default: return 'bg-slate-500/15 text-slate-400';
  }
}

export default function StrategyLab() {
  const [strategies, setStrategies] = useState<{ strategyId: string; latest: StrategyRecord }[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [detail, setDetail] = useState<StrategyRecord | null>(null);
  const [results, setResults] = useState<BacktestSummary[]>([]);
  const [active, setActive] = useState<BacktestResult | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [statusMsg, setStatusMsg] = useState<string | null>(null);

  // backtest form
  const [symbol, setSymbol] = useState('BTCUSDT');
  const [timeframe, setTimeframe] = useState('1h');
  const [limit, setLimit] = useState(2000);
  const [allowShorts, setAllowShorts] = useState(false);
  const [feeBps, setFeeBps] = useState(2);
  const [slippageBps, setSlippageBps] = useState(2);

  const loadStrategies = useCallback(async () => {
    try {
      const res = await api.strategies();
      setStrategies(res.strategies);
      if (!selected && res.strategies[0]) setSelected(res.strategies[0].strategyId);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'failed to load strategies');
    }
  }, [selected]);

  const loadResults = useCallback(async (strategyId?: string) => {
    try {
      const res = await api.backtestResults(strategyId);
      setResults(res.results);
      if (res.results[0] && !active) setActive(await api.backtestDetail(res.results[0].id));
    } catch {
      /* keep */
    }
  }, [active]);

  useEffect(() => { void loadStrategies(); }, [loadStrategies]);
  useEffect(() => {
    if (!selected) return;
    api.strategy(selected).then(setDetail).catch(() => setDetail(null));
    void loadResults(selected);
  }, [selected, loadResults]);

  const marketClass = useMemo(
    () => SYMBOLS.find((s) => s.symbol === symbol)?.marketClass ?? 'crypto',
    [symbol],
  );

  const runBacktest = async () => {
    if (!selected) return;
    setRunning(true);
    setError(null);
    setStatusMsg(null);
    try {
      const record = detail ?? (await api.strategy(selected));
      const result = await api.runBacktest({
        strategyId: selected,
        strategyVersion: record.version,
        symbol,
        marketClass,
        timeframe,
        limit,
        allowShorts,
        feeBps,
        slippageBps,
        spreadBps: feeBps > 0 ? 1 : 0,
      });
      setActive(result);
      await loadResults(selected);
      await loadStrategies();
      setStatusMsg(
        `Backtest complete: ${result.metrics.trades} trades, return ${result.metrics.totalReturnPct.toFixed(2)}%, max DD ${result.metrics.maxDrawdownPct.toFixed(1)}%`,
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'backtest failed');
    } finally {
      setRunning(false);
    }
  };

  const advance = async (to: string) => {
    if (!selected || !detail) return;
    setStatusMsg(null);
    setError(null);
    try {
      const res = await api.strategyStatus(selected, to, 'advanced from Strategy Lab');
      if (res.ok) {
        setStatusMsg(`Strategy advanced to ${to}`);
        setDetail(await api.strategy(selected));
        await loadStrategies();
      }
    } catch (e) {
      const msg = e instanceof Error ? e.message : 'transition rejected';
      setError(msg);
    }
  };

  return (
    <div className="mx-auto max-w-[1500px] space-y-3 px-4 py-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-lg font-bold text-white">Strategy Lab</h1>
          <p className="text-xs text-slate-500">
            Versioned strategies, evidence-gated lifecycle, backtesting with realistic costs — paper &amp; live stages arrive in Phases 3–5.
          </p>
        </div>
        {statusMsg && <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs text-emerald-300">{statusMsg}</div>}
        {error && <div className="max-w-xl rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-300">{error}</div>}
      </div>

      <div className="grid gap-3 xl:grid-cols-[320px_minmax(0,1fr)]">
        {/* strategy list */}
        <div className="space-y-2">
          {strategies.map(({ strategyId, latest }) => (
            <button
              key={strategyId}
              onClick={() => { setSelected(strategyId); setActive(null); }}
              className={`w-full rounded-xl border p-3 text-left transition ${selected === strategyId ? 'border-sky-500/60 bg-sky-500/10' : 'border-[#1d2333] bg-[#0b0e15] hover:border-slate-600'}`}
            >
              <div className="flex items-center gap-2">
                <span className="font-mono text-sm font-bold text-slate-200">{strategyId}</span>
                <span className="font-mono text-[10px] text-slate-500">v{latest.version}</span>
                <span className={`chip ml-auto ${lifecycleColor(latest.lifecycle)}`}>{latest.lifecycle}</span>
              </div>
              <div className="mt-1 text-[11px] leading-snug text-slate-500">{latest.name}</div>
            </button>
          ))}
          {strategies.length === 0 && <div className="panel p-4 text-xs text-slate-500">loading strategies…</div>}

          {detail && <LifecycleCard detail={detail} onAdvance={advance} />}
        </div>

        {/* main: runner + results */}
        <div className="space-y-3">
          <div className="panel p-4">
            <div className="mb-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Run a backtest</div>
            <div className="flex flex-wrap items-end gap-3">
              <label className="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-slate-500">
                Symbol
                <select value={symbol} onChange={(e) => setSymbol(e.target.value)} className="rounded-lg border border-[#2a3247] bg-[#0f131c] px-2 py-1.5 font-mono text-sm text-slate-100">
                  {SYMBOLS.map((s) => <option key={s.symbol}>{s.symbol}</option>)}
                </select>
              </label>
              <label className="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-slate-500">
                Timeframe
                <div className="flex rounded-lg border border-[#2a3247] bg-[#0f131c] p-0.5">
                  {TIMEFRAMES.map((tf) => (
                    <button key={tf} onClick={() => setTimeframe(tf)} className={`rounded px-2 py-1 font-mono text-xs ${timeframe === tf ? 'bg-sky-500/20 text-sky-300' : 'text-slate-500'}`}>{tf}</button>
                  ))}
                </div>
              </label>
              <label className="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-slate-500">
                Bars
                <input type="number" min={200} max={5000} step={100} value={limit} onChange={(e) => setLimit(Number(e.target.value))} className="w-24 rounded-lg border border-[#2a3247] bg-[#0f131c] px-2 py-1.5 font-mono text-sm text-slate-100" />
              </label>
              <label className="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-slate-500">
                Fee (bps/side)
                <input type="number" min={0} max={50} value={feeBps} onChange={(e) => setFeeBps(Number(e.target.value))} className="w-20 rounded-lg border border-[#2a3247] bg-[#0f131c] px-2 py-1.5 font-mono text-sm text-slate-100" />
              </label>
              <label className="flex flex-col gap-1 text-[10px] uppercase tracking-wider text-slate-500">
                Slippage (bps)
                <input type="number" min={0} max={50} value={slippageBps} onChange={(e) => setSlippageBps(Number(e.target.value))} className="w-20 rounded-lg border border-[#2a3247] bg-[#0f131c] px-2 py-1.5 font-mono text-sm text-slate-100" />
              </label>
              <label className="flex items-center gap-1.5 text-[11px] text-slate-400">
                <input type="checkbox" checked={allowShorts} onChange={(e) => setAllowShorts(e.target.checked)} className="accent-sky-500" />
                allow shorts
              </label>
              <button
                onClick={runBacktest}
                disabled={running || !selected}
                className="rounded-lg bg-gradient-to-r from-sky-600 to-indigo-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-50"
              >
                {running ? 'Simulating…' : '▶ Run backtest'}
              </button>
            </div>
            <p className="mt-2 text-[10px] leading-relaxed text-slate-600">
              Fills execute at the NEXT bar open after a signal (never the signal close), pay half-spread + slippage + commission,
              and stops are assumed to fill before targets when a bar touches both. Strategies cannot read future bars — the engine throws on look-ahead.
            </p>
          </div>

          {active && <BacktestView result={active} />}

          {results.length > 0 && (
            <div className="panel">
              <div className="panel-title">Backtest history — {selected}</div>
              <div className="scroll-thin max-h-72 overflow-auto px-4 pb-3">
                <table className="w-full text-[11px]">
                  <thead className="text-slate-500">
                    <tr className="text-left">
                      <th className="py-1 pr-2">Date</th><th className="pr-2">Symbol</th><th className="pr-2">TF</th>
                      <th className="pr-2 text-right">Trades</th><th className="pr-2 text-right">Return</th>
                      <th className="pr-2 text-right">Win%</th><th className="pr-2 text-right">PF</th>
                      <th className="pr-2 text-right">MaxDD</th><th className="pr-2 text-right">Sharpe</th><th />
                    </tr>
                  </thead>
                  <tbody className="font-mono">
                    {results.map((r) => (
                      <tr key={r.id} className={`border-t border-[#141926] ${active?.id === r.id ? 'bg-sky-500/5' : ''}`}>
                        <td className="py-1 pr-2 text-slate-400">{r.createdAt.slice(0, 16).replace('T', ' ')}</td>
                        <td className="pr-2 text-slate-300">{r.symbol}</td>
                        <td className="pr-2 text-slate-500">{r.timeframe}</td>
                        <td className="pr-2 text-right text-slate-300">{r.metrics.trades}</td>
                        <td className={`pr-2 text-right ${r.metrics.totalReturnPct >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{r.metrics.totalReturnPct.toFixed(2)}%</td>
                        <td className="pr-2 text-right text-slate-300">{r.metrics.winRate === null ? '—' : `${(r.metrics.winRate * 100).toFixed(0)}%`}</td>
                        <td className="pr-2 text-right text-slate-300">{r.metrics.profitFactor === null ? '—' : r.metrics.profitFactor.toFixed(2)}</td>
                        <td className="pr-2 text-right text-amber-400">{r.metrics.maxDrawdownPct.toFixed(1)}%</td>
                        <td className="pr-2 text-right text-slate-300">{r.metrics.sharpe === null ? '—' : r.metrics.sharpe.toFixed(2)}</td>
                        <td className="pr-1">
                          <button onClick={async () => setActive(await api.backtestDetail(r.id))} className="text-sky-400 hover:text-sky-300">open</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function LifecycleCard({ detail, onAdvance }: { detail: StrategyRecord; onAdvance: (to: string) => void }) {
  const stageIdx = LIFECYCLE_STAGES.findIndex((s) => s.key === detail.lifecycle);
  const next = detail.nextStage ?? null;
  return (
    <div className="panel p-3">
      <div className="mb-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Lifecycle</div>
      <div className="space-y-1">
        {LIFECYCLE_STAGES.map((s, i) => {
          const reached = stageIdx >= i && detail.lifecycle !== 'RETIRED';
          const isCurrent = detail.lifecycle === s.key;
          return (
            <div key={s.key} className="flex items-center gap-2 text-[11px]">
              <span className={`grid h-4 w-4 place-items-center rounded-full text-[9px] font-bold ${reached ? 'bg-sky-500/30 text-sky-300' : 'bg-[#141926] text-slate-600'}`}>
                {reached ? '✓' : i + 1}
              </span>
              <span className={isCurrent ? 'font-bold text-slate-100' : reached ? 'text-slate-400' : 'text-slate-600'}>{s.label}</span>
              {isCurrent && <span className={`chip ${lifecycleColor(detail.lifecycle)} !py-0`}>current</span>}
            </div>
          );
        })}
        {detail.lifecycle === 'RETIRED' && <div className="text-[11px] text-rose-400">RETIRED — terminal state</div>}
      </div>
      {next && (
        <button
          onClick={() => onAdvance(next)}
          className="mt-3 w-full rounded-lg border border-sky-500/40 bg-sky-500/10 px-2 py-1.5 text-[11px] font-semibold text-sky-300 hover:bg-sky-500/20"
        >
          Request advance → {next}
        </button>
      )}
      {next === 'PAPER_TRADING' && <div className="mt-1.5 text-[10px] text-amber-400/80">Gate check will refuse until Phase 3 (honest 409).</div>}
      <div className="mt-2 border-t border-[#1d2333] pt-2 text-[10px] text-slate-600">
        Params: {Object.entries(detail.params).map(([k, v]) => `${k}=${v}`).join(', ') || 'defaults'}
      </div>
    </div>
  );
}

function BacktestView({ result }: { result: BacktestResult }) {
  const [showTrades, setShowTrades] = useState(false);
  const m = result.metrics;
  const stats: { label: string; value: string; tone?: string }[] = [
    { label: 'Total return', value: `${m.totalReturnPct.toFixed(2)}%`, tone: m.totalReturnPct >= 0 ? 'text-emerald-400' : 'text-rose-400' },
    { label: 'Final equity', value: `$${m.finalEquity.toLocaleString()}` },
    { label: 'Trades', value: String(m.trades) },
    { label: 'Win rate', value: m.winRate === null ? '—' : `${(m.winRate * 100).toFixed(1)}%` },
    { label: 'Profit factor', value: m.profitFactor === null ? '—' : m.profitFactor.toFixed(2) },
    { label: 'Expectancy', value: m.expectancyR === null ? '—' : `${m.expectancyR.toFixed(2)}R` },
    { label: 'Sharpe', value: m.sharpe === null ? '—' : m.sharpe.toFixed(2) },
    { label: 'Sortino', value: m.sortino === null ? '—' : m.sortino.toFixed(2) },
    { label: 'Max drawdown', value: `${m.maxDrawdownPct.toFixed(1)}%`, tone: 'text-amber-400' },
    { label: 'Avg win / loss', value: `${m.avgWin === null ? '—' : `$${m.avgWin.toFixed(0)}`} / ${m.avgLoss === null ? '—' : `$${m.avgLoss.toFixed(0)}`}` },
    { label: 'Exposure', value: `${m.exposurePct.toFixed(0)}%` },
    { label: 'Costs', value: `$${m.totalFees.toFixed(0)} fees + $${m.totalSlippage.toFixed(0)} slip`, tone: 'text-slate-400' },
  ];

  return (
    <div className="space-y-3">
      <div className={`rounded-xl border p-3 ${result.dataProvenance.synthetic ? 'border-amber-500/40 bg-amber-500/10' : 'border-[#1d2333] bg-[#0b0e15]'}`}>
        <div className="flex flex-wrap items-center gap-3 text-xs">
          {result.dataProvenance.synthetic && <span className="chip bg-amber-500 font-black text-amber-950">⚠ SIMULATION / SYNTHETIC DATA</span>}
          <span className="text-slate-400">{result.request.strategyId}@{result.request.strategyVersion}</span>
          <span className="font-mono text-slate-200">{result.request.symbol} · {result.request.timeframe}</span>
          <span className="text-slate-500">{result.dataProvenance.candles} bars · {result.dataProvenance.from.slice(0, 10)} → {result.dataProvenance.to.slice(0, 10)}</span>
          <span className="text-slate-500">costs: {result.request.feeBps}bps fee / {result.request.spreadBps}bps spread / {result.request.slippageBps}bps slip{result.request.allowShorts ? ' · shorts on' : ''}</span>
        </div>
        {result.warnings.length > 0 && (
          <ul className="mt-1.5 space-y-0.5">
            {result.warnings.slice(0, 3).map((w, i) => <li key={i} className="text-[11px] text-amber-300/80">⚠ {w}</li>)}
          </ul>
        )}
      </div>

      <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
        {stats.map((s) => (
          <div key={s.label} className="panel p-2.5">
            <div className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-500">{s.label}</div>
            <div className={`mt-0.5 font-mono text-sm font-bold ${s.tone ?? 'text-slate-200'}`}>{s.value}</div>
          </div>
        ))}
      </div>

      <EquityCurve result={result} />

      <div className="panel">
        <button onClick={() => setShowTrades(!showTrades)} className="w-full px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400 hover:text-slate-200">
          {showTrades ? '▾' : '▸'} Trade-by-trade journal ({result.trades.length} trades)
        </button>
        {showTrades && (
          <div className="scroll-thin max-h-80 overflow-auto px-4 pb-3">
            <table className="w-full text-[11px]">
              <thead className="text-slate-500"><tr className="text-left">
                <th className="py-1 pr-2">#</th><th className="pr-2">Dir</th><th className="pr-2">Entry time</th>
                <th className="pr-2 text-right">Entry</th><th className="pr-2 text-right">Exit</th>
                <th className="pr-2 text-right">R</th><th className="pr-2 text-right">Net P&L</th>
                <th className="pr-2">Exit reason</th><th className="pr-2 text-right">Conf</th><th className="pr-2">Signal</th>
              </tr></thead>
              <tbody className="font-mono">
                {result.trades.map((t, i) => (
                  <tr key={i} className="border-t border-[#141926]">
                    <td className="py-1 pr-2 text-slate-500">{i + 1}</td>
                    <td className={`pr-2 font-bold ${t.direction === 'LONG' ? 'text-emerald-400' : 'text-rose-400'}`}>{t.direction === 'LONG' ? '▲' : '▼'}</td>
                    <td className="pr-2 text-slate-400">{t.entryTime.slice(0, 16).replace('T', ' ')}</td>
                    <td className="pr-2 text-right text-slate-300">{formatPrice(t.entryPrice)}</td>
                    <td className="pr-2 text-right text-slate-300">{formatPrice(t.exitPrice)}</td>
                    <td className={`pr-2 text-right ${t.rMultiple >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{t.rMultiple.toFixed(2)}</td>
                    <td className={`pr-2 text-right ${t.netPnl >= 0 ? 'text-emerald-400' : 'text-rose-400'}`}>{t.netPnl.toFixed(1)}</td>
                    <td className="pr-2 text-slate-500">{t.exitReason}</td>
                    <td className="pr-2 text-right text-slate-400">{(t.confidence * 100).toFixed(0)}%</td>
                    <td className="pr-2 max-w-[220px] truncate text-slate-500" title={t.signalReason}>{t.signalReason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function EquityCurve({ result }: { result: BacktestResult }) {
  const curve = result.equityCurve;
  const W = 920, H = 240, PAD = 10, PAD_R = 60;
  if (curve.length < 2) return null;
  const eqs = curve.map((p) => p.equity);
  const lo = Math.min(...eqs), hi = Math.max(...eqs);
  const x = (i: number) => PAD + (i / (curve.length - 1)) * (W - PAD - PAD_R);
  const y = (v: number) => PAD + (1 - (v - lo) / Math.max(1e-9, hi - lo)) * (H - 2 * PAD);
  const points = curve.map((p, i) => `${x(i).toFixed(1)},${y(p.equity).toFixed(1)}`).join(' ');
  const maxDd = Math.max(...curve.map((p) => p.drawdownPct));

  return (
    <div className="panel">
      <div className="panel-title">Equity curve (mark-to-market, net of costs)</div>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full">
        {[0, 0.25, 0.5, 0.75, 1].map((f) => {
          const v = lo + (hi - lo) * f;
          return (
            <g key={f}>
              <line x1={PAD} x2={W - PAD_R} y1={y(v)} y2={y(v)} stroke="#141926" />
              <text x={W - PAD_R + 6} y={y(v) + 3.5} fontSize="10" fill="#5b6478" className="font-mono">${Math.round(v).toLocaleString()}</text>
            </g>
          );
        })}
        <polyline points={points} fill="none" stroke="#38bdf8" strokeWidth="1.6" />
      </svg>
      <div className="flex justify-between px-4 pb-2 text-[10px] text-slate-500">
        <span>{curve[0].time.slice(0, 10)}</span>
        <span>peak drawdown {maxDd.toFixed(1)}%</span>
        <span>{curve[curve.length - 1].time.slice(0, 10)}</span>
      </div>
    </div>
  );
}

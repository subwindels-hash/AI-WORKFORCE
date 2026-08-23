'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import CandleChart from '@/components/ChartCard';
import {
  AgentGrid, BiasPanel, EventLog, HeaderBar, HistoryList, ProvenanceBanner,
  RiskPanel, ScenarioPanel, SetupPanel, StatusMatrix, WatchStrip,
} from '@/components/panels';
import { api } from '@/lib/api';
import type { AnalysisRun, AuditEvent, Candle, ConsensusSummary, DataProvenance, IntegrationStatusEntry, RiskLimitsView, SystemStatus } from '@/lib/types';

const SYMBOLS: { symbol: string; marketClass: string }[] = [
  { symbol: 'BTCUSDT', marketClass: 'crypto' },
  { symbol: 'ETHUSDT', marketClass: 'crypto' },
  { symbol: 'SOLUSDT', marketClass: 'crypto' },
  { symbol: 'BNBUSDT', marketClass: 'crypto' },
  { symbol: 'XRPUSDT', marketClass: 'crypto' },
  { symbol: 'EURUSD', marketClass: 'forex' },
  { symbol: 'GBPUSD', marketClass: 'forex' },
  { symbol: 'USDJPY', marketClass: 'forex' },
  { symbol: 'AUDUSD', marketClass: 'forex' },
  { symbol: 'USDCAD', marketClass: 'forex' },
  { symbol: 'XAUUSD', marketClass: 'forex' },
];
const TIMEFRAMES = ['15m', '1h', '4h', '1d'] as const;
const WATCH = ['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'BTCUSDT', 'ETHUSDT', 'SOLUSDT'];

export default function Dashboard() {
  const [symbol, setSymbol] = useState('BTCUSDT');
  const [timeframe, setTimeframe] = useState<string>('1h');
  const [run, setRun] = useState<AnalysisRun | null>(null);
  const [candles, setCandles] = useState<Candle[]>([]);
  const [status, setStatus] = useState<SystemStatus | null>(null);
  const [integrations, setIntegrations] = useState<IntegrationStatusEntry[]>([]);
  const [watch, setWatch] = useState<ConsensusSummary[]>([]);
  const [watchLoading, setWatchLoading] = useState(true);
  const [riskView, setRiskView] = useState<RiskLimitsView | null>(null);
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [history, setHistory] = useState<{ id: string; symbol: string; timeframe: string; bias: string; confidence: number; regime: string; synthetic: boolean; completedAt: string }[]>([]);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const runSeq = useRef(0);

  const marketClass = useMemo(
    () => SYMBOLS.find((s) => s.symbol === symbol)?.marketClass ?? 'forex',
    [symbol],
  );

  const loadAux = useCallback(async () => {
    try {
      const [st, ig, ev, hi, rv] = await Promise.all([
        api.systemStatus(), api.integrations(), api.events(), api.history(), api.riskLimits(),
      ]);
      setStatus(st); setIntegrations(ig); setEvents(ev.events); setHistory(hi.runs); setRiskView(rv);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'aux load failed');
    }
  }, []);

  const loadWatch = useCallback(async (tf: string) => {
    setWatchLoading(true);
    try {
      const res = await api.consensus(WATCH, tf);
      setWatch(res.consensus);
    } catch {
      /* keep previous */
    } finally {
      setWatchLoading(false);
    }
  }, []);

  const runAnalysis = useCallback(async (sym: string, mc: string, tf: string) => {
    const seq = ++runSeq.current;
    setRunning(true);
    setError(null);
    try {
      const [analysis, candleRes] = await Promise.all([
        api.runAnalysis(sym, mc, tf),
        fetch(`/api/market-data/candles?symbol=${sym}&timeframe=${tf}&limit=200&marketClass=${mc}`).then((r) => r.json()),
      ]);
      if (seq !== runSeq.current) return; // stale response — a newer request superseded it
      setRun(analysis);
      setCandles(candleRes.candles ?? []);
      loadAux();
    } catch (e) {
      if (seq === runSeq.current) setError(e instanceof Error ? e.message : 'analysis failed');
    } finally {
      if (seq === runSeq.current) setRunning(false);
    }
  }, [loadAux]);

  useEffect(() => {
    runAnalysis(symbol, marketClass, timeframe);
  }, [symbol, timeframe, marketClass, runAnalysis]);

  useEffect(() => {
    loadWatch(timeframe === '15m' ? '15m' : timeframe);
  }, [timeframe, loadWatch]);

  useEffect(() => {
    const id = setInterval(() => {
      loadAux();
      loadWatch(timeframe);
    }, 60_000);
    return () => clearInterval(id);
  }, [loadAux, loadWatch, timeframe]);

  const onKillSwitch = async (active: boolean) => {
    try {
      await api.killSwitch(active, active ? 'Engaged from dashboard' : 'Released from dashboard');
      loadAux();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'kill switch failed');
    }
  };

  const technical = run?.agents.find((a) => a.agent === 'technical') as
    | (AnalysisRun['agents'][number] & { structure?: { support: number[]; resistance: number[] } })
    | undefined;

  const openRun = async (id: string) => {
    try {
      const full = await fetch(`/api/analysis/${id}`).then((r) => r.json());
      if (full?.request?.symbol) {
        setSymbol(full.request.symbol);
        setTimeframe(full.request.timeframe);
      }
    } catch { /* noop */ }
  };

  return (
    <div className="min-h-screen">
      <HeaderBar status={status} onKillSwitch={onKillSwitch} />

      <WatchStrip items={watch} selected={symbol} onSelect={setSymbol} loading={watchLoading} />

      <main className="mx-auto max-w-[1500px] space-y-3 px-4 pb-10">
        {/* controls */}
        <div className="panel flex flex-wrap items-center gap-3 p-3">
          <div className="flex items-center gap-2">
            <span className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Symbol</span>
            <select
              value={symbol}
              onChange={(e) => setSymbol(e.target.value)}
              className="rounded-lg border border-[#2a3247] bg-[#0f131c] px-2.5 py-1.5 font-mono text-sm font-bold text-slate-100 outline-none focus:border-sky-500/60"
            >
              <optgroup label="Crypto">
                {SYMBOLS.filter((s) => s.marketClass === 'crypto').map((s) => <option key={s.symbol} value={s.symbol}>{s.symbol}</option>)}
              </optgroup>
              <optgroup label="Forex & Metals">
                {SYMBOLS.filter((s) => s.marketClass === 'forex').map((s) => <option key={s.symbol} value={s.symbol}>{s.symbol}</option>)}
              </optgroup>
            </select>
          </div>

          <div className="flex items-center gap-1 rounded-lg border border-[#2a3247] bg-[#0f131c] p-0.5">
            {TIMEFRAMES.map((tf) => (
              <button
                key={tf}
                onClick={() => setTimeframe(tf)}
                className={`rounded px-2.5 py-1 font-mono text-xs font-semibold ${timeframe === tf ? 'bg-sky-500/20 text-sky-300' : 'text-slate-500 hover:text-slate-300'}`}
              >
                {tf}
              </button>
            ))}
          </div>

          <button
            onClick={() => runAnalysis(symbol, marketClass, timeframe)}
            disabled={running}
            className="rounded-lg bg-gradient-to-r from-sky-600 to-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow disabled:opacity-50"
          >
            {running ? 'Running agents…' : '▶ Run analysis'}
          </button>

          <div className="ml-auto flex items-center gap-2 text-[11px] text-slate-500">
            <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
            auto-refresh 60s
            <span className="font-mono text-slate-600">{status ? new Date(status.time).toISOString().slice(11, 19) + 'Z' : ''}</span>
          </div>
        </div>

        {error && (
          <div className="rounded-xl border border-rose-500/40 bg-rose-500/10 p-3 text-xs text-rose-300">
            {error} — check that the AEGIS API service is running.
          </div>
        )}

        {run && <ProvenanceBanner provenance={run.provenance as DataProvenance} />}

        <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_360px]">
          {/* main column */}
          <div className="space-y-3">
            {run && <BiasPanel run={run} />}

            <div className="panel">
              <div className="panel-title">
                {symbol} · {timeframe} — candles, EMA, structure &amp; setup overlay
              </div>
              {candles.length > 0 ? (
                <CandleChart
                  candles={candles}
                  support={technical?.structure?.support ?? []}
                  resistance={technical?.structure?.resistance ?? []}
                  setup={run?.tradeSetup ?? null}
                />
              ) : (
                <div className="p-6 text-center text-xs text-slate-600">
                  {running ? 'loading candles…' : 'select a symbol and run the analysis'}
                </div>
              )}
            </div>

            {run && <ScenarioPanel scenarios={run.scenarios} bias={run.bias} />}

            {run && (
              <div>
                <div className="mb-2 mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                  AI Agents — {run.agents.length} reporting · {run.consensus.votingAgents.length} voting · {run.consensus.abstainingAgents.length} abstaining
                </div>
                <AgentGrid agents={run.agents} />
              </div>
            )}
          </div>

          {/* side column */}
          <div className="space-y-3">
            {run && <SetupPanel setup={run.tradeSetup} risk={run.riskDecision} />}
            <RiskPanel view={riskView} />
            <StatusMatrix entries={integrations} />
            <HistoryList runs={history} onOpen={openRun} />
            <EventLog events={events} />
          </div>
        </div>

        <footer className="pt-2 text-center text-[10px] leading-relaxed text-slate-600">
          AEGIS Phase 1 · ANALYSIS_ONLY — this platform generates analysis and structured trade proposals with mandatory
          risk review. It places no orders. Synthetic demo data is always labeled. Nothing here is investment advice.
        </footer>
      </main>
    </div>
  );
}

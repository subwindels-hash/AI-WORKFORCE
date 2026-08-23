'use client';

import { useState } from 'react';
import type {
  AgentReport, AnalysisRun, AuditEvent, ConsensusSummary, DataProvenance,
  IntegrationStatusEntry, RiskDecision, RiskLimitsView, Scenario, SystemStatus, TradeSetup,
} from '@/lib/types';
import { formatAge, formatPrice } from '@/lib/api';

export function biasColor(bias: string): string {
  switch (bias) {
    case 'BULLISH': return 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30';
    case 'BEARISH': return 'bg-rose-500/15 text-rose-400 border border-rose-500/30';
    case 'NEUTRAL': return 'bg-slate-500/15 text-slate-300 border border-slate-500/30';
    default: return 'bg-amber-500/15 text-amber-400 border border-amber-500/30';
  }
}

export function statusColor(status: string): string {
  switch (status) {
    case 'TESTED':
    case 'IMPLEMENTED':
    case 'LIVE_READY':
    case 'PAPER_TRADING_READY':
    case 'UP': return 'bg-emerald-500/15 text-emerald-400';
    case 'PLANNED': return 'bg-sky-500/15 text-sky-400';
    case 'DISABLED': case 'DOWN': return 'bg-rose-500/15 text-rose-400';
    case 'DEGRADED': return 'bg-amber-500/15 text-amber-400';
    default: return 'bg-slate-500/15 text-slate-400';
  }
}

export { default as Nav } from './nav';

// ----------------------------------------------------------------- header --

export function HeaderBar({ status, onKillSwitch }: { status: SystemStatus | null; onKillSwitch: (active: boolean) => void }) {
  const [confirmKill, setConfirmKill] = useState(false);
  const ks = status?.killSwitch;
  return (
    <header className="sticky top-0 z-20 border-b border-[#1d2333] bg-[#07090e]/95 backdrop-blur">
      <div className="mx-auto flex max-w-[1500px] flex-wrap items-center gap-3 px-4 py-2.5">
        <div className="flex items-center gap-2.5">
          <div className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-sky-500 to-indigo-600 font-black text-white">Æ</div>
          <div>
            <div className="text-sm font-bold tracking-wide text-white">AEGIS <span className="font-normal text-slate-400">· AI Trading Intelligence</span></div>
            <div className="text-[10px] uppercase tracking-[0.18em] text-slate-500">Phase 1 · Analysis-only vertical slice</div>
          </div>
        </div>

        <div className="ml-auto flex flex-wrap items-center gap-2">
          <span className="chip bg-indigo-500/15 text-indigo-300 border border-indigo-500/30" title="Only ANALYSIS_ONLY is implemented in Phase 1 — the API refuses other modes">
            MODE: {status?.tradingMode ?? '…'}
          </span>
          {ks?.active ? (
            <button
              onClick={() => setConfirmKill(true)}
              className="chip animate-pulse border border-rose-500/50 bg-rose-500/20 text-rose-300"
              title="All trade proposals are vetoed while active"
            >
              ⛔ KILL SWITCH ACTIVE
            </button>
          ) : (
            <button
              onClick={() => onKillSwitch(true)}
              className="chip border border-rose-500/30 bg-rose-500/10 text-rose-400 hover:bg-rose-500/20"
              title="Immediately veto every trade proposal"
            >
              Activate kill switch
            </button>
          )}
          <div className="flex items-center gap-1.5" title="Market-data providers">
            {(status?.providers ?? []).map((p) => (
              <span
                key={p.name}
                className={`h-2.5 w-2.5 rounded-full ${p.status === 'UP' ? (p.synthetic ? 'bg-amber-400' : 'bg-emerald-400') : 'bg-rose-500'}`}
                title={`${p.name}: ${p.status}${p.synthetic ? ' (SYNTHETIC)' : ''}${p.lastError ? ` — ${p.lastError}` : ''}`}
              />
            ))}
          </div>
        </div>
      </div>

      {confirmKill && (
        <div className="border-t border-rose-500/30 bg-rose-950/40 px-4 py-2 text-xs text-rose-200">
          Kill switch is already ACTIVE — every trade proposal is vetoed. Release it?
          <button className="ml-3 rounded bg-rose-500/30 px-2 py-0.5 font-semibold hover:bg-rose-500/50" onClick={() => { onKillSwitch(false); setConfirmKill(false); }}>Release</button>
          <button className="ml-2 rounded bg-slate-700/50 px-2 py-0.5 hover:bg-slate-700" onClick={() => setConfirmKill(false)}>Keep active</button>
        </div>
      )}
    </header>
  );
}

// --------------------------------------------------------------- watchlist --

export function WatchStrip({ items, selected, onSelect, loading }: {
  items: ConsensusSummary[];
  selected: string;
  onSelect: (s: string) => void;
  loading: boolean;
}) {
  return (
    <div className="flex items-center gap-2 overflow-x-auto scroll-thin px-4 py-2">
      <span className="shrink-0 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Watchlist · 1h consensus</span>
      {loading && <span className="text-xs text-slate-500">running agents…</span>}
      {items.map((c) => (
        <button
          key={c.symbol}
          onClick={() => onSelect(c.symbol)}
          className={`shrink-0 rounded-lg border px-2.5 py-1 text-left transition ${selected === c.symbol ? 'border-sky-500/60 bg-sky-500/10' : 'border-[#1d2333] bg-[#0b0e15] hover:border-slate-600'}`}
        >
          <div className="flex items-center gap-2">
            <span className="font-mono text-xs font-bold text-slate-200">{c.symbol}</span>
            <span className={`chip ${biasColor(c.bias)} !px-1.5 !py-0`}>{c.bias.slice(0, 4)}</span>
            {c.synthetic && <span className="chip bg-amber-500/15 text-amber-400 !px-1.5 !py-0" title="Analysis built on synthetic demo data">SIM</span>}
          </div>
          <div className="mt-0.5 flex gap-2 text-[10px] text-slate-500">
            <span>conf {(c.confidence * 100).toFixed(0)}%</span>
            <span>{c.regime.replace('_', ' ').toLowerCase()}</span>
          </div>
        </button>
      ))}
    </div>
  );
}

// -------------------------------------------------------------- provenance --

export function ProvenanceBanner({ provenance }: { provenance: DataProvenance }) {
  const p = provenance;
  return (
    <div className={`rounded-xl border p-3 ${p.synthetic ? 'border-amber-500/40 bg-amber-500/10' : p.stale ? 'border-rose-500/40 bg-rose-500/10' : 'border-[#1d2333] bg-[#0b0e15]'}`}>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
        {p.synthetic && <span className="chip bg-amber-500 text-amber-950 font-black">⚠ SIMULATION / SYNTHETIC DATA</span>}
        {p.stale && !p.synthetic && <span className="chip bg-rose-500/20 text-rose-300 border border-rose-500/40">DATA STALE</span>}
        {p.delayed && !p.synthetic && <span className="chip bg-sky-500/15 text-sky-300">DELAYED FEED</span>}
        {p.live && !p.synthetic && !p.delayed && <span className="chip bg-emerald-500/15 text-emerald-400">LIVE DATA</span>}
        <span className="text-slate-400">Source: <b className="font-mono text-slate-200">{p.source}</b></span>
        <span className="text-slate-400">Data time: <b className="font-mono text-slate-200">{new Date(p.dataTimestamp).toISOString().replace('T', ' ').slice(0, 19)}Z</b></span>
        <span className="text-slate-400">Age: <b className="font-mono text-slate-200">{formatAge(p.dataAgeMs)}</b></span>
        {p.fallbackChain.length > 0 && (
          <span className="text-amber-400/90" title={`Providers that failed: ${p.fallbackChain.join(', ')}`}>
            fallback: {p.fallbackChain.join(' → ')} → {p.source}
          </span>
        )}
      </div>
      {p.synthetic && (
        <p className="mt-1.5 text-[11px] leading-relaxed text-amber-300/80">
          Real market-data providers (Binance, Frankfurter/ECB) are unreachable from this host, so the labeled synthetic
          demo provider is serving this analysis. The Risk Engine vetoes every trade proposal built on synthetic data.
        </p>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- verdict --

export function BiasPanel({ run }: { run: AnalysisRun }) {
  const pct = (v: number) => `${Math.round(v * 100)}%`;
  return (
    <div className="panel">
      <div className="panel-title">Trading Intelligence Consensus</div>
      <div className="grid gap-4 px-4 pb-4 sm:grid-cols-[minmax(0,1fr)_220px]">
        <div>
          <div className="flex flex-wrap items-center gap-3">
            <span className={`rounded-xl px-4 py-2 text-2xl font-black tracking-wide ${biasColor(run.bias)}`}>{run.bias}</span>
            <div className="text-xs text-slate-400">
              <div>Recommendation <b className={run.recommendation === 'BUY' ? 'text-emerald-400' : run.recommendation === 'SELL' ? 'text-rose-400' : 'text-slate-200'}>{run.recommendation}</b></div>
              <div className="mt-0.5">Regime <b className="font-mono text-slate-200">{run.marketRegime}</b> · ADX {run.regimeAssessment.adx ?? '—'} · vol {run.regimeAssessment.volatilityPct ?? '—'}%</div>
            </div>
            {run.quote && (
              <div className="ml-auto text-right">
                <div className="font-mono text-lg font-bold text-white">{formatPrice(run.quote.last)}</div>
                <div className="text-[10px] text-slate-500">last quote</div>
              </div>
            )}
          </div>

          <div className="mt-4 space-y-2.5">
            <Meter label="Confidence" value={run.confidence} color="bg-sky-500" />
            <Meter label="Confluence" value={run.confluence} color="bg-violet-500" />
            <Meter label="Agent agreement" value={run.consensus.agreement} color="bg-emerald-500" />
            <div className="flex justify-between text-[11px] text-slate-500">
              <span>net score <b className="font-mono">{run.consensus.netScore.toFixed(2)}</b></span>
              <span>voting: {run.consensus.votingAgents.join(', ') || '—'}</span>
              {run.consensus.abstainingAgents.length > 0 && <span>abstaining: {run.consensus.abstainingAgents.join(', ')}</span>}
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-[#1d2333] bg-[#0f131c] p-3">
          <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Regime evidence</div>
          <ul className="space-y-1 text-[11px] leading-relaxed text-slate-400">
            {run.regimeAssessment.evidence.map((e, i) => <li key={i} className="flex gap-1.5"><span className="text-slate-600">▸</span>{e}</li>)}
          </ul>
          {run.conflicts.length > 0 && (
            <>
              <div className="mb-1 mt-2.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-500">Signal conflicts</div>
              {run.conflicts.map((c, i) => (
                <div key={i} className="text-[11px] text-amber-300/90">{c.agent} leans {c.theirBias} — {c.reason}</div>
              ))}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export function Meter({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div>
      <div className="mb-1 flex justify-between text-[11px] text-slate-400">
        <span>{label}</span><span className="font-mono">{Math.round(value * 100)}%</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded bg-[#141926]">
        <div className={`h-full rounded ${color}`} style={{ width: `${Math.min(100, value * 100)}%` }} />
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ agents --

const agentIcon: Record<string, string> = {
  technical: '📐', 'market-structure': '🏗', forex: '💱', crypto: '₿', sentiment: '📻',
};

export function AgentGrid({ agents }: { agents: AgentReport[] }) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      {agents.map((a) => <AgentCard key={a.agent} agent={a} />)}
    </div>
  );
}

function AgentCard({ agent }: { agent: AgentReport }) {
  const [open, setOpen] = useState(false);
  const vote = agent.vote;
  return (
    <div className="panel flex flex-col">
      <div className="flex items-center gap-2 px-4 pt-3">
        <span className="text-lg">{agentIcon[agent.agent] ?? '🤖'}</span>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold text-slate-200">{agent.title}</div>
          <div className="text-[10px] text-slate-500">data quality {Math.round(agent.dataQuality * 100)}%</div>
        </div>
        <span className={`chip ${biasColor(vote.directionalScore > 0.15 ? 'BULLISH' : vote.directionalScore < -0.15 ? 'BEARISH' : 'NEUTRAL')}`}>
          {vote.votes ? (vote.signal === 'BUY' ? '▲ BULL' : vote.signal === 'SELL' ? '▼ BEAR' : 'NEUTRAL') : 'ABSTAIN'}
        </span>
      </div>
      <div className="px-4 py-2 text-xs text-slate-400">{vote.reason}</div>

      {agent.agent === 'technical' && <TechnicalBody agent={agent as Extract<AgentReport, { agent: 'technical' }> & AgentReport} />}
      {agent.agent === 'market-structure' && <StructureBody agent={agent} />}
      {agent.agent === 'forex' && <ForexBody agent={agent} />}
      {agent.agent === 'crypto' && <CryptoBody agent={agent} />}
      {agent.agent === 'sentiment' && (
        <div className="mx-4 mb-3 rounded-lg border border-slate-700 bg-[#0f131c] p-2.5 text-[11px] text-slate-400">
          <b className="text-slate-300">Unavailable:</b> no news or social providers configured (Phase 6). This agent
          abstains from the consensus vote — price/volume proxies are handled by the Technical Agent and are never
          presented as sentiment.
        </div>
      )}

      {(agent.dataLimitations.length > 0 || agent.warnings.length > 0) && (
        <div className="mx-4 mb-3 space-y-1 rounded-lg border border-amber-500/20 bg-amber-500/5 p-2.5">
          {agent.warnings.map((w, i) => <div key={`w${i}`} className="text-[11px] text-amber-300/90">⚠ {w}</div>)}
          {agent.dataLimitations.slice(0, 3).map((l, i) => <div key={`l${i}`} className="text-[11px] text-slate-500">· {l}</div>)}
        </div>
      )}

      <button className="mt-auto px-4 pb-3 text-left text-[11px] text-sky-400 hover:text-sky-300" onClick={() => setOpen(!open)}>
        {open ? '▾ hide' : '▸ show'} raw agent JSON
      </button>
      {open && (
        <pre className="scroll-thin mx-4 mb-3 max-h-72 overflow-auto rounded-lg bg-[#07090e] p-2.5 text-[10px] leading-relaxed text-slate-400">
          {JSON.stringify(agent, null, 2)}
        </pre>
      )}
    </div>
  );
}

function TechnicalBody({ agent }: { agent: Record<string, unknown> & { indicators?: Record<string, unknown>; signals?: { name: string; value: number | null; signal: string; detail: string }[] } }) {
  const sigs = agent.signals ?? [];
  return (
    <div className="mx-4 mb-3 overflow-hidden rounded-lg border border-[#1d2333]">
      <table className="w-full text-[11px]">
        <tbody>
          {sigs.map((s) => (
            <tr key={s.name} className="border-b border-[#141926] last:border-0">
              <td className="px-2.5 py-1 text-slate-400">{s.name}</td>
              <td className="px-2 py-1 text-right font-mono text-slate-300">{s.value !== null ? (typeof s.value === 'number' ? s.value.toFixed(2) : s.value) : '—'}</td>
              <td className={`px-2.5 py-1 text-right font-semibold ${s.signal === 'BUY' ? 'text-emerald-400' : s.signal === 'SELL' ? 'text-rose-400' : 'text-slate-500'}`}>{s.signal}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function StructureBody({ agent }: { agent: Record<string, unknown> }) {
  const events = agent.events as { breakOfStructure: { detected: boolean; direction: string; confirmedBy: string }; changeOfCharacter: { detected: boolean; direction: string; confirmedBy: string } };
  const counts = agent.swingSequence as string[];
  return (
    <div className="mx-4 mb-3 space-y-1.5 rounded-lg border border-[#1d2333] bg-[#0f131c] p-2.5 text-[11px]">
      <div className="flex gap-2">
        <span className="text-slate-500">Swings:</span>
        <span className="font-mono text-slate-300">{counts?.join(' ') || '—'}</span>
        <span className="ml-auto text-slate-400">{String(agent.trendLabel)}</span>
      </div>
      <div className="flex gap-2">
        <span className="text-slate-500">BOS:</span>
        <span className={events?.breakOfStructure?.confirmedBy === 'CLOSE' ? 'text-emerald-400' : 'text-amber-400'}>
          {events?.breakOfStructure?.detected ? `${events.breakOfStructure.direction} (${events.breakOfStructure.confirmedBy}-confirmed)` : 'none'}
        </span>
        <span className="ml-3 text-slate-500">CHoCH:</span>
        <span className={events?.changeOfCharacter?.detected ? 'text-violet-400' : 'text-slate-500'}>
          {events?.changeOfCharacter?.detected ? `${events.changeOfCharacter.direction}` : 'none'}
        </span>
      </div>
      {events?.breakOfStructure?.confirmedBy === 'WICK' && (
        <div className="text-amber-400">⚠ wick-only break — NOT treated as confirmation (close-based rule)</div>
      )}
    </div>
  );
}

function ForexBody({ agent }: { agent: Record<string, unknown> }) {
  const strength = agent.currencyStrength as { scores: { currency: string; score: number }[]; strongest: string | null; weakest: string | null; note: string } | undefined;
  const session = agent.session as { name: string; active: boolean } | undefined;
  const macro = agent.macro as { available: boolean; reason: string } | undefined;
  return (
    <div className="mx-4 mb-3 space-y-2 rounded-lg border border-[#1d2333] bg-[#0f131c] p-2.5 text-[11px]">
      <div className="flex flex-wrap gap-1.5">
        {(strength?.scores ?? []).slice(0, 8).map((s) => (
          <span key={s.currency} className={`chip ${s.score > 0 ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400'} !px-1.5`}>
            {s.currency} {s.score >= 0 ? '+' : ''}{(s.score * 100).toFixed(2)}%
          </span>
        ))}
      </div>
      <div className="text-slate-500">{strength?.note}</div>
      <div className="text-slate-400">Session: <b className="text-slate-300">{session?.name}</b>{session?.active ? '' : ' (thin liquidity)'}</div>
      {macro && !macro.available && <div className="rounded bg-amber-500/5 px-2 py-1 text-amber-300/80">_macro data unavailable_ — {macro.reason}</div>}
    </div>
  );
}

function CryptoBody({ agent }: { agent: Record<string, unknown> }) {
  const pa = agent.priceAction as { changePct24h: number | null; changePct7d: number | null; trendLabel: string } | undefined;
  const onChain = agent.onChain as { dataAvailable: boolean; warning: string } | undefined;
  return (
    <div className="mx-4 mb-3 space-y-1.5 rounded-lg border border-[#1d2333] bg-[#0f131c] p-2.5 text-[11px]">
      <div className="flex gap-4 text-slate-400">
        <span>24h <b className={((pa?.changePct24h ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400')}>{pa?.changePct24h ?? '—'}%</b></span>
        <span>7d <b className={((pa?.changePct7d ?? 0) >= 0 ? 'text-emerald-400' : 'text-rose-400')}>{pa?.changePct7d ?? '—'}%</b></span>
        <span className="text-slate-500">{pa?.trendLabel}</span>
      </div>
      {onChain && !onChain.dataAvailable && (
        <pre className="rounded bg-amber-500/5 px-2 py-1 text-[10px] text-amber-300/80">{JSON.stringify(onChain, null, 0)}</pre>
      )}
    </div>
  );
}

// --------------------------------------------------------------- scenarios --

export function ScenarioPanel({ scenarios, bias }: { scenarios: { bullish: Scenario; bearish: Scenario; neutral: Scenario }; bias: string }) {
  const cols: { key: string; s: Scenario; tone: string }[] = [
    { key: 'Bullish', s: scenarios.bullish, tone: 'border-emerald-500/30' },
    { key: 'Bearish', s: scenarios.bearish, tone: 'border-rose-500/30' },
    { key: 'Neutral', s: scenarios.neutral, tone: 'border-slate-600/40' },
  ];
  return (
    <div className="panel">
      <div className="panel-title">Scenarios</div>
      <div className="grid gap-3 px-4 pb-4 md:grid-cols-3">
        {cols.map(({ key, s, tone }) => (
          <div key={key} className={`rounded-lg border bg-[#0f131c] p-3 ${tone} ${s.probabilityHint === 'primary' ? 'ring-1 ring-sky-500/40' : ''}`}>
            <div className="mb-1 flex items-center justify-between">
              <span className="text-xs font-bold text-slate-200">{key}</span>
              {s.probabilityHint === 'primary' && <span className="chip bg-sky-500/15 text-sky-300 !py-0">primary (bias: {bias.toLowerCase()})</span>}
            </div>
            <p className="mb-2 text-[11px] text-slate-400">{s.summary}</p>
            <div className="space-y-1 text-[11px]">
              {s.triggers.map((t, i) => <div key={i} className="text-slate-500">▸ {t}</div>)}
              <div className="pt-1 text-slate-400">Targets: <span className="font-mono">{s.targets.map(formatPrice).join(' · ')}</span></div>
              <div className="text-slate-500">Invalid: {s.invalidation}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ setup --

export function SetupPanel({ setup, risk }: { setup: TradeSetup | null; risk: RiskDecision | null }) {
  if (!setup) {
    return (
      <div className="panel p-4 text-xs text-slate-500">
        No tradeable setup for this symbol/timeframe — the consensus did not produce enough evidence (bias neutral,
        confidence below threshold, or data-quality gates failed). <b className="text-slate-400">NO_TRADE is a valid, deliberate outcome.</b>
      </div>
    );
  }
  const buy = setup.action === 'BUY';
  return (
    <div className="panel">
      <div className="flex items-center gap-2 px-4 pt-3">
        <span className={`rounded-lg px-3 py-1 text-lg font-black ${buy ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400'}`}>{setup.action}</span>
        <div>
          <div className="font-mono text-sm font-bold text-white">{setup.symbol}</div>
          <div className="text-[10px] text-slate-500">{setup.timeframe} · R:R {setup.riskReward.toFixed(2)} · conf {(setup.confidence * 100).toFixed(0)}%</div>
        </div>
        <span className={`chip ml-auto ${risk?.approved ? 'bg-emerald-500/15 text-emerald-400' : 'bg-rose-500/15 text-rose-400'}`}>
          {risk?.approved ? 'RISK: APPROVED' : 'RISK: VETOED'}
        </span>
      </div>

      <div className="space-y-1.5 px-4 py-3 font-mono text-xs">
        <Row label="Entry zone" value={`${formatPrice(setup.entry.min)} – ${formatPrice(setup.entry.max)}`} />
        <Row label="Stop loss" value={formatPrice(setup.stopLoss)} tone="text-rose-400" />
        {setup.takeProfit.map((tp, i) => <Row key={i} label={`Target ${i + 1}`} value={formatPrice(tp)} tone="text-emerald-400" />)}
        <Row label="Expires" value={new Date(setup.expiration).toISOString().replace('T', ' ').slice(0, 16) + 'Z'} />
      </div>

      {risk?.sizing && (
        <div className="mx-4 mb-3 rounded-lg border border-[#1d2333] bg-[#0f131c] p-2.5 text-[11px] text-slate-400">
          <div className="mb-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Risk-engine sizing (paper baseline)</div>
          <div className="grid grid-cols-2 gap-x-4 gap-y-1">
            <span>Risk amount <b className="font-mono text-slate-300">${risk.sizing.riskAmount.toFixed(0)}</b> ({(risk.sizing.riskPct * 100).toFixed(1)}%)</span>
            <span>Stop distance <b className="font-mono text-slate-300">{formatPrice(risk.sizing.stopDistance)}</b></span>
            <span>Units <b className="font-mono text-slate-300">{risk.sizing.units?.toLocaleString()}</b></span>
            <span>Notional <b className="font-mono text-slate-300">${risk.sizing.notionalUsd?.toLocaleString()}</b></span>
            <span>Leverage <b className="font-mono text-slate-300">{risk.sizing.impliedLeverage?.toFixed(1)}×</b></span>
            <span>Equity <b className="font-mono text-slate-300">${risk.sizing.equity.toLocaleString()}</b></span>
          </div>
        </div>
      )}

      {risk && !risk.approved && (
        <div className="mx-4 mb-3 rounded-lg border border-rose-500/30 bg-rose-500/5 p-2.5">
          <div className="mb-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-rose-400">Risk engine veto — reasons</div>
          {risk.reasons.map((r, i) => <div key={i} className="text-[11px] text-rose-300/90">✕ {r}</div>)}
        </div>
      )}
      {risk?.warnings.map((w, i) => <div key={i} className="px-4 pb-2 text-[11px] text-amber-300/80">⚠ {w}</div>)}

      <details className="px-4 pb-3 text-[11px] text-slate-500">
        <summary className="cursor-pointer text-sky-400">Setup rationale & invalidation</summary>
        <ul className="mt-1.5 space-y-1">
          {setup.rationale.map((r, i) => <li key={i}>▸ {r}</li>)}
          {setup.invalidationReasons.map((r, i) => <li key={i} className="text-rose-300/80">✕ {r}</li>)}
        </ul>
      </details>

      <div className="border-t border-[#1d2333] px-4 py-2 text-[10px] leading-relaxed text-slate-600">
        Phase 1 is ANALYSIS_ONLY: setups are proposals for review. No order can be placed by any component — the
        execution supervisor arrives in Phase 5 and will re-run the Risk Engine at submission time.
      </div>
    </div>
  );
}

function Row({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-slate-500">{label}</span>
      <span className={tone ?? 'text-slate-200'}>{value}</span>
    </div>
  );
}

// ------------------------------------------------------------------- risk --

const limitLabels: Record<string, string> = {
  riskPerTradePct: 'Risk per trade', maxRiskPerTradePct: 'Hard risk cap', minRiskReward: 'Min R:R',
  requireStopLoss: 'Stop mandatory', maxPositionNotionalUsd: 'Max notional', maxLeverage: 'Max leverage',
  maxOpenPositions: 'Max open positions', maxDailyLossPct: 'Max daily loss', maxWeeklyLossPct: 'Max weekly loss',
  maxDrawdownPct: 'Max drawdown', maxSymbolExposurePct: 'Max symbol risk', maxPortfolioExposurePct: 'Max portfolio risk',
  minDataQuality: 'Min data quality', blockSyntheticData: 'Block synthetic data', blockStaleData: 'Block stale data',
  maxCorrelatedPositions: 'Max correlated positions',
};

export function RiskPanel({ view }: { view: RiskLimitsView | null }) {
  if (!view) return <div className="panel p-4 text-xs text-slate-500">loading risk limits…</div>;
  const pct = (k: string) => (typeof view.limits[k] === 'number' && Math.abs(view.limits[k] as number) <= 1 ? `${((view.limits[k] as number) * 100).toFixed(1)}%` : String(view.limits[k]));
  return (
    <div className="panel">
      <div className="panel-title">Risk Engine — limits (defaults, safety-first)</div>
      <div className="space-y-1 px-4 pb-3 text-[11px]">
        {Object.entries(limitLabels).map(([k, label]) => (
          <div key={k} className="flex justify-between">
            <span className="text-slate-500">{label}</span>
            <span className="font-mono text-slate-300">{pct(k)}</span>
          </div>
        ))}
      </div>
      <div className="border-t border-[#1d2333] px-4 py-2.5 text-[11px] text-slate-500">
        Paper baseline equity <b className="font-mono text-slate-300">${view.portfolio.equity.toLocaleString()}</b> · open positions {view.portfolio.openPositions}.
        Real brokerage/portfolio sync arrives with broker connectors (Phase 4).
      </div>
    </div>
  );
}

// ----------------------------------------------------------- integrations --

const categoryLabels: Record<string, string> = {
  'market-data': 'Market Data', agent: 'AI Agents', engine: 'Engines', broker: 'Brokers',
  exchange: 'Crypto Exchanges', module: 'Modules', mode: 'Trading Modes',
};

export function StatusMatrix({ entries }: { entries: IntegrationStatusEntry[] }) {
  const groups = entries.reduce<Record<string, IntegrationStatusEntry[]>>((acc, e) => {
    (acc[e.category] ??= []).push(e);
    return acc;
  }, {});
  return (
    <div className="panel">
      <div className="panel-title">Integration Status — nothing claimed unless tested</div>
      <div className="space-y-3 px-4 pb-4">
        {Object.entries(groups).map(([cat, list]) => (
          <div key={cat}>
            <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{categoryLabels[cat] ?? cat}</div>
            <div className="space-y-1">
              {list.map((e) => (
                <div key={e.name} className="flex items-baseline gap-2 text-[11px]" title={e.detail}>
                  <span className={`chip ${statusColor(e.status)} !px-1.5 !py-0 shrink-0`}>{e.status}</span>
                  <span className="text-slate-300">{e.name}</span>
                  <span className="truncate text-slate-600">— {e.detail}</span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ events --

export function EventLog({ events }: { events: AuditEvent[] }) {
  const color = (t: string) =>
    t.startsWith('RISK_REJECTED') || t.includes('KILL_SWITCH_ACTIVATED') || t === 'TRADE_REJECTED'
      ? 'text-rose-400'
      : t.startsWith('SIGNAL') || t.startsWith('RISK_APPROVED')
        ? 'text-emerald-400'
        : 'text-slate-400';
  return (
    <div className="panel">
      <div className="panel-title">Audit trail (append-only)</div>
      <div className="scroll-thin max-h-64 space-y-1 overflow-y-auto px-4 pb-3">
        {events.map((e) => (
          <div key={e.id} className="flex gap-2 text-[11px]">
            <span className="shrink-0 font-mono text-slate-600">{new Date(e.at).toISOString().slice(11, 19)}</span>
            <span className={`shrink-0 font-semibold ${color(e.type)}`}>{e.type}</span>
            <span className="truncate text-slate-400" title={e.summary}>{e.summary}</span>
          </div>
        ))}
        {events.length === 0 && <div className="text-[11px] text-slate-600">no events yet</div>}
      </div>
    </div>
  );
}

export function HistoryList({ runs, onOpen }: { runs: { id: string; symbol: string; timeframe: string; bias: string; confidence: number; regime: string; synthetic: boolean; completedAt: string }[]; onOpen: (id: string) => void }) {
  return (
    <div className="panel">
      <div className="panel-title">Recent analysis runs</div>
      <div className="scroll-thin max-h-56 space-y-1 overflow-y-auto px-4 pb-3">
        {runs.map((r) => (
          <button key={r.id} onClick={() => onOpen(r.id)} className="flex w-full items-center gap-2 rounded px-1.5 py-1 text-left text-[11px] hover:bg-[#141926]">
            <span className="font-mono font-bold text-slate-300">{r.symbol}</span>
            <span className="text-slate-600">{r.timeframe}</span>
            <span className={`chip ${biasColor(r.bias)} !px-1.5 !py-0`}>{r.bias.slice(0, 4)}</span>
            <span className="font-mono text-slate-500">{(r.confidence * 100).toFixed(0)}%</span>
            {r.synthetic && <span className="text-amber-500" title="synthetic data">◇</span>}
            <span className="ml-auto font-mono text-slate-600">{new Date(r.completedAt).toISOString().slice(11, 19)}</span>
          </button>
        ))}
        {runs.length === 0 && <div className="text-[11px] text-slate-600">no runs yet</div>}
      </div>
    </div>
  );
}

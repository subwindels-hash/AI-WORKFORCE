import type { JournalEntry } from '../store/types';

/**
 * Performance analytics over the trade journal (spec §15):
 * win rate, profit factor, drawdown, averages, and groupings by strategy,
 * market, symbol and AI-confidence level — the last one answers "do the
 * AI's high-confidence trades actually perform better than its low-confidence
 * trades?" once journal entries carry confidence data.
 */

export interface BucketMetrics {
  count: number;
  winRate: number | null;
  profitFactor: number | null;
  expectancyPnl: number | null;
  avgWin: number | null;
  avgLoss: number | null;
  totalPnl: number;
  avgRMultiple: number | null;
}

export interface GroupedAnalytics {
  groupBy: 'strategy' | 'market' | 'symbol' | 'source' | 'confidence';
  groups: { key: string; metrics: BucketMetrics }[];
  overall: BucketMetrics & { closedTrades: number; openOrPending: number; maxDrawdownPct: number };
  note?: string;
}

export const CONFIDENCE_BUCKETS: { key: string; min: number; max: number }[] = [
  { key: '0–40% (low)', min: 0, max: 0.4 },
  { key: '40–60% (moderate)', min: 0.4, max: 0.6 },
  { key: '60–80% (high)', min: 0.6, max: 0.8 },
  { key: '80–100% (very high)', min: 0.8, max: 1.0001 },
];

export function bucketMetrics(entries: JournalEntry[]): BucketMetrics {
  const closed = entries.filter((e) => e.pnl !== null && e.exit !== null);
  const wins = closed.filter((e) => (e.pnl as number) > 0);
  const losses = closed.filter((e) => (e.pnl as number) <= 0);
  const grossWin = wins.reduce((a, e) => a + (e.pnl as number), 0);
  const grossLoss = Math.abs(losses.reduce((a, e) => a + (e.pnl as number), 0));
  const rEntries = closed.filter((e) => e.rMultiple !== null);
  return {
    count: closed.length,
    winRate: closed.length ? round4(wins.length / closed.length) : null,
    profitFactor: closed.length === 0 ? null : grossLoss === 0 ? null : round4(grossWin / grossLoss),
    expectancyPnl: closed.length ? round2((grossWin - grossLoss) / closed.length) : null,
    avgWin: wins.length ? round2(grossWin / wins.length) : null,
    avgLoss: losses.length ? round2(-grossLoss / losses.length) : null,
    totalPnl: round2(grossWin - grossLoss),
    avgRMultiple: rEntries.length ? round4(rEntries.reduce((a, e) => a + (e.rMultiple as number), 0) / rEntries.length) : null,
  };
}

export function analyzeJournal(
  entries: JournalEntry[],
  groupBy: 'strategy' | 'market' | 'symbol' | 'source' | 'confidence',
): GroupedAnalytics {
  const overallBase = bucketMetrics(entries);
  const closed = entries.filter((e) => e.pnl !== null && e.exit !== null);

  // Drawdown from the cumulative closed P&L series (chronological).
  const chrono = [...closed].sort((a, b) => (a.executionTime > b.executionTime ? 1 : -1));
  let cum = 0;
  let peakPnl = 0;
  let maxDd = 0;
  for (const e of chrono) {
    cum += e.pnl as number;
    peakPnl = Math.max(peakPnl, cum);
    maxDd = Math.max(maxDd, peakPnl - cum);
  }

  let groups: { key: string; metrics: BucketMetrics }[] = [];
  if (groupBy === 'confidence') {
    const withConf = closed.filter((e) => e.aiConfidence !== null);
    groups = CONFIDENCE_BUCKETS.map((b) => ({
      key: b.key,
      metrics: bucketMetrics(withConf.filter((e) => (e.aiConfidence as number) >= b.min && (e.aiConfidence as number) < b.max)),
    })).filter((g) => g.metrics.count > 0);
  } else {
    const keys = [...new Set(closed.map((e) => String(e[groupBy] ?? '—')).sort())];
    groups = keys.map((k) => ({
      key: k,
      metrics: bucketMetrics(closed.filter((e) => String(e[groupBy] ?? '—') === k)),
    })).filter((g) => g.metrics.count > 0);
  }

  const note =
    groupBy === 'confidence' && closed.every((e) => e.aiConfidence === null)
      ? 'No confidence-tagged trades in the journal yet — buckets populate once entries carry aiConfidence (strategy signals already tag it; AI-consensus tagging arrives with paper trading).'
      : undefined;

  return {
    groupBy,
    groups,
    overall: { ...overallBase, closedTrades: closed.length, openOrPending: entries.length - closed.length, maxDrawdownPct: round2(maxDd) },
    note,
  };
}

/** Direct answer to the calibration question, with an honest sample-size guard. */
export interface CalibrationReport {
  buckets: { key: string; count: number; winRate: number | null; expectancyR: number | null }[];
  verdict: string;
  sufficientData: boolean;
}

export function confidenceCalibration(entries: JournalEntry[]): CalibrationReport {
  const closed = entries.filter((e) => e.pnl !== null && e.exit !== null && e.aiConfidence !== null);
  const buckets = CONFIDENCE_BUCKETS.map((b) => {
    const inBucket = closed.filter((e) => {
      const c = e.aiConfidence as number;
      return c >= b.min && c < b.max;
    });
    const m = bucketMetrics(inBucket);
    return { key: b.key, count: m.count, winRate: m.winRate, expectancyR: m.avgRMultiple };
  }).filter((b) => b.count > 0);

  if (closed.length < 30) {
    return {
      buckets,
      sufficientData: false,
      verdict: `Sample too small for a calibration verdict (${closed.length} confidence-tagged closed trades; need 30+). Collect more journal entries.`,
    };
  }

  // Monotonicity check: win rate should broadly increase with confidence.
  const rates = buckets.filter((b) => b.winRate !== null).map((b) => b.winRate as number);
  let monotonic = true;
  for (let i = 1; i < rates.length; i++) if (rates[i] < rates[i - 1] - 0.05) monotonic = false;
  const verdict = monotonic
    ? 'Win rate broadly increases with confidence — the confidence signal is directionally informative. (Not a guarantee: verify across regimes and symbols.)'
    : 'Win rate does NOT consistently increase with confidence — treat the confidence signal with skepticism and re-examine before sizing up on it.';
  return { buckets, sufficientData: true, verdict };
}

function round2(v: number): number {
  return Math.round(v * 100) / 100;
}
function round4(v: number): number {
  return Math.round(v * 10000) / 10000;
}

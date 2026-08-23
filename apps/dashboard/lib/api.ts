import type {
  AnalysisRun, ConsensusSummary, IntegrationStatusEntry, RiskLimitsView, SystemStatus, AuditEvent,
  StrategyRecord, BacktestResult, BacktestSummary, JournalEntryView, AnalyticsSummaryView, CalibrationView,
} from './types';

async function json<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await fetch(url, { ...init, headers: { 'Content-Type': 'application/json', ...(init?.headers ?? {}) } });
  if (!res.ok) {
    const body = await res.text();
    throw new Error(`API ${res.status}: ${body.slice(0, 200)}`);
  }
  return res.json() as Promise<T>;
}

export const api = {
  systemStatus: () => json<SystemStatus>('/api/system/status'),
  integrations: () => json<IntegrationStatusEntry[]>('/api/system/features'),
  consensus: (symbols: string[], timeframe: string) =>
    json<{ generatedAt: string; consensus: ConsensusSummary[] }>('/api/agents/consensus', {
      method: 'POST',
      body: JSON.stringify({ symbols, timeframe }),
    }),
  runAnalysis: (symbol: string, marketClass: string, timeframe: string) =>
    json<AnalysisRun>('/api/analysis/run', {
      method: 'POST',
      body: JSON.stringify({ symbol, marketClass, timeframe }),
    }),
  history: () => json<{ runs: { id: string; symbol: string; timeframe: string; bias: string; confidence: number; regime: string; synthetic: boolean; completedAt: string }[] }>('/api/analysis/history?limit=12'),
  riskLimits: () => json<RiskLimitsView>('/api/risk/limits'),
  events: () => json<{ events: AuditEvent[] }>('/api/events?limit=25'),
  killSwitch: (active: boolean, reason: string) =>
    json<{ killSwitch: SystemStatus['killSwitch'] }>('/api/trading/kill-switch', {
      method: 'POST',
      body: JSON.stringify({ active, reason }),
    }),
  // ------------------------------------------------------------- phase 2 --
  strategies: () =>
    json<{ strategies: { strategyId: string; latest: StrategyRecord; versions: { version: string; lifecycle: string; updatedAt: string }[] }[] }>('/api/strategies'),
  strategy: (id: string) => json<StrategyRecord>(`/api/strategies/${id}`),
  strategyStatus: (id: string, to: string, reason?: string) =>
    json<{ ok: boolean; strategy?: StrategyRecord; warnings?: string[] }>(`/api/strategies/${id}/status`, {
      method: 'POST',
      body: JSON.stringify({ to, reason }),
    }),
  runBacktest: (payload: Record<string, unknown>) =>
    json<BacktestResult>('/api/backtesting/run', { method: 'POST', body: JSON.stringify(payload) }),
  backtestResults: (strategyId?: string) =>
    json<{ results: BacktestSummary[] }>(`/api/backtesting/results${strategyId ? `?strategyId=${strategyId}` : ''}`),
  backtestDetail: (id: string) => json<BacktestResult>(`/api/backtesting/results/${id}`),
  journal: (params?: string) => json<{ entries: JournalEntryView[] }>(`/api/journal${params ? `?${params}` : ''}`),
  analytics: (groupBy: string) => json<AnalyticsSummaryView>(`/api/analytics/summary?groupBy=${groupBy}`),
  calibration: () => json<CalibrationView>('/api/analytics/confidence-calibration'),
};

export function formatAge(ms: number): string {
  if (ms < 60_000) return `${Math.round(ms / 1000)}s`;
  if (ms < 3_600_000) return `${Math.round(ms / 60_000)}m`;
  if (ms < 86_400_000) return `${(ms / 3_600_000).toFixed(1)}h`;
  return `${(ms / 86_400_000).toFixed(1)}d`;
}

export function formatPrice(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  if (v >= 1000) return v.toLocaleString('en-US', { maximumFractionDigits: 1 });
  if (v >= 10) return v.toFixed(3);
  if (v >= 1) return v.toFixed(4);
  return v.toFixed(5);
}

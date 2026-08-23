import { TIMEFRAME_MS, type Timeframe } from '../types';

export interface HttpFetch {
  (url: string, init?: { method?: string; headers?: Record<string, string>; signal?: AbortSignal }): Promise<{
    ok: boolean;
    status: number;
    text: () => Promise<string>;
  }>;
}

/**
 * Fetch JSON with hard timeout, bounded retries and exponential backoff.
 * Honors HTTP 429 with a cooldown. Injected `fetchImpl` keeps this testable
 * and lets the platform run without a global fetch polyfill.
 */
export async function fetchJson<T>(
  url: string,
  opts: {
    fetchImpl: HttpFetch;
    timeoutMs?: number;
    retries?: number;
    headers?: Record<string, string>;
    rateLimitCooldownMs?: number;
  },
): Promise<T> {
  const { fetchImpl, timeoutMs = 8000, retries = 2, headers = {}, rateLimitCooldownMs = 30_000 } = opts;

  let lastError: Error = new Error('fetch failed');
  for (let attempt = 0; attempt <= retries; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const res = await fetchImpl(url, { method: 'GET', headers, signal: controller.signal });
      clearTimeout(timer);
      if (res.status === 429) {
        await sleep(rateLimitCooldownMs);
        lastError = new Error('HTTP 429 rate limited');
        continue;
      }
      if (!res.ok) {
        lastError = new Error(`HTTP ${res.status}`);
        if (res.status >= 500 || res.status === 408) {
          await sleep(2 ** attempt * 300);
          continue;
        }
        throw lastError; // 4xx (other than 429) — not retryable
      }
      const text = await res.text();
      return JSON.parse(text) as T;
    } catch (err) {
      clearTimeout(timer);
      lastError = err instanceof Error ? err : new Error(String(err));
      if (attempt < retries) await sleep(2 ** attempt * 300);
    }
  }
  throw lastError;
}

export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Align an epoch-ms timestamp down to the candle grid of a timeframe. */
export function alignToTimeframe(ts: number, timeframe: Timeframe): number {
  const interval = TIMEFRAME_MS[timeframe];
  return Math.floor(ts / interval) * interval;
}

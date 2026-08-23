import type {
  Candle,
  CandleRequest,
  MarketClass,
  MarketDataProvider,
  ProviderCapabilities,
  ProviderHealth,
  Quote,
  Timeframe,
} from '../../types';
import { fetchJson, type HttpFetch } from '../http';
import { CircuitBreaker } from '../circuit-breaker';

/** ECB reference currencies (Frankfurter coverage). XAU metals are NOT included — honest limitation. */
const ECB_CURRENCIES = new Set([
  'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
  'IDR', 'ILS', 'INR', 'ISK', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN',
  'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR',
]);

interface FrankfurterTimeSeries {
  base: string;
  rates: Record<string, { [date: string]: number }>;
}

/**
 * REAL forex reference rates from Frankfurter (ECB data, public, no key).
 *
 * Honest capability statement: the ECB publishes DAILY reference rates only.
 * This provider therefore serves exactly one timeframe — 1d. Requests for
 * intraday forex timeframes are refused here so the provider manager can
 * fall back (and flag it) rather than this provider inventing intraday data.
 */
export class FrankfurterProvider implements MarketDataProvider {
  readonly name = 'frankfurter-ecb';
  readonly synthetic = false;
  readonly priority = 20;

  private breaker = new CircuitBreaker('frankfurter');
  private lastError: string | undefined;

  constructor(
    private readonly baseUrl = process.env.FRANKFURTER_API_BASE ?? 'https://api.frankfurter.dev',
    private readonly fetchImpl: HttpFetch = (globalThis.fetch as unknown as HttpFetch) ?? fallbackFetch,
  ) {}

  supportsSymbol(symbol: string): boolean {
    const { base, quote } = splitPair(symbol);
    return ECB_CURRENCIES.has(base) && ECB_CURRENCIES.has(quote) && base !== quote;
  }

  supportsTimeframe(_symbol: string, tf: Timeframe): boolean {
    return tf === '1d'; // ECB publishes daily reference rates only.
  }

  async getCandles(req: CandleRequest): Promise<Candle[]> {
    if (req.timeframe !== '1d') {
      throw new Error('frankfurter-ecb serves daily (1d) data only');
    }
    if (!this.supportsSymbol(req.symbol)) {
      throw new Error(`frankfurter-ecb does not cover ${req.symbol}`);
    }
    const { base, quote } = splitPair(req.symbol);
    const days = Math.ceil(req.limit * 1.5) + 10; // weekends/holidays produce no rows
    const start = new Date(Date.now() - days * 86_400_000).toISOString().slice(0, 10);
    const url = `${this.baseUrl}/v1/${start}..?base=${base}&symbols=${quote}`;

    const data = await this.withBreaker<FrankfurterTimeSeries>(url);
    const dates = Object.keys(data.rates[quote] ?? {}).sort();
    const rows = dates.map((d) => ({ d, rate: data.rates[quote][d] })).filter((r) => Number.isFinite(r.rate));

    // Daily reference rate is a single price point per day: synthesize an
    // OHLC envelope around consecutive reference rates. The candle "body" is
    // the day-over-day rate move — clearly documented, not fabricated ticks.
    const candles: Candle[] = [];
    for (let i = 0; i < rows.length; i++) {
      const prev = i > 0 ? rows[i - 1].rate : rows[i].rate;
      const cur = rows[i].rate;
      const open = prev;
      const close = cur;
      candles.push({
        timestamp: Date.parse(`${rows[i].d}T00:00:00Z`),
        open,
        high: Math.max(open, close),
        low: Math.min(open, close),
        close,
        volume: 0, // Reference rates carry no volume; reported honestly.
      });
    }
    return candles.slice(-req.limit);
  }

  async getQuote(symbol: string): Promise<Quote> {
    if (!this.supportsSymbol(symbol)) {
      throw new Error(`frankfurter-ecb does not cover ${symbol}`);
    }
    const { base, quote } = splitPair(symbol);
    const url = `${this.baseUrl}/v1/latest?base=${base}&symbols=${quote}`;
    const data = await this.withBreaker<{ base: string; date: string; rates: Record<string, number> }>(url);
    const rate = data.rates[quote];
    if (!Number.isFinite(rate)) throw new Error('frankfurter-ecb returned no rate');
    return {
      symbol: symbol.toUpperCase(),
      last: rate,
      timestamp: Date.parse(`${data.date}T16:00:00Z`), // ECB publication time (approx., CET business close)
    };
  }

  async healthCheck(): Promise<ProviderHealth> {
    const started = Date.now();
    try {
      const url = `${this.baseUrl}/v1/latest?base=EUR&symbols=USD`;
      await this.withBreaker<unknown>(url, 1);
      this.lastError = undefined;
      return {
        name: this.name,
        status: 'UP',
        synthetic: false,
        latencyMs: Date.now() - started,
        lastCheckAt: Date.now(),
        circuitState: this.breaker.currentState(),
        detail: 'ECB daily reference rates via Frankfurter (daily timeframe only, no volume)',
      };
    } catch (err) {
      this.lastError = err instanceof Error ? err.message : String(err);
      return {
        name: this.name,
        status: 'DOWN',
        synthetic: false,
        latencyMs: Date.now() - started,
        lastCheckAt: Date.now(),
        lastError: this.lastError,
        circuitState: this.breaker.currentState(),
        detail: 'Unreachable from this host — provider manager will fall back and flag synthetic use.',
      };
    }
  }

  capabilities(): ProviderCapabilities {
    return {
      marketClasses: ['forex'] as MarketClass[],
      timeframes: ['1d'],
      delayed: true, // ECB reference rates are published once per business day.
      notes: 'Real ECB daily FX reference rates. Intraday forex and metals (XAUUSD) are NOT available from this source.',
    };
  }

  private async withBreaker<T>(url: string, retries = 2): Promise<T> {
    if (!this.breaker.canCall()) {
      throw new Error(`frankfurter circuit breaker OPEN`);
    }
    try {
      const out = await fetchJson<T>(url, { fetchImpl: this.fetchImpl, retries, timeoutMs: 6000 });
      this.breaker.recordSuccess();
      return out;
    } catch (err) {
      this.breaker.recordFailure();
      throw err;
    }
  }
}

export function splitPair(symbol: string): { base: string; quote: string } {
  const s = symbol.toUpperCase();
  const six = [s.slice(0, 3), s.slice(3, 6)];
  if (six[0].length === 3 && six[1].length === 3) return { base: six[0], quote: six[1] };
  return { base: s, quote: '' };
}

function fallbackFetch(): Promise<{ ok: boolean; status: number; text: () => Promise<string> }> {
  return Promise.reject(new Error('No HTTP client available'));
}

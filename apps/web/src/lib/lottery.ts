/**
 * Server-side bridge to the WINDELS PHP backend's lottery API.
 *
 * EuroMillions draw records live in the PHP application's verified historical
 * database (lottery_draws / lottery_draw_numbers) and are served by
 * /api/lottery/*. EuroMillions is ONE shared draw — every participating
 * country uses the same winning combination — so the widget reads the single
 * stored record. Nothing here (and nothing in the UI) hard-codes winning
 * numbers, Lucky Stars or jackpots: when no backend is reachable the widget
 * renders an honest empty state instead of fabricating draw data.
 *
 * These helpers run on the Next.js SERVER (never the browser), so they may
 * address an internal service directly. The browser only ever calls the
 * relative /api/lottery/* path on the Next server itself.
 */

// Matches next.config.ts (rewrites /api/* to the PHP backend).
const target = (process.env.LOTTERY_API_INTERNAL_URL ?? process.env.LEAD_API_INTERNAL_URL ?? 'http://127.0.0.1:8080').replace(/\/+$/, '');

/**
 * The honest empty dashboard shape (mirrors Api_lottery::dashboard defaults).
 * Winning numbers and jackpots are intentionally absent — never invented.
 */
export const EMPTY_LOTTERY_DASHBOARD = {
  status: 'NO_DATA',
  jackpot: null,
  jackpotFormatted: '—',
  nextEstimated: null,
  lastDraw: null,
  recentDraws: [],
  imported: 0,
  verifiedDraws: 0,
  dataAvailable: false,
  jackpotSource: null,
  historicalDataset: null,
  lastSuccessfulSync: null,
  lastSyncAttempt: null,
  syncStatus: 'UNKNOWN',
  syncMessage: 'No lottery backend is connected — the dashboard never fabricates draw data.',
  myTicketsCount: 0,
  rules: { mains: 5, mainMax: 50, stars: 2, starMax: 12 },
  // EuroMillions is a single shared draw — there is no per-country variant.
  lotteries: [{ code: 'euromillions', name: 'EuroMillions' }],
};

/**
 * GET an /api/lottery/* resource from the PHP backend (server-side).
 * @returns the decoded JSON, or null when no backend is configured or reachable.
 */
export async function fetchLotteryJson(pathWithQuery: string): Promise<unknown> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8_000);
  try {
    const response = await fetch(`${target}/api/lottery/${pathWithQuery}`, {
      signal: controller.signal,
      headers: { accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) return null;
    return await response.json();
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

import { NextResponse } from 'next/server';

import { EMPTY_LOTTERY_DASHBOARD, fetchLotteryJson } from '../../../../lib/lottery';

export const dynamic = 'force-dynamic';

/**
 * Lottery dashboard — proxied from the WINDELS PHP backend's verified database.
 *
 * Winning numbers, Lucky Stars, draw dates, jackpots and verification status are
 * NOT defined here. They come from the PHP /api/lottery/dashboard endpoint, which
 * reads the validated historical draw store (lottery_draws / lottery_draw_numbers).
 * EuroMillions is one shared draw across all participating countries, so there is
 * a single stored record — no per-country winning numbers.
 *
 * When the backend is unreachable the response is an honest "NO_DATA" shape with
 * no fabricated numbers — the client renders an empty state rather than inventing
 * a draw.
 */
export async function GET(): Promise<Response> {
  const data = (await fetchLotteryJson('dashboard')) as Record<string, unknown> | null;
  if (!data) {
    return NextResponse.json(EMPTY_LOTTERY_DASHBOARD, { status: 200 });
  }
  return NextResponse.json(data);
}

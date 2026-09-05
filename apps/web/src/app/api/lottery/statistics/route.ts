import { NextResponse } from 'next/server';

import { fetchLotteryJson } from '../../../../lib/lottery';

export const dynamic = 'force-dynamic';

/**
 * Lottery hot/cold statistics — computed by the PHP backend from the verified
 * historical dataset, never hard-coded here. The backend answers an
 * associative map (number → frequency); we return plain ascending number lists
 * so the widget renders balls without knowing the statistics internals. When
 * the backend is unreachable the response is empty — the UI shows no invented
 * "hot" or "cold" numbers.
 */
export async function GET(): Promise<Response> {
  const payload = (await fetchLotteryJson('statistics/hot-cold?window=0')) as
    | { data?: { hot?: unknown; cold?: unknown } }
    | null;

  const toNumbers = (value: unknown): number[] => {
    if (Array.isArray(value)) return value.map((n) => Number(n)).filter((n) => Number.isFinite(n));
    if (value && typeof value === 'object') {
      return Object.keys(value as Record<string, unknown>)
        .map((k) => Number(k))
        .filter((n) => Number.isFinite(n))
        .sort((a, b) => a - b);
    }
    return [];
  };

  const hot = toNumbers(payload?.data?.hot);
  const cold = toNumbers(payload?.data?.cold);
  return NextResponse.json({ hot, cold });
}

import { NextResponse } from 'next/server';

/**
 * Synthetic lottery dashboard data for demonstration purposes.
 * In production, this would query the MySQL database for real lottery data.
 */
const syntheticDashboard = {
  status: 'active',
  jackpot: 35000000,
  jackpotFormatted: '$35,000,000',
  nextEstimated: '2026-09-20',
  lastDraw: {
    id: 1,
    lottery_code: 'euromillions',
    draw_date: '2026-09-03',
    mainNumbers: [5, 12, 23, 34, 45],
    jackpot: 35000000,
    winners: 2,
    currency: 'EUR',
  },
  recentDraws: [
    {
      id: 1,
      lottery_code: 'euromillions',
      draw_date: '2026-09-03',
      mainNumbers: [5, 12, 23, 34, 45],
      jackpot: 35000000,
      winners: 2,
      currency: 'EUR',
    },
    {
      id: 2,
      lottery_code: 'euromillions',
      draw_date: '2026-08-30',
      mainNumbers: [3, 9, 18, 27, 39],
      jackpot: 32000000,
      winners: 1,
      currency: 'EUR',
    },
    {
      id: 3,
      lottery_code: 'euromillions',
      draw_date: '2026-08-27',
      mainNumbers: [8, 16, 25, 33, 42],
      jackpot: 30000000,
      winners: 0,
      currency: 'EUR',
    },
  ],
  imported: 156,
  myTicketsCount: 3,
  rules: {
    mains: 5,
    mainMax: 50,
    stars: 2,
    starMax: 12,
  },
  lotteries: [
    { code: 'euromillions', name: 'EuroMillions' },
    { code: 'powerball', name: 'Powerball' },
    { code: 'mega-millions', name: 'Mega Millions' },
  ],
};

export const GET = () => NextResponse.json(syntheticDashboard);
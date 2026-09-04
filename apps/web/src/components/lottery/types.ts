/**
 * Lottery Intelligence - TypeScript Interfaces
 * Matches the backend API response structure
 */

export interface LiDraw {
  id: number;
  lottery_code: string;
  draw_date: string;
  drawDate?: string;
  mainNumbers: number[];
  bonusNumbers?: number[];
  stars?: number[];
  numbers?: {
    main: number[];
    stars?: number[];
  };
  jackpot?: number;
  jackpotMinor?: number;
  winners: number;
  currency?: string;
}

export interface LiDashboard {
  status: string;
  jackpot: number | null;
  jackpotMinor?: number | null;
  jackpotFormatted?: string;
  nextEstimated: string | null;
  lastDraw: LiDraw | null;
  recentDraws: LiDraw[];
  imported: number;
  myTicketsCount: number;
  rules: {
    mains: number;
    mainMax: number;
    stars: number;
    starMax: number;
  };
  lotteries: Array<{
    code: string;
    name: string;
  }>;
}

export interface EuroMillionsData {
  currentJackpot: number | null;
  jackpotFormatted: string;
  nextDraw: string | null;
  latestDraw: LiDraw | null;
  recentDraws: LiDraw[];
  hotNumbers: number[];
  coldNumbers: number[];
  rules: {
    mains: number;
    mainMax: number;
    stars: number;
    starMax: number;
  };
}

import type { RiskLimits, TradingMode } from '../types';

/** Platform defaults — safety-first. Live trading is disabled by default (Rule 4). */
export const DEFAULT_TRADING_MODE: TradingMode = 'ANALYSIS_ONLY';

/** The kill switch ships ACTIVE (all order placement blocked) until explicitly released. */
export const DEFAULT_KILL_SWITCH_ACTIVE = true;

export const DEFAULT_RISK_LIMITS: RiskLimits = {
  riskPerTradePct: 0.01, // 1% of equity per trade
  maxRiskPerTradePct: 0.02, // hard ceiling 2%
  minRiskReward: 1.5,
  requireStopLoss: true,
  /** Sized for a $10k baseline account: 1% risk with a ~30-pip FX stop implies ~$36k notional. */
  maxPositionNotionalUsd: 50_000,
  maxLeverage: 5,
  maxOpenPositions: 10,
  maxDailyLossPct: 0.03,
  maxWeeklyLossPct: 0.06,
  maxDrawdownPct: 0.1,
  // Exposure = capital AT RISK (stop-distance basis) as % of equity.
  // Notional/margin exposure is governed by maxPositionNotionalUsd + maxLeverage.
  maxSymbolExposurePct: 0.05, // max 5% of equity at risk in one symbol
  maxPortfolioExposurePct: 0.15, // max 15% of equity at risk in total
  maxCorrelatedPositions: 3,
  minDataQuality: 0.5,
  blockSyntheticData: true,
  blockStaleData: true,
};

/** Phase 1 has no broker connection; the paper-portfolio baseline used for sizing math. */
export const DEFAULT_PORTFOLIO_STATE = {
  equity: 10_000,
  cash: 10_000,
  openPositions: 0,
  openRiskBySymbol: {},
  dailyPnl: 0,
  weeklyPnl: 0,
  peakEquity: 10_000,
};

export const ANALYSIS_DEFAULTS = {
  candleLimit: 300,
  /** Minimum confidence for a trade setup to be generated at all. */
  minSetupConfidence: 0.55,
  /** Setup expires after this many bars of the analysed timeframe. */
  setupExpiryBars: 24,
  /** Agent weights in the consensus vote. */
  agentWeights: {
    technical: 1.0,
    'market-structure': 0.9,
    forex: 0.9,
    crypto: 0.9,
    sentiment: 0.5,
  } as Record<string, number>,
  /** |directionalScore| below this counts as "no meaningful opinion". */
  voteThreshold: 0.15,
  /** |netScore| needed before a directional bias is declared. */
  biasThreshold: 0.2,
};

export const MARKET_CLASS_SYMBOLS: Record<string, string[]> = {
  forex: ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'USDCHF', 'NZDUSD', 'XAUUSD'],
  crypto: ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'],
};

export const SYMBOL_MARKET_CLASS: Record<string, string> = Object.entries(MARKET_CLASS_SYMBOLS).reduce(
  (acc, [cls, symbols]) => {
    for (const s of symbols) acc[s] = cls;
    return acc;
  },
  {} as Record<string, string>,
);

import type { IntegrationStatusEntry } from '../types';

/**
 * Integration honesty matrix (Rule 3).
 *
 * Only modules that are implemented AND covered by automated tests may claim
 * TESTED. Everything else is PLANNED (or DISABLED) — this is the single
 * source the /api/system/features endpoint serves, and the dashboard renders.
 */
export const INTEGRATION_STATUS: IntegrationStatusEntry[] = [
  // Market data
  { name: 'MarketDataProvider abstraction', category: 'market-data', status: 'TESTED', detail: 'Provider interface, health checks, retry, timeout, circuit breaker, caching, fallback chain' },
  { name: 'Binance market data (crypto)', category: 'market-data', status: 'IMPLEMENTED', detail: 'Real public REST klines/quotes. Reports DOWN automatically when the host cannot reach api.binance.com; falls back with explicit provenance.' },
  { name: 'Frankfurter/ECB (forex daily)', category: 'market-data', status: 'IMPLEMENTED', detail: 'Real ECB daily reference rates. Serves 1d only; reports DOWN when unreachable. Intraday FX needs a licensed feed (PLANNED).' },
  { name: 'Synthetic demo provider', category: 'market-data', status: 'TESTED', detail: 'Deterministic generator, always flagged SYNTHETIC in provenance; UI shows a simulation banner. Never presented as real data.' },
  { name: 'Stock data provider', category: 'market-data', status: 'PLANNED', detail: 'Phase 6 — needs a licensed equities feed' },
  { name: 'ETF data provider', category: 'market-data', status: 'PLANNED', detail: 'Phase 6' },
  { name: 'Commodities dedicated feed', category: 'market-data', status: 'PLANNED', detail: 'Phase 6 — XAUUSD currently only available via synthetic demo offline' },
  { name: 'Futures/options chains provider', category: 'market-data', status: 'PLANNED', detail: 'Phase 6 — Options agent disabled until real chain data exists (no simulated Greeks)' },
  { name: 'News / social sentiment providers', category: 'market-data', status: 'PLANNED', detail: 'Phase 6 — sentiment agent currently abstains honestly' },
  { name: 'On-chain analytics provider', category: 'market-data', status: 'PLANNED', detail: 'Phase 6 — crypto agent reports dataAvailable=false today' },

  // Agents
  { name: 'Technical Analysis Agent', category: 'agent', status: 'TESTED', detail: 'SMA/EMA/RSI/MACD/BB/ATR/ADX/VWAP/Stochastic/S-R/pivots/volume-profile with unit-tested math' },
  { name: 'Market Structure Agent', category: 'agent', status: 'TESTED', detail: 'Swings, BOS/CHoCH with close-confirmation rule (wick-only never confirms), liquidity, S/D zones, order blocks, FVGs' },
  { name: 'Forex Agent', category: 'agent', status: 'TESTED', detail: 'Classification, volatility, trend alignment, session clock, price-momentum currency strength; macro reported unavailable' },
  { name: 'Crypto Agent', category: 'agent', status: 'TESTED', detail: 'Price action, volume, volatility from candles; on-chain/derivatives/dominance reported unavailable' },
  { name: 'Sentiment Agent', category: 'agent', status: 'TESTED', detail: 'Abstains with explicit unavailability until real providers are configured' },
  { name: 'Trading Intelligence Agent (consensus)', category: 'agent', status: 'TESTED', detail: 'Weighted confluence, confidence, conflict detection, BUY/SELL/HOLD/NO_TRADE' },
  { name: 'Stock Agent', category: 'agent', status: 'PLANNED', detail: 'Phase 6 — requires fundamentals provider' },
  { name: 'ETF Agent', category: 'agent', status: 'PLANNED', detail: 'Phase 6' },
  { name: 'Commodities Agent', category: 'agent', status: 'PLANNED', detail: 'Phase 6 — supply/demand/inventory data source required' },
  { name: 'Futures Agent', category: 'agent', status: 'PLANNED', detail: 'Phase 6 — term structure data required' },
  { name: 'Options Intelligence Agent', category: 'agent', status: 'DISABLED', detail: 'Deliberately disabled: Greeks/IV/max-pain only with real options-chain data (never simulated)' },

  // Engines
  { name: 'Trading Intelligence Engine', category: 'engine', status: 'TESTED', detail: 'Full pipeline: data -> agents -> consensus -> regime -> scenarios -> setup -> risk' },
  { name: 'Regime detection', category: 'engine', status: 'TESTED', detail: 'TRENDING_UP/DOWN, RANGING, HIGH/LOW_VOLATILITY, BREAKOUT, UNKNOWN with evidence' },
  { name: 'Trade Setup Generator', category: 'engine', status: 'TESTED', detail: 'Zone entries, ATR/structure stops, R-multiple targets, expiry, invalidation reasons' },
  { name: 'Risk Engine', category: 'engine', status: 'TESTED', detail: 'Independent veto: per-trade + portfolio limits, sizing math, kill-switch participation' },
  { name: 'Event & audit trail', category: 'engine', status: 'TESTED', detail: 'Append-only event bus with structured audit records' },
  { name: 'Strategy Engine + versioning', category: 'module', status: 'TESTED', detail: 'Strategy contract, 4 built-in strategies (trend/mean-reversion/breakout/momentum), versioned registry, gated lifecycle DRAFT→BACKTESTED→VALIDATED→RISK_REVIEWED→PAPER_TRADING→APPROVED' },
  { name: 'Backtesting Engine', category: 'module', status: 'TESTED', detail: 'Event-driven simulator: next-bar-open fills, spread/slippage/commission cost model, pessimistic stop-first rule, look-ahead guard, R-based sizing, full provenance' },
  { name: 'Performance metrics', category: 'module', status: 'TESTED', detail: 'Return, win rate, profit factor, expectancy (R & P&L), Sharpe, Sortino, max drawdown, streaks, exposure, cost totals' },
  { name: 'Trade journal + analytics', category: 'module', status: 'TESTED', detail: 'Per-trade journal (fees/slippage/reason/confidence) with groupings by strategy/market/symbol and AI-confidence calibration' },
  { name: 'Grid / DCA strategies', category: 'module', status: 'PLANNED', detail: 'Execution-heavy styles — arrive with paper trading (Phase 3)' },
  { name: 'AI-generated strategies', category: 'module', status: 'PLANNED', detail: 'Generation not implemented; the lifecycle already blocks AI-source strategies from advancing past risk review automatically' },
  { name: 'PostgreSQL persistence', category: 'module', status: 'PLANNED', detail: 'Schema defined in db/schema.sql; the tested runtime uses the JSON file store behind the same DataStore contract' },
  { name: 'Redis (cache/rate-limit/locks)', category: 'module', status: 'PLANNED', detail: 'Joins with multi-service deployment (Phase 4+)' },
  { name: 'Paper trading simulation', category: 'mode', status: 'PLANNED', detail: 'Phase 3' },
  { name: 'Trade Execution Supervisor', category: 'engine', status: 'PLANNED', detail: 'Phase 5 — 15-step pipeline design locked in spec' },

  // Brokers & exchanges
  { name: 'BrokerConnector abstraction', category: 'broker', status: 'PLANNED', detail: 'Interface designed (spec §9); implementation begins Phase 4' },
  { name: 'MT5 Connector', category: 'broker', status: 'PLANNED', detail: 'Phase 4 — first broker, Python bridge architecture' },
  { name: 'MT4 Connector', category: 'broker', status: 'PLANNED', detail: 'After MT5' },
  { name: 'Binance trading connector', category: 'exchange', status: 'PLANNED', detail: 'Phase 4+ — market data only today; no order endpoints implemented' },
  { name: 'Bybit / OKX / Coinbase / Kraken', category: 'exchange', status: 'PLANNED', detail: 'One at a time after MT5, per build order' },
  { name: 'Interactive Brokers / Alpaca / OANDA', category: 'broker', status: 'PLANNED', detail: 'Phase 4+' },

  // Modes
  { name: 'ANALYSIS_ONLY mode', category: 'mode', status: 'TESTED', detail: 'Active default — analysis pipeline with zero order capability' },
  { name: 'PAPER_TRADING mode', category: 'mode', status: 'PLANNED', detail: 'Phase 3' },
  { name: 'HUMAN_APPROVAL mode', category: 'mode', status: 'PLANNED', detail: 'Phase 5' },
  { name: 'SEMI_AUTONOMOUS mode', category: 'mode', status: 'PLANNED', detail: 'Phase 5' },
  { name: 'FULLY_AUTOMATED mode', category: 'mode', status: 'PLANNED', detail: 'Phase 5 — gated on broker health, risk approval, kill switch, audit logging' },
  { name: 'Kill switch', category: 'engine', status: 'TESTED', detail: 'Ships ACTIVE; Risk Engine vetoes every proposal while active' },
];

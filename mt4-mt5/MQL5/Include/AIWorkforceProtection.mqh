//+------------------------------------------------------------------+
//|                                        AIWorkforceProtection.mqh |
//|                     AI WORKFORCE — Automatic Kill Switch (spec §10)|
//|                                                                   |
//|  Shared protection engine for MT5 Expert Advisors.                |
//|                                                                   |
//|  An EA runs inside the terminal, often with nobody watching it.    |
//|  This library gives it the same automatic protection the platform  |
//|  applies to every other trading surface:                          |
//|                                                                   |
//|    1  terminal connection lost          8  repeated order failures |
//|    2  broker / trade server lost        9  daily loss — percent    |
//|    3  market data unavailable          10  daily loss — fixed      |
//|    4  quote stale (tick age)           11  maximum drawdown        |
//|    5  abnormal price feed              12  margin level floor      |
//|    6  spread above maximum             13  high-impact news window |
//|    7  slippage above maximum           14  platform decision stale |
//|                                                                   |
//|  Enforcement is LOCAL and immediate: AIWF_AllowNewTrades() is the  |
//|  gate every EA calls before it sends an order. The platform's      |
//|  decision is an additional, stricter opinion that arrives through  |
//|  the bridge — never a looser one. When the two disagree, the       |
//|  stricter wins (spec §11: nothing may bypass an active kill).      |
//|                                                                   |
//|  Usage in an EA:                                                  |
//|     #include <AIWorkforceProtection.mqh>                           |
//|     int OnInit()           { return AIWF_Init(InpMagic); }         |
//|     void OnTick()          { AIWF_OnTick();  ...your logic... }    |
//|     if (AIWF_AllowNewTrades()) { ...send the order... }            |
//|       AIWF_RecordOrderResult(success, slippagePoints);             |
//|                                                                   |
//|  Status is drawn on the chart by AIWF_OnTick(); there is no manual |
//|  on/off control anywhere — that is the point (spec §8).            |
//+------------------------------------------------------------------+
#ifndef __AIWORKFORCE_PROTECTION_MQH__
#define __AIWORKFORCE_PROTECTION_MQH__

#include <Trade\Trade.mqh>

// ─── Protection states (§7 — identical to the platform's states) ────
enum ENUM_AIWF_STATE
  {
   AIWF_NORMAL   = 0,  // 🟢 no condition active
   AIWF_WARNING  = 1,  // 🟠 approaching a threshold, trading still allowed
   AIWF_PAUSED   = 2,  // 🟠 temporary block, monitoring for the way back
   AIWF_KILL     = 3,  // 🔴 critical — new trades blocked, emergency actions
   AIWF_RECOVERY = 4,  // 🟡 conditions clear, confirmation scans running
   AIWF_RESUMED  = 5   // 🔵 restarted automatically
  };

// ─── Inputs: the administrator's policy, mirrored into the terminal ─
// An EA cannot read the platform's database, so the limits travel with
// the deployment. The platform's decision OVERRIDES these with the same
// policy when it is reachable; this copy is what protects the account
// when it is not.
input bool   InpProtectionEnabled       = true;    // Protection enabled
input double InpDailyLossPercent        = 3.0;     // Max daily loss (% of equity)
input double InpDailyLossFixedUsd       = 0.0;     // Max daily loss (account currency, 0 = off)
input double InpMaxDrawdownPercent      = 10.0;    // Max drawdown from equity peak (%)
input double InpMaxSpreadPoints         = 30.0;    // Max spread (points)
input double InpMaxSlippagePoints       = 10.0;    // Max slippage (points)
input int    InpMaxOrderFailures        = 3;       // Consecutive order failures before blocking
input int    InpMaxTickAgeSeconds       = 60;      // Stale quote threshold (seconds)
input double InpMarginLevelFloorPercent = 150.0;   // Margin level floor (%, 0 = off)
input double InpAbnormalPriceMovePercent= 5.0;     // Abnormal tick move (%, 0 = off)

input int    InpNewsMinutesBefore       = 5;       // News: minutes before a high-impact event
input int    InpNewsMinutesAfter        = 30;      // News: minutes after a high-impact event
input string InpNewsEvents              = "";      // News: "yyyy.mm.dd hh:mi" UTC list, ';' separated

input bool   InpClosePositionsOnKill    = false;   // Emergency: close positions on AUTOMATIC_KILL
input bool   InpCancelPendingOnKill     = false;   // Emergency: cancel pending orders on AUTOMATIC_KILL
input int    InpRecoveryScansRequired   = 2;       // Consecutive clear evaluations before resuming

input long   InpMagic                   = 0;       // Magic number this EA protects (0 = all)
input string InpSymbol                  = "";      // Symbol to monitor ("" = chart symbol)

// Reporting to the platform through the bridge (python-services/mt5-bridge)
input bool   InpReportToPlatform        = true;    // Report heartbeats and pull decisions
input string InpBridgeUrl               = "http://127.0.0.1:8787";
input string InpBridgeToken             = "";      // MT5_BRIDGE_TOKEN (shared secret)
input int    InpHeartbeatSeconds        = 15;      // Heartbeat interval (seconds)
input bool   InpRequirePlatformDecision = false;   // Block trading when the platform cannot be reached
input int    InpDecisionMaxAgeSeconds   = 180;     // Platform decision staleness limit (seconds)

// ─── Internal state ────────────────────────────────────────────────
int      g_state              = AIWF_NORMAL;
string   g_code               = "";
string   g_reason             = "Protection initialising.";
datetime g_stateSince         = 0;
int      g_clearScans         = 0;

double   g_dayStartEquity     = 0.0;
int      g_dayKey             = 0;
double   g_peakEquity         = 0.0;

int      g_orderFailures      = 0;
double   g_slippagePoints     = 0.0;
datetime g_lastTickTime       = 0;
double   g_lastPrice          = 0.0;
double   g_lastMovePercent    = 0.0;
int      g_newsParsedCount    = 0;
int      g_blockedOrders      = 0;
int      g_closedPositions    = 0;
int      g_cancelledOrders    = 0;

// The platform's opinion, published by the bridge (see README "Decision format").
int      g_platformState      = -1;      // -1 = never received
string   g_platformCode       = "";
string   g_platformReason     = "";
bool     g_platformAllow      = true;
bool     g_platformClose      = false;
bool     g_platformCancel     = false;
datetime g_platformDecisionAt = 0;
datetime g_lastHeartbeatAt    = 0;

CTrade   g_trade;

// ─── Small helpers ─────────────────────────────────────────────────
string AIWF_Symbol()
  {
   return(InpSymbol == "" ? _Symbol : InpSymbol);
  }

// MT4/MT5 "points": one point is the smallest price change the terminal
// quotes for the symbol — a pip is ten of them on a 5-digit FX feed.
// The platform uses the same convention (ProtectionPolicy::pointSize).
double AIWF_PointSize(const string symbol)
  {
   double p = SymbolInfoDouble(symbol, SYMBOL_POINT);
   return(p > 0.0 ? p : 0.00001);
  }

double AIWF_Equity()
  {
   return(AccountInfoDouble(ACCOUNT_EQUITY));
  }

double AIWF_Balance()
  {
   return(AccountInfoDouble(ACCOUNT_BALANCE));
  }

double AIWF_DailyPnl()
  {
   return(AIWF_Equity() - g_dayStartEquity);
  }

double AIWF_DrawdownPercent()
  {
   double equity = AIWF_Equity();
   if(g_peakEquity <= 0.0 || equity >= g_peakEquity) return(0.0);
   return((g_peakEquity - equity) / g_peakEquity * 100.0);
  }

double AIWF_MarginLevel()
  {
   double equity = AIWF_Equity();
   double margin = AccountInfoDouble(ACCOUNT_MARGIN);
   if(margin <= 0.0) return(0.0);
   return(equity / margin * 100.0);
  }

int AIWF_CountPositions(const string symbol, const long magic)
  {
   int count = 0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0) continue;
      if(magic > 0 && PositionGetInteger(POSITION_MAGIC) != magic) continue;
      if(symbol != "" && PositionGetString(POSITION_SYMBOL) != symbol) continue;
      count++;
     }
   return(count);
  }

int AIWF_CountPending(const string symbol, const long magic)
  {
   int count = 0;
   for(int i = OrdersTotal() - 1; i >= 0; i--)
     {
      ulong ticket = OrderGetTicket(i);
      if(ticket == 0) continue;
      if(magic > 0 && OrderGetInteger(ORDER_MAGIC) != magic) continue;
      if(symbol != "" && OrderGetString(ORDER_SYMBOL) != symbol) continue;
      count++;
     }
   return(count);
  }

// ─── News window (§1) ──────────────────────────────────────────────
// InpNewsEvents holds terminal-time event stamps: "yyyy.mm.dd hh:mi" separated
// by ';'. Terminal time is the broker's server time; keep the list in that
// timezone. The platform publishes the same windows through its decision, so
// an operator usually maintains the list in one place (Admin → API calendar)
// and copies it here as a fallback for when the platform is unreachable.
// Returns the minutes to the nearest event (negative = it has just passed),
// or EMPTY_VALUE when no event is configured.
double AIWF_MinutesToNextEvent()
  {
   if(InpNewsEvents == "") return(EMPTY_VALUE);
   string parts[];
   int n = StringSplit(InpNewsEvents, ';', parts);
   double best = EMPTY_VALUE;
   datetime now = TimeCurrent();
   g_newsParsedCount = 0;
   for(int i = 0; i < n; i++)
     {
      datetime when = StringToTime(parts[i]);
      if(when <= 0) continue;
      g_newsParsedCount++;
      double minutes = (double)(when - now) / 60.0;
      if(best == EMPTY_VALUE || MathAbs(minutes) < MathAbs(best)) best = minutes;
     }
   return(best);
  }

// ─── Local evaluation: every condition the terminal can see (§1–§12) ─
// Returns the worst state found. Each condition sets g_code/g_reason so the
// chart and the heartbeat always explain themselves.
int AIWF_EvaluateLocal()
  {
   string symbol = AIWF_Symbol();
   int    state  = AIWF_NORMAL;
   g_code = "";
   g_reason = "No risk conditions detected.";

   // Terminal and broker connectivity (1, 2)
   if(!TerminalInfoInteger(TERMINAL_CONNECTED))
      return(AIWF_Set(AIWF_PAUSED, "EA_TERMINAL_DISCONNECTED", "Terminal is not connected to the broker."));
   if(!AccountInfoInteger(ACCOUNT_TRADE_EXPERT))
      return(AIWF_Set(AIWF_PAUSED, "EA_BROKER_DISCONNECTED", "Automated trading is not allowed on this account (broker or terminal setting)."));
   if((ENUM_SYMBOL_TRADE_MODE)SymbolInfoInteger(symbol, SYMBOL_TRADE_MODE) == SYMBOL_TRADE_MODE_DISABLED)
      return(AIWF_Set(AIWF_PAUSED, "EA_BROKER_DISCONNECTED", "Trading is disabled for " + symbol + "."));

   // Market data available, fresh and sane (3, 4, 5)
   MqlTick tick;
   if(!SymbolInfoTick(symbol, tick))
      return(AIWF_Set(AIWF_PAUSED, "EA_DATA_FEED_UNAVAILABLE", "No tick available for " + symbol + " (market data unavailable)."));

   datetime tickTime = (datetime)tick.time;
   if(tickTime > 0) g_lastTickTime = tickTime;
   if(g_lastTickTime > 0)
     {
      int age = (int)(TimeCurrent() - g_lastTickTime);
      if(age > InpMaxTickAgeSeconds)
         return(AIWF_Set(AIWF_PAUSED, "EA_QUOTE_STALE", StringFormat("Last tick is %d s old (limit %d s).", age, InpMaxTickAgeSeconds)));
     }

   double price = (tick.bid > 0.0 ? tick.bid : tick.last);
   if(price <= 0.0)
      return(AIWF_Set(AIWF_PAUSED, "EA_ABNORMAL_PRICE", "The feed reported a non-positive price."));
   // Reported with the heartbeat so the platform sees the same spike; it is
   // reset every tick, so a stale spike cannot pin the account in a pause.
   g_lastMovePercent = 0.0;
   if(InpAbnormalPriceMovePercent > 0.0 && g_lastPrice > 0.0)
     {
      double move = MathAbs(price - g_lastPrice) / g_lastPrice * 100.0;
      g_lastMovePercent = move;
      if(move > InpAbnormalPriceMovePercent)
         return(AIWF_Set(AIWF_PAUSED, "EA_ABNORMAL_PRICE", StringFormat("Price moved %.2f%% in one tick (limit %.2f%%).", move, InpAbnormalPriceMovePercent)));
     }
   g_lastPrice = price;

   // Spread (6, §5)
   double spreadPoints = 0.0;
   if(tick.ask > 0.0 && tick.bid > 0.0)
      spreadPoints = (tick.ask - tick.bid) / AIWF_PointSize(symbol);
   if(InpMaxSpreadPoints > 0.0 && spreadPoints > InpMaxSpreadPoints)
      return(AIWF_Set(AIWF_PAUSED, "EA_SPREAD_EXCEEDED", StringFormat("Spread %.1f points exceeds the %.1f point limit.", spreadPoints, InpMaxSpreadPoints)));

   // Slippage (7, §6)
   if(InpMaxSlippagePoints > 0.0 && g_slippagePoints > InpMaxSlippagePoints)
      return(AIWF_Set(AIWF_PAUSED, "EA_SLIPPAGE_EXCEEDED", StringFormat("Last slippage %.1f points exceeds the %.1f point limit.", g_slippagePoints, InpMaxSlippagePoints)));

   // Repeated order failures (8)
   if(InpMaxOrderFailures > 0 && g_orderFailures >= InpMaxOrderFailures)
      return(AIWF_Set(AIWF_PAUSED, "EA_ORDER_FAILURES", StringFormat("%d consecutive order failure(s) — execution is not reliable.", g_orderFailures)));

   // Margin level (12)
   double marginLevel = AIWF_MarginLevel();
   if(InpMarginLevelFloorPercent > 0.0 && marginLevel > 0.0 && marginLevel < InpMarginLevelFloorPercent)
      return(AIWF_Set(AIWF_PAUSED, "EA_MARGIN_LEVEL_LOW", StringFormat("Margin level %.0f%% is below the %.0f%% floor.", marginLevel, InpMarginLevelFloorPercent)));

   // Daily loss — percentage and fixed amount (9, 10, §2)
   double loss = -AIWF_DailyPnl();
   if(loss > 0.0)
     {
      double lossPct = AIWF_Equity() > 0.0 ? loss / AIWF_Equity() * 100.0 : 0.0;
      bool percentBreach = (InpDailyLossPercent > 0.0 && lossPct >= InpDailyLossPercent);
      bool fixedBreach   = (InpDailyLossFixedUsd > 0.0 && loss >= InpDailyLossFixedUsd);
      if(percentBreach || fixedBreach)
        {
         if(fixedBreach)
            return(AIWF_Set(AIWF_KILL, "EA_DAILY_LOSS_LIMIT",
                   StringFormat("Daily loss %.2f reached the fixed limit of %.2f.", loss, InpDailyLossFixedUsd)));
         return(AIWF_Set(AIWF_KILL, "EA_DAILY_LOSS_LIMIT",
                StringFormat("Daily loss reached %.2f%% of equity (limit %.2f%%).", lossPct, InpDailyLossPercent)));
        }
      // Warnings do not block; they tell the operator the limit is close (§7).
      double nearest = 0.0;
      if(InpDailyLossPercent > 0.0) nearest = lossPct / InpDailyLossPercent;
      if(InpDailyLossFixedUsd > 0.0) nearest = MathMax(nearest, loss / InpDailyLossFixedUsd);
      if(nearest >= 0.8)
         AIWF_Set(AIWF_WARNING, "EA_DAILY_LOSS_APPROACHING", StringFormat("Daily loss at %.0f%% of the configured limit.", MathMin(100.0, nearest * 100.0)));
     }

   // Drawdown (11, §3)
   double drawdown = AIWF_DrawdownPercent();
   if(InpMaxDrawdownPercent > 0.0 && drawdown > 0.0)
     {
      if(drawdown >= InpMaxDrawdownPercent)
         return(AIWF_Set(AIWF_KILL, "EA_MAX_DRAWDOWN",
                StringFormat("Drawdown %.2f%% reached the %.2f%% limit.", drawdown, InpMaxDrawdownPercent)));
      if((drawdown / InpMaxDrawdownPercent) >= 0.8)
         AIWF_Set(AIWF_WARNING, "EA_DRAWDOWN_APPROACHING",
                  StringFormat("Drawdown at %.0f%% of the configured maximum.", MathMin(100.0, drawdown / InpMaxDrawdownPercent * 100.0)));
     }

   // News window (13, §1). A list that is configured but cannot be read is a
   // feed failure, not an empty calendar: a typo must not silently disable
   // news protection, so it pauses (§12, the platform's onFeedFailure default).
   double minutes = AIWF_MinutesToNextEvent();
   if(InpNewsEvents != "" && g_newsParsedCount == 0)
      return(AIWF_Set(AIWF_PAUSED, "EA_NEWS_FEED_UNAVAILABLE",
             "The configured news event list could not be read, so an event cannot be ruled out."));
   if(minutes != EMPTY_VALUE)
     {
      if(minutes <= (double)InpNewsMinutesBefore && minutes >= -(double)InpNewsMinutesAfter)
        {
         if(minutes >= 0.0)
            return(AIWF_Set(AIWF_PAUSED, "EA_NEWS_EVENT", StringFormat("High-impact event in %.0f minute(s).", MathCeil(minutes))));
         return(AIWF_Set(AIWF_PAUSED, "EA_NEWS_EVENT", StringFormat("High-impact event %.0f minute(s) ago.", MathAbs(minutes))));
        }
      if(minutes > (double)InpNewsMinutesBefore && minutes <= (double)(InpNewsMinutesBefore + 15))
         AIWF_Set(AIWF_WARNING, "EA_NEWS_APPROACHING", StringFormat("High-impact event in %.0f minute(s).", MathCeil(minutes)));
     }

   // Only the warnings (if any) remain — a warning never blocks trading.
   if(g_code == "EA_DAILY_LOSS_APPROACHING" || g_code == "EA_DRAWDOWN_APPROACHING" || g_code == "EA_NEWS_APPROACHING")
      return(AIWF_WARNING);
   return(AIWF_NORMAL);
  }

// Record the state/code/reason of a detected condition.
int AIWF_Set(const int state, const string code, const string reason)
  {
   g_code   = code;
   g_reason = reason;
   return(state);
  }

// ─── State machine (§7) ────────────────────────────────────────────
// The local evaluation is the target; the machine adds hysteresis so a
// condition that flickers cannot flap the account in and out of trading.
void AIWF_ApplyState(const int target)
  {
   datetime now = TimeCurrent();

   if(target == AIWF_KILL)
     {
      // A kill is never downgraded to a pause: once critical, stay critical
      // until the account is deliberately reset (restart or new trading day).
      if(g_state != AIWF_KILL) { g_state = AIWF_KILL; g_stateSince = now; }
      g_clearScans = 0;
      AIWF_EmergencyActions();
      return;
     }

   if(target == AIWF_PAUSED)
     {
      if(g_state != AIWF_PAUSED) { g_state = AIWF_PAUSED; g_stateSince = now; }
      g_clearScans = 0;
      return;
     }

   // Clear (NORMAL or WARNING): recovery needs confirmation scans (§7).
   if(g_state == AIWF_PAUSED || g_state == AIWF_RECOVERY || (g_state == AIWF_KILL && target != AIWF_KILL))
     {
      if(g_state == AIWF_KILL) { g_state = AIWF_RECOVERY; g_stateSince = now; g_clearScans = 0; return; }
      if(g_state != AIWF_RECOVERY) { g_state = AIWF_RECOVERY; g_stateSince = now; g_clearScans = 0; }
      g_clearScans++;
      if(g_clearScans >= MathMax(1, InpRecoveryScansRequired))
        {
         g_state      = AIWF_RESUMED;
         g_stateSince = now;
         g_reason     = "All configured safety conditions are clear — trading resumed automatically.";
         g_code       = "";
        }
      else
        {
         g_reason = StringFormat("Conditions are clear (%d/%d confirmation scans) — monitoring before trading resumes.",
                                 g_clearScans, MathMax(1, InpRecoveryScansRequired));
         g_code   = "";
        }
      return;
     }

   // Steady state: warnings are reported, they do not stop trading.
   if(target == AIWF_WARNING && (g_state == AIWF_NORMAL || g_state == AIWF_RESUMED))
     {
      g_state = AIWF_WARNING;
      return;
     }
   if(target == AIWF_NORMAL && (g_state == AIWF_WARNING || g_state == AIWF_RESUMED))
     {
      g_state      = AIWF_NORMAL;
      g_code       = "";
      g_reason     = "No risk conditions detected.";
     }
  }

// ─── Emergency actions (opt-in — closing is money-moving) ──────────
// New trades are ALWAYS blocked while protection is active. Closing open
// positions and cancelling pending orders only happen when the
// administrator enabled them (locally here, or in the platform policy).
void AIWF_EmergencyActions()
  {
   bool doClose  = InpClosePositionsOnKill  || g_platformClose;
   bool doCancel = InpCancelPendingOnKill   || g_platformCancel;

   if(doCancel)
     {
      for(int i = OrdersTotal() - 1; i >= 0; i--)
        {
         ulong ticket = OrderGetTicket(i);
         if(ticket == 0) continue;
         if(InpMagic > 0 && OrderGetInteger(ORDER_MAGIC) != InpMagic) continue;
         bool cancelledNow = g_trade.OrderDelete(ticket);
         if(cancelledNow) g_cancelledOrders++;
         AIWF_RecordOrderResult(g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
        }
     }

   if(doClose)
     {
      for(int i = PositionsTotal() - 1; i >= 0; i--)
        {
         ulong ticket = PositionGetTicket(i);
         if(ticket == 0) continue;
         if(InpMagic > 0 && PositionGetInteger(POSITION_MAGIC) != InpMagic) continue;
         bool closedNow = g_trade.PositionClose(ticket);
         if(closedNow) g_closedPositions++;
         AIWF_RecordOrderResult(g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
        }
     }
  }

// ─── The gate (§11) ────────────────────────────────────────────────
// Every EA must ask before it sends an order. The answer is the stricter of
// the local evaluation and the platform's published decision.
bool AIWF_AllowNewTrades()
  {
   if(!InpProtectionEnabled) return(true);

   bool localBlocked = (g_state == AIWF_KILL || g_state == AIWF_PAUSED || g_state == AIWF_RECOVERY);
   if(localBlocked) return(false);

   if(!InpReportToPlatform) return(true);

   // 14 — the platform's decision. Unknown is not the same as clear (§12):
   // when the operator demanded a fresh decision, silence blocks trading.
   if(g_platformState < 0)
      return(InpRequirePlatformDecision ? false : true);

   int age = (int)(TimeCurrent() - g_platformDecisionAt);
   if(age > InpDecisionMaxAgeSeconds)
      return(InpRequirePlatformDecision ? false : true);

   return(g_platformAllow);
  }

string AIWF_BlockReason()
  {
   if(!InpProtectionEnabled) return("");
   if(g_state == AIWF_KILL || g_state == AIWF_PAUSED || g_state == AIWF_RECOVERY) return(g_reason);
   if(InpReportToPlatform && g_platformState >= 0 && !g_platformAllow && g_platformReason != "")
      return(g_platformReason);
   return("");
  }

// ─── Reporting: heartbeat out, decision in ─────────────────────────
// The bridge (python-services/mt5-bridge) runs on the same Windows host as
// the terminal, so the EA never needs to reach the internet. Add the bridge
// URL to Terminal → Options → Expert Advisors → "Allow WebRequest for listed
// URL" or the calls below simply fail (and protection keeps working locally).
string AIWF_HeartbeatJson()
  {
   string symbol = AIWF_Symbol();
   MqlTick tick;
   bool   haveTick = SymbolInfoTick(symbol, tick);
   double spread   = (haveTick && tick.ask > 0.0 && tick.bid > 0.0) ? (tick.ask - tick.bid) / AIWF_PointSize(symbol) : 0.0;
   int    tickAge  = (g_lastTickTime > 0) ? (int)(TimeCurrent() - g_lastTickTime) : 0;

   return(StringFormat(
      "{"
      "\"eaId\":\"%s\",\"name\":\"%s\",\"terminal\":\"MT5\",\"account\":\"%s\",\"broker\":\"%s\","
      "\"symbol\":\"%s\",\"magic\":%I64d,\"version\":\"1.0.0\",\"at\":\"%s\",\"atTs\":%I64d,"
      "\"metrics\":{\"equity\":%.2f,\"balance\":%.2f,\"dailyPnl\":%.2f,\"drawdownPct\":%.2f,\"peakEquity\":%.2f,"
      "\"openPositions\":%d,\"pendingOrders\":%d,\"marginLevelPct\":%.2f,\"freeMargin\":%.2f,"
      "\"spreadPoints\":%.2f,\"slippagePoints\":%.2f,\"priceMovePercent\":%.2f,\"orderFailures\":%d,\"symbol\":\"%s\"},"
      "\"connection\":{\"terminal\":%s,\"broker\":%s,\"dataFeed\":%s,\"lastTickAgeSeconds\":%d},"
      "\"news\":{\"configured\":%s,\"ok\":true,\"minutesToNextHighImpact\":%s},"
      "\"actions\":{\"closedPositions\":%d,\"cancelledOrders\":%d,\"blockedOrders\":%d}"
      "}",
      AIWF_EaId(), MQLInfoString(MQL_PROGRAM_NAME),
      (string)AccountInfoInteger(ACCOUNT_LOGIN), AIWF_Company(),
      symbol, InpMagic, AIWF_NowIso(), (long)TimeCurrent(),
      AIWF_Equity(), AIWF_Balance(), AIWF_DailyPnl(), AIWF_DrawdownPercent(), g_peakEquity,
      AIWF_CountPositions(symbol, InpMagic), AIWF_CountPending(symbol, InpMagic), AIWF_MarginLevel(),
      AccountInfoDouble(ACCOUNT_MARGIN_FREE), spread, g_slippagePoints, g_lastMovePercent, g_orderFailures, symbol,
      TerminalInfoInteger(TERMINAL_CONNECTED) ? "true" : "false",
      AccountInfoInteger(ACCOUNT_TRADE_EXPERT) ? "true" : "false",
      haveTick ? "true" : "false", tickAge,
      (InpNewsEvents == "") ? "false" : "true",
      AIWF_JsonMinutes(), g_closedPositions, g_cancelledOrders, g_blockedOrders));
  }

// Stable identity: terminal account + chart symbol + magic + EA name, so the
// platform can tell two copies of the same EA on two accounts apart.
string AIWF_EaId()
  {
   return(StringFormat("%I64d-%s-%I64d-%s",
          (long)AccountInfoInteger(ACCOUNT_LOGIN),
          AIWF_Symbol(),
          (long)InpMagic,
          MQLInfoString(MQL_PROGRAM_NAME)));
  }

string AIWF_Company()
  {
   string broker = AccountInfoString(ACCOUNT_COMPANY);
   return(broker == "" ? "unknown" : broker);
  }

string AIWF_NowIso()
  {
   MqlDateTime dt;
   TimeToStruct(TimeCurrent(), dt);
   return(StringFormat("%04d-%02d-%02dT%02d:%02d:%02dZ", dt.year, dt.mon, dt.day, dt.hour, dt.min, dt.sec));
  }

string AIWF_JsonMinutes()
  {
   double minutes = AIWF_MinutesToNextEvent();
   if(minutes == EMPTY_VALUE) return("null");
   return(StringFormat("%.1f", minutes));
  }

// The bridge answers with flat KEY=VALUE lines (no JSON parser needed in MQL).
// See mt4-mt5/README.md — "Decision format".
void AIWF_ApplyDecision(const string text)
  {
   if(text == "") return;
   string lines[];
   int n = StringSplit(text, '\n', lines);
   bool any = false;
   for(int i = 0; i < n; i++)
     {
      string line = lines[i];
      int eq = StringFind(line, "=");
      if(eq <= 0) continue;
      string key   = StringSubstr(line, 0, eq);
      string value = StringSubstr(line, eq + 1);
      if(key == "state")                   { g_platformState = AIWF_StateFromName(value); any = true; }
      else if(key == "code")               g_platformCode   = value;
      else if(key == "reason")             g_platformReason = value;
      else if(key == "allowNewTrades")     g_platformAllow  = (value == "1" || value == "true");
      else if(key == "closePositions")     g_platformClose  = (value == "1" || value == "true");
      else if(key == "cancelPendingOrders")g_platformCancel = (value == "1" || value == "true");
     }
   if(any) g_platformDecisionAt = TimeCurrent();
  }

int AIWF_StateFromName(const string name)
  {
   if(name == "NORMAL")           return(AIWF_NORMAL);
   if(name == "WARNING")          return(AIWF_WARNING);
   if(name == "AUTOMATIC_PAUSED") return(AIWF_PAUSED);
   if(name == "AUTOMATIC_KILL")   return(AIWF_KILL);
   if(name == "RECOVERY")         return(AIWF_RECOVERY);
   if(name == "RESUMED")          return(AIWF_RESUMED);
   return(-1);
  }

// One HTTP round trip: POST the heartbeat, read the decision for this EA.
void AIWF_SendHeartbeat()
  {
   if(!InpReportToPlatform || InpBridgeUrl == "") return;
   g_lastHeartbeatAt = TimeCurrent();

   string url      = InpBridgeUrl + "/v1/ea/heartbeat";
   string decision = InpBridgeUrl + "/v1/ea/decision/" + AIWF_EaId() + "/text";
   string headers  = "Content-Type: application/json\r\n";
   if(InpBridgeToken != "") headers += "Authorization: Bearer " + InpBridgeToken + "\r\n";

   char data[], result[];
   string resultHeaders;
   // -1 drops the trailing terminating zero the string conversion appends.
   ArrayResize(data, StringToCharArray(AIWF_HeartbeatJson(), data, 0, WHOLE_ARRAY, CP_UTF8) - 1);
   int status = WebRequest("POST", url, headers, 3000, data, result, resultHeaders);
   if(status == -1)
     {
      // WebRequest is blocked (URL not whitelisted) or the bridge is down.
      // Protection keeps running locally — that is what the local gate is for.
      Print("AI WORKFORCE: heartbeat failed (", GetLastError(), ") — check Terminal → Options → Expert Advisors → Allow WebRequest for ", InpBridgeUrl);
      return;
     }

   char decisionData[];
   ArrayResize(decisionData, 0);
   string decisionHeaders = "";
   char decisionResult[];
   int code = WebRequest("GET", decision, headers, 3000, decisionData, decisionResult, decisionHeaders);
   if(code != -1)
      AIWF_ApplyDecision(CharArrayToString(decisionResult, 0, WHOLE_ARRAY, CP_UTF8));
  }

// ─── Order bookkeeping ─────────────────────────────────────────────
// Call after every order attempt. Failures count up (8) and a success resets
// them; slippage is recorded in points and evaluated on the next tick (7).
void AIWF_RecordOrderResult(const bool success, const double slippagePoints = 0.0)
  {
   if(success)
     {
      g_orderFailures  = 0;
      g_slippagePoints = slippagePoints;
      return;
     }
   g_orderFailures++;
   Print("AI WORKFORCE: order failed (", g_orderFailures, " consecutive) — ", g_trade.ResultRetcodeDescription());
  }

// ─── Lifecycle ─────────────────────────────────────────────────────
int AIWF_Init(const long magic)
  {
   g_trade.SetExpertMagicNumber(magic);
   g_trade.SetDeviationInPoints((ulong)MathMax(1, (int)InpMaxSlippagePoints));
   g_state         = AIWF_NORMAL;
   g_code          = "";
   g_reason        = "Protection initialising.";
   g_stateSince    = TimeCurrent();
   g_clearScans    = 0;
   g_orderFailures = 0;
   g_slippagePoints= 0.0;
   g_dayStartEquity= AIWF_Equity();
   g_peakEquity    = MathMax(AIWF_Equity(), AIWF_Balance());
   g_dayKey        = AIWF_DayKey();
   g_lastTickTime  = 0;
   g_lastPrice     = 0.0;
   g_platformState = -1;
   Print("AI WORKFORCE: Automatic Kill Switch armed for ", AIWF_EaId(),
         " | daily loss ", DoubleToString(InpDailyLossPercent, 2), "% / ", DoubleToString(InpDailyLossFixedUsd, 2),
         " | drawdown ", DoubleToString(InpMaxDrawdownPercent, 2), "%",
         InpProtectionEnabled ? "" : " | ** PROTECTION DISABLED **");
   return(INIT_SUCCEEDED);
  }

int AIWF_DayKey()
  {
   MqlDateTime dt;
   TimeToStruct(TimeCurrent(), dt);
   return(dt.year * 10000 + dt.mon * 100 + dt.day);
  }

// Roll the daily counters and the equity high-water mark at the start of a
// new trading day; §2/§3 are per-day and per-peak limits respectively.
void AIWF_RolloverDay()
  {
   int key = AIWF_DayKey();
   if(key != g_dayKey)
     {
      g_dayKey         = key;
      g_dayStartEquity = AIWF_Equity();
      Print("AI WORKFORCE: new trading day — daily loss counters reset at equity ", DoubleToString(g_dayStartEquity, 2));
     }
   double equity = AIWF_Equity();
   if(equity > g_peakEquity) g_peakEquity = equity;
  }

// Call at the top of OnTick(). Evaluates, enforces, reports and paints.
void AIWF_OnTick()
  {
   AIWF_RolloverDay();
   if(!InpProtectionEnabled)
     {
      AIWF_DrawStatus();
      return;
     }

   int target = AIWF_EvaluateLocal();

   // The platform's decision is stricter or equal: an active kill there is an
   // active kill here, even if the terminal itself sees nothing wrong (§11).
   if(InpReportToPlatform && g_platformState >= 0 &&
      (int)(TimeCurrent() - g_platformDecisionAt) <= InpDecisionMaxAgeSeconds)
     {
      if(g_platformState == AIWF_KILL && target < AIWF_KILL)
        {
         target   = AIWF_KILL;
         g_code   = (g_platformCode == "" ? "PLATFORM_KILL" : g_platformCode);
         g_reason = (g_platformReason == "" ? "The platform reports an active Automatic Kill Switch." : g_platformReason);
        }
      else if(g_platformState == AIWF_PAUSED && target < AIWF_PAUSED)
        {
         target   = AIWF_PAUSED;
         g_code   = (g_platformCode == "" ? "PLATFORM_PAUSED" : g_platformCode);
         g_reason = (g_platformReason == "" ? "The platform has paused new trades." : g_platformReason);
        }
     }

   AIWF_ApplyState(target);

   if(InpReportToPlatform && (TimeCurrent() - g_lastHeartbeatAt) >= InpHeartbeatSeconds)
      AIWF_SendHeartbeat();

   AIWF_DrawStatus();
  }

// Chart display: status and reason only — there is no manual switch (§8).
void AIWF_DrawStatus()
  {
   string icon, label;
   switch(g_state)
     {
      case AIWF_KILL:     icon = "🔴"; label = "AUTOMATIC KILL"; break;
      case AIWF_PAUSED:   icon = "🟠"; label = "PAUSED";         break;
      case AIWF_RECOVERY: icon = "🟡"; label = "RECOVERY";       break;
      case AIWF_WARNING:  icon = "🟠"; label = "WARNING";        break;
      case AIWF_RESUMED:  icon = "🔵"; label = "RESUMED";        break;
      default:            icon = "🟢"; label = "NORMAL";         break;
     }
   string text = StringFormat("AI WORKFORCE — Automatic Kill Switch\n%s %s\n%s\nNew trades: %s",
                              icon, label, g_reason, AIWF_AllowNewTrades() ? "allowed" : "BLOCKED");
   if(InpReportToPlatform)
     {
      if(g_platformState < 0)
         text += "\nPlatform decision: none received yet";
      else
         text += StringFormat("\nPlatform decision: %d s old", (int)(TimeCurrent() - g_platformDecisionAt));
     }
   Comment(text);
  }

// ─── Introspection for the four EAs ────────────────────────────────
/** Call when the EA refuses an entry because protection is active (§13). */
void AIWF_RecordBlockedOrder()
  {
   g_blockedOrders++;
   Print("AI WORKFORCE: entry refused — ", AIWF_BlockReason());
  }

int      AIWF_State()      { return(g_state); }
string   AIWF_Code()       { return(g_code); }
string   AIWF_Reason()     { return(g_reason); }
bool     AIWF_IsBlocking() { return(g_state == AIWF_KILL || g_state == AIWF_PAUSED || g_state == AIWF_RECOVERY); }
double   AIWF_SpreadPoints()
  {
   MqlTick tick;
   if(!SymbolInfoTick(AIWF_Symbol(), tick) || tick.ask <= 0.0 || tick.bid <= 0.0) return(0.0);
   return((tick.ask - tick.bid) / AIWF_PointSize(AIWF_Symbol()));
  }

// Position size from a risk percentage, capped by free margin — the "Automatic
// Risk Manager" half of §10. Returns 0 when the trade must not be taken.
double AIWF_LotSize(const double stopLossPoints, const double riskPercent)
  {
   if(stopLossPoints <= 0.0 || riskPercent <= 0.0) return(0.0);
   string symbol = AIWF_Symbol();
   double tickValue  = SymbolInfoDouble(symbol, SYMBOL_TRADE_TICK_VALUE);
   double tickSize   = SymbolInfoDouble(symbol, SYMBOL_TRADE_TICK_SIZE);
   double point      = AIWF_PointSize(symbol);
   if(tickValue <= 0.0 || tickSize <= 0.0 || point <= 0.0) return(0.0);

   double riskAmount = AIWF_Equity() * (riskPercent / 100.0);
   // Money lost per lot if the stop is reached: points → ticks → tick value.
   double lossPerLot = (stopLossPoints * point / tickSize) * tickValue;
   if(lossPerLot <= 0.0) return(0.0);
   double lots = riskAmount / lossPerLot;

   double minLot  = SymbolInfoDouble(symbol, SYMBOL_VOLUME_MIN);
   double maxLot  = SymbolInfoDouble(symbol, SYMBOL_VOLUME_MAX);
   double stepLot = SymbolInfoDouble(symbol, SYMBOL_VOLUME_STEP);
   if(stepLot > 0.0) lots = MathFloor(lots / stepLot) * stepLot;
   lots = MathMax(minLot, MathMin(maxLot, lots));
   return(NormalizeDouble(lots, 2));
  }

#endif // __AIWORKFORCE_PROTECTION_MQH__

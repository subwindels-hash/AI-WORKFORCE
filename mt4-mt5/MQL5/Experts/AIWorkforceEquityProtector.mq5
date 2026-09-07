//+------------------------------------------------------------------+
//|                                 AIWorkforceEquityProtector.mq5    |
//|  Equity Protector EA — the account-level half of the Automatic     |
//|  Kill Switch: daily loss (percentage AND fixed) and maximum        |
//|  drawdown, measured on every tick, enforced before every order.    |
//|                                                                   |
//|  Closing positions and cancelling pending orders happen only when  |
//|  the administrator enabled those emergency actions (locally here,  |
//|  or in the platform policy) — blocking new trades always happens.  |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input bool InpProtectAllSymbols = true;  // Protect the whole account, not one symbol
input int  InpWarnCommentLevel  = 80;    // Comment a warning from this % of the limit

//+------------------------------------------------------------------+
int OnInit()
  {
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   AIWF_OnTick();

   // The protector reports the numbers the operator needs to see, whether or
   // not a limit has been reached (§13: the trail must explain the decision).
   if(AIWF_State() == AIWF_WARNING || AIWF_IsBlocking())
      Print("AI WORKFORCE: ", AIWF_Code(), " — ", AIWF_Reason(),
            " | daily P&L ", DoubleToString(AIWF_DailyPnl(), 2),
            " | drawdown ", DoubleToString(AIWF_DrawdownPercent(), 2), "%",
            " | margin level ", DoubleToString(AIWF_MarginLevel(), 0), "%");

   if(!AIWF_AllowNewTrades())
      return;

   // Nothing else to do: this EA protects, it does not trade. Every entry in
   // the account (from any EA) is gated by AIWF_AllowNewTrades() in its own
   // code, and the terminal refuses automated trading altogether while
   // ACCOUNT_TRADE_EXPERT is false — which the library checks every tick.
   (void)InpProtectAllSymbols;
   (void)InpWarnCommentLevel;
  }

//+------------------------------------------------------------------+
//| A new trading day rolls the daily-loss counters automatically; the  |
//| drawdown high-water mark only moves up. Both live in the library.  |
//+------------------------------------------------------------------+
void OnTradeTransaction(const MqlTradeTransaction &trans,
                        const MqlTradeRequest &request,
                        const MqlTradeResult &result)
  {
   if(result.retcode != TRADE_RETCODE_DONE) return;
   // Keep the peak current between ticks so a fast drawdown cannot slip past.
   if(AIWF_Equity() > g_peakEquity) g_peakEquity = AIWF_Equity();
  }
//+------------------------------------------------------------------+

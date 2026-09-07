//+------------------------------------------------------------------+
//|                                 AIWorkforceEquityProtector.mq4    |
//|  Equity Protector EA (MT4) — daily loss (percentage AND fixed) and |
//|  maximum drawdown, measured every tick, enforced before any order. |
//|                                                                   |
//|  Closing and cancelling happen only when the administrator enabled |
//|  those emergency actions; blocking new trades always happens.      |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

//+------------------------------------------------------------------+
int OnInit()
  {
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   AIWF_OnTick();

   if(AIWF_State() == AIWF_WARNING || AIWF_IsBlocking())
      Print("AI WORKFORCE: ", AIWF_Code(), " — ", AIWF_Reason(),
            " | daily P&L ", DoubleToString(AIWF_DailyPnl(), 2),
            " | drawdown ", DoubleToString(AIWF_DrawdownPercent(), 2), "%",
            " | margin level ", DoubleToString(AIWF_MarginLevel(), 0), "%");

   // This EA protects; it does not trade. Every entry anywhere in the account
   // is gated by AIWF_AllowNewTrades() inside its own code.
   if(!AIWF_AllowNewTrades()) return;
  }

//+------------------------------------------------------------------+
//| Keep the equity high-water mark current between ticks so a fast    |
//| drawdown cannot slip past the next evaluation (§3).                |
//+------------------------------------------------------------------+
void OnTrade()
  {
   if(AIWF_Equity() > g_peakEquity) g_peakEquity = AIWF_Equity();
  }
//+------------------------------------------------------------------+

//+------------------------------------------------------------------+
//|                                   AIWorkforceNewsFilter.mq5       |
//|  News Filter EA — blocks entries around high-impact events         |
//|  (NFP, CPI, FOMC, rate decisions, anything the operator lists).    |
//|                                                                   |
//|  The window is [minutesBefore … minutesAfter] around each event,   |
//|  exactly as the platform applies it (§1). The platform's own       |
//|  calendar (Admin → API → Economic Calendar) is authoritative; this  |
//|  list is the fallback that keeps working when it is unreachable.   |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input bool InpFlattenBeforeEvent = false; // Close positions when the window opens
input int  InpFlattenMinutes     = 2;     // …this many minutes before the event
input bool InpAllowEntries       = false; // This EA may open trades (off by default)
input double InpEntryLots        = 0.01;  // Entry size when allowed

//+------------------------------------------------------------------+
int OnInit()
  {
   if(InpNewsEvents == "")
      Print("AI WORKFORCE: no events configured — InpNewsEvents is empty, so no window is enforced locally. "
            "The platform's calendar still applies when it is reachable.");
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   AIWF_OnTick();

   MaybeFlattenBeforeEvent();

   if(!InpAllowEntries) return;
   if(!AIWF_AllowNewTrades())
     {
      Print("AI WORKFORCE: entry refused — ", AIWF_BlockReason());
      return;
     }

   string symbol = AIWF_Symbol();
   MqlTick tick;
   if(!SymbolInfoTick(symbol, tick) || tick.ask <= 0.0) return;
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints) return;

   double point  = AIWF_PointSize(symbol);
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   bool ok = g_trade.Buy(InpEntryLots, symbol, tick.ask,
                         NormalizeDouble(tick.ask - 400.0 * point, digits),
                         NormalizeDouble(tick.ask + 800.0 * point, digits),
                         "ai-workforce news filter");
   AIWF_RecordOrderResult(ok && g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
  }

//+------------------------------------------------------------------+
//| Optional flatten just before the event. Off by default: closing     |
//|  is a money-moving action the administrator opts into (§3).         |
//+------------------------------------------------------------------+
void MaybeFlattenBeforeEvent()
  {
   if(!InpFlattenBeforeEvent) return;
   double minutes = AIWF_MinutesToNextEvent();
   if(minutes == EMPTY_VALUE) return;
   if(minutes > (double)InpFlattenMinutes || minutes < 0.0) return;

   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0) continue;
      if(InpMagic > 0 && PositionGetInteger(POSITION_MAGIC) != InpMagic) continue;
      g_trade.PositionClose(ticket);
      AIWF_RecordOrderResult(g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
     }
  }
//+------------------------------------------------------------------+

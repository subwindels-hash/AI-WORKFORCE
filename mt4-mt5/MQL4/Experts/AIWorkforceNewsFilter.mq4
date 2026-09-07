//+------------------------------------------------------------------+
//|                                   AIWorkforceNewsFilter.mq4       |
//|  News Filter EA (MT4) — blocks entries around high-impact events   |
//|  (NFP, CPI, FOMC, rate decisions, whatever the operator lists).    |
//|                                                                   |
//|  Window = [minutesBefore … minutesAfter], the same rule the        |
//|  platform applies (§1). The platform's calendar is authoritative;  |
//|  InpNewsEvents is the fallback when it cannot be reached.          |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input bool   InpFlattenBeforeEvent = false; // Close positions when the window opens
input int    InpFlattenMinutes     = 2;     // …this many minutes before the event
input bool   InpAllowEntries       = false; // This EA may open trades (off by default)
input double InpEntryLots          = 0.01;  // Entry size when allowed
input int    InpEntryStopPoints    = 400;   // Entry stop distance (points)

//+------------------------------------------------------------------+
int OnInit()
  {
   if(InpNewsEvents == "")
      Print("AI WORKFORCE: InpNewsEvents is empty — no local news window is enforced. "
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
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints) return;

   string symbol = AIWF_Symbol();
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double point  = AIWF_PointSize(symbol);
   RefreshRates();
   double price = MarketInfo(symbol, MODE_ASK);
   if(price <= 0.0) return;

   int ticket = OrderSend(symbol, OP_BUY, InpEntryLots, NormalizeDouble(price, digits),
                          (int)InpMaxSlippagePoints,
                          NormalizeDouble(price - InpEntryStopPoints * point, digits),
                          NormalizeDouble(price + InpEntryStopPoints * 2.0 * point, digits),
                          "ai-workforce news filter", InpMagic, 0, clrNONE);
   AIWF_RecordOrderResult(ticket > 0, 0.0);
  }

//+------------------------------------------------------------------+
//| Optional flatten just before the event (opt-in, money-moving).     |
//+------------------------------------------------------------------+
void MaybeFlattenBeforeEvent()
  {
   if(!InpFlattenBeforeEvent) return;
   double minutes = AIWF_MinutesToNextEvent();
   if(minutes == EMPTY_VALUE) return;
   if(minutes > (double)InpFlattenMinutes || minutes < 0.0) return;

   for(int i = OrdersTotal() - 1; i >= 0; i--)
     {
      if(!OrderSelect(i, SELECT_BY_POS, MODE_TRADES)) continue;
      if(InpMagic > 0 && OrderMagicNumber() != InpMagic) continue;
      if(AIWF_IsPendingType(OrderType())) continue;
      RefreshRates();
      double closePrice = (OrderType() == OP_BUY) ? MarketInfo(OrderSymbol(), MODE_BID) : MarketInfo(OrderSymbol(), MODE_ASK);
      bool ok = OrderClose(OrderTicket(), OrderLots(), closePrice, (int)InpMaxSlippagePoints, clrNONE);
      AIWF_RecordOrderResult(ok, 0.0);
     }
  }
//+------------------------------------------------------------------+

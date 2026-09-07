//+------------------------------------------------------------------+
//|                                 AIWorkforceRiskManager.mq4        |
//|  Automatic Risk Manager EA (MT4) — sizes every position from the   |
//|  risk budget, refuses entries that break spread / slippage /        |
//|  exposure limits, and feeds failures back into protection (§4/§6). |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input double InpRiskPerTradePercent  = 1.0;  // Risk per trade (% of equity)
input int    InpStopLossPoints       = 400;  // Planned stop distance (points)
input int    InpTakeProfitPoints     = 800;  // Planned target distance (points)
input int    InpMaxOpenPositions     = 3;    // Exposure cap for this magic
input int    InpMaxPendingOrders     = 3;    // Pending-order cap for this magic
input double InpMinFreeMarginUsd     = 0.0;  // Refuse entries below this free margin
input bool   InpAllowEntries         = false; // Open entries from this EA
input int    InpEntryCooldownSeconds = 60;   // Minimum gap between entries

datetime g_lastEntryAt = 0;

//+------------------------------------------------------------------+
int OnInit()
  {
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   AIWF_OnTick();
   if(!InpAllowEntries) return;

   if(!AIWF_AllowNewTrades())
     {
      Print("AI WORKFORCE: entry refused — ", AIWF_BlockReason());
      return;
     }
   if((TimeCurrent() - g_lastEntryAt) < (datetime)InpEntryCooldownSeconds) return;
   if(AIWF_CountPositions(AIWF_Symbol(), InpMagic) >= InpMaxOpenPositions) return;
   if(AIWF_CountPending(AIWF_Symbol(), InpMagic) >= InpMaxPendingOrders) return;
   if(InpMinFreeMarginUsd > 0.0 && AccountInfoDouble(ACCOUNT_FREEMARGIN) < InpMinFreeMarginUsd) return;
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints)
     {
      Print("AI WORKFORCE: entry refused — spread ", DoubleToString(AIWF_SpreadPoints(), 1), " points");
      return;
     }

   string symbol = AIWF_Symbol();
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double point  = AIWF_PointSize(symbol);
   double lots   = AIWF_LotSize((double)InpStopLossPoints, InpRiskPerTradePercent);
   if(lots <= 0.0)
     {
      Print("AI WORKFORCE: entry refused — position size could not be computed safely");
      return;
     }

   RefreshRates();
   double price = MarketInfo(symbol, MODE_ASK);
   if(price <= 0.0) return;

   int ticket = OrderSend(symbol, OP_BUY, lots, NormalizeDouble(price, digits), (int)InpMaxSlippagePoints,
                          NormalizeDouble(price - InpStopLossPoints * point, digits),
                          NormalizeDouble(price + InpTakeProfitPoints * point, digits),
                          "ai-workforce risk manager", InpMagic, 0, clrNONE);

   if(ticket > 0)
     {
      // Slippage is measured against the price we asked for — §6 limits that.
      if(OrderSelect(ticket, SELECT_BY_TICKET, MODE_TRADES))
        {
         double slippagePoints = MathAbs(OrderOpenPrice() - price) / point;
         g_lastEntryAt = TimeCurrent();
         AIWF_RecordOrderResult(true, slippagePoints);
         Print("AI WORKFORCE: entry filled ", DoubleToString(lots, 2), " lots, slippage ",
               DoubleToString(slippagePoints, 1), " points");
         return;
        }
     }
   AIWF_RecordOrderResult(false, 0.0);
  }
//+------------------------------------------------------------------+

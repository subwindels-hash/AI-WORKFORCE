//+------------------------------------------------------------------+
//|                                 AIWorkforceRiskManager.mq5        |
//|  Automatic Risk Manager EA — sizes every position from the risk    |
//|  budget, refuses entries that break spread/slippage/exposure       |
//|  limits, and feeds execution failures back into protection (§4/§6).|
//|                                                                   |
//|  It is deliberately signal-free: wire it into whatever decides the |
//|  direction, or leave InpAllowEntries off and let the other EAs call|
//|  AIWF_AllowNewTrades() themselves.                                 |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input double InpRiskPerTradePercent = 1.0;  // Risk per trade (% of equity)
input int    InpStopLossPoints      = 400;  // Planned stop distance (points)
input int    InpTakeProfitPoints    = 800;  // Planned target distance (points)
input int    InpMaxOpenPositions    = 3;    // Exposure cap for this magic
input int    InpMaxPendingOrders    = 3;    // Pending-order cap for this magic
input double InpMinFreeMarginUsd    = 0.0;  // Refuse entries below this free margin
input bool   InpAllowEntries        = false; // Open entries from this EA
input int    InpEntryCooldownSeconds= 60;   // Minimum gap between entries

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
      // Counted and reported with the heartbeat (§13): a refused entry is a
      // protection event, not a silent no-op.
      AIWF_RecordBlockedOrder();
      return;
     }
   if((TimeCurrent() - g_lastEntryAt) < (datetime)InpEntryCooldownSeconds) return;
   if(AIWF_CountPositions(AIWF_Symbol(), InpMagic) >= InpMaxOpenPositions) return;
   if(AIWF_CountPending(AIWF_Symbol(), InpMagic) >= InpMaxPendingOrders) return;
   if(InpMinFreeMarginUsd > 0.0 && AccountInfoDouble(ACCOUNT_MARGIN_FREE) < InpMinFreeMarginUsd) return;
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints)
     {
      Print("AI WORKFORCE: entry refused — spread ", DoubleToString(AIWF_SpreadPoints(), 1),
            " points exceeds the ", DoubleToString(InpMaxSpreadPoints, 1), " point limit");
      return;
     }

   string symbol = AIWF_Symbol();
   MqlTick tick;
   if(!SymbolInfoTick(symbol, tick) || tick.ask <= 0.0) return;

   double lots = AIWF_LotSize((double)InpStopLossPoints, InpRiskPerTradePercent);
   if(lots <= 0.0)
     {
      Print("AI WORKFORCE: entry refused — position size could not be computed safely");
      return;
     }

   double point  = AIWF_PointSize(symbol);
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double price  = tick.ask;

   // Slippage is measured against the requested price — that is the number
   // §6 limits, and it feeds the next tick's evaluation.
   bool ok = g_trade.Buy(lots, symbol, price,
                         NormalizeDouble(price - InpStopLossPoints * point, digits),
                         NormalizeDouble(price + InpTakeProfitPoints * point, digits),
                         "ai-workforce risk manager");
   ulong retcode = g_trade.ResultRetcode();
   if(ok && retcode == TRADE_RETCODE_DONE)
     {
      double filled = g_trade.ResultPrice();
      double slippagePoints = (filled > 0.0) ? MathAbs(filled - price) / point : 0.0;
      g_lastEntryAt = TimeCurrent();
      AIWF_RecordOrderResult(true, slippagePoints);
      Print("AI WORKFORCE: entry filled ", DoubleToString(lots, 2), " lots, slippage ",
            DoubleToString(slippagePoints, 1), " points");
     }
   else
     {
      AIWF_RecordOrderResult(false, 0.0);
     }
  }
//+------------------------------------------------------------------+

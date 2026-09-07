//+------------------------------------------------------------------+
//|                                    AIWorkforceTradeManager.mq5    |
//|  Trade Manager EA — positions are managed by AI WORKFORCE policy   |
//|  and no new entry is ever sent while protection is active (§11).   |
//|                                                                   |
//|  This EA does not invent signals. It manages the positions a       |
//|  strategy (or a human) opens: break-even, trailing stop, partial   |
//|  protection, and a hard refusal to open anything new while the     |
//|  Automatic Kill Switch is engaged.                                |
//+------------------------------------------------------------------+
#property copyright "AI WORKFORCE"
#property link      "https://github.com/subwindels-hash/AI-WORKFORCE"
#property version   "1.00"
#property strict

#include <AIWorkforceProtection.mqh>

input bool   InpManageAllSymbols = false; // Manage every symbol (false = chart symbol)
input bool   InpBreakEven        = true;  // Move stop to break-even
input double InpBreakEvenPoints  = 200.0; // Break-even after this profit (points)
input double InpBreakEvenLock    = 20.0;  // Lock this many points at break-even
input bool   InpTrailing         = true;  // Trail the stop
input double InpTrailPoints      = 300.0; // Trail distance (points)
input double InpTrailStepPoints  = 50.0;  // Minimum improvement before moving (points)
input bool   InpAllowDemoEntries = false; // Open a demo entry when flat (testing only)
input double InpDemoLots         = 0.01;  // Demo entry size (lots)
input int    InpDemoStopPoints   = 400.0; // Demo entry stop distance (points)

//+------------------------------------------------------------------+
int OnInit()
  {
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   // Evaluate protection FIRST — every decision below depends on it.
   AIWF_OnTick();

   ManageOpenPositions();

   if(InpAllowDemoEntries && AIWF_CountPositions(AIWF_Symbol(), InpMagic) == 0)
      TryOpenDemoEntry();
  }

//+------------------------------------------------------------------+
//| Break-even and trailing for the positions this EA owns.           |
//+------------------------------------------------------------------+
void ManageOpenPositions()
  {
   for(int i = PositionsTotal() - 1; i >= 0; i--)
     {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0) continue;
      if(InpMagic > 0 && PositionGetInteger(POSITION_MAGIC) != InpMagic) continue;

      string symbol = PositionGetString(POSITION_SYMBOL);
      if(!InpManageAllSymbols && symbol != _Symbol) continue;

      double point   = AIWF_PointSize(symbol);
      double open    = PositionGetDouble(POSITION_PRICE_OPEN);
      double sl      = PositionGetDouble(POSITION_SL);
      double tp      = PositionGetDouble(POSITION_TP);
      double volume  = PositionGetDouble(POSITION_VOLUME);
      bool   isBuy   = ((ENUM_POSITION_TYPE)PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY);

      MqlTick tick;
      if(!SymbolInfoTick(symbol, tick)) continue;
      double price = isBuy ? tick.bid : tick.ask;
      if(price <= 0.0) continue;

      double profitPoints = isBuy ? (price - open) / point : (open - price) / point;
      double newSl        = sl;

      // Break-even: once the trade has paid, the stop never sits below entry.
      if(InpBreakEven && profitPoints >= InpBreakEvenPoints)
        {
         double breakEven = isBuy
                            ? open + InpBreakEvenLock * point
                            : open - InpBreakEvenLock * point;
         if(isBuy  && (sl == 0.0 || breakEven > sl)) newSl = breakEven;
         if(!isBuy && (sl == 0.0 || breakEven < sl)) newSl = breakEven;
        }

      // Trailing: follow the market, never widen the stop.
      if(InpTrailing && profitPoints >= InpBreakEvenPoints)
        {
         double trail = isBuy ? price - InpTrailPoints * point : price + InpTrailPoints * point;
         if(isBuy  && (newSl == 0.0 || trail > newSl + InpTrailStepPoints * point)) newSl = trail;
         if(!isBuy && (newSl == 0.0 || trail < newSl - InpTrailStepPoints * point)) newSl = trail;
        }

      if(MathAbs(newSl - sl) > point / 2.0)
        {
         g_trade.PositionModify(ticket, NormalizeDouble(newSl, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS)),
                                NormalizeDouble(tp, (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS)));
         AIWF_RecordOrderResult(g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
        }
      // volume is read for clarity of intent: management never adds risk.
      (void)volume;
     }
  }

//+------------------------------------------------------------------+
//| Optional test entry. It goes through the same gate as any order.  |
//+------------------------------------------------------------------+
void TryOpenDemoEntry()
  {
   if(!AIWF_AllowNewTrades())
     {
      Print("AI WORKFORCE: entry refused — ", AIWF_BlockReason());
      return;
     }

   string symbol = AIWF_Symbol();
   MqlTick tick;
   if(!SymbolInfoTick(symbol, tick) || tick.ask <= 0.0) return;

   double point  = AIWF_PointSize(symbol);
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double price  = tick.ask;
   double sl     = NormalizeDouble(price - InpDemoStopPoints * point, digits);
   double tp     = NormalizeDouble(price + InpDemoStopPoints * 2.0 * point, digits);

   // Spread is re-checked at the moment of entry: the tick that authorised the
   // trade is not the tick that fills it.
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints)
     {
      Print("AI WORKFORCE: entry refused — spread ", DoubleToString(AIWF_SpreadPoints(), 1), " points");
      return;
     }

   bool ok = g_trade.Buy(InpDemoLots, symbol, price, sl, tp, "ai-workforce trade manager");
   AIWF_RecordOrderResult(ok && g_trade.ResultRetcode() == TRADE_RETCODE_DONE, 0.0);
  }
//+------------------------------------------------------------------+

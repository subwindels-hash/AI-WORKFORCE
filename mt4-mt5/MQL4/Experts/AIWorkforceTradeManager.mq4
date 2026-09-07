//+------------------------------------------------------------------+
//|                                    AIWorkforceTradeManager.mq4    |
//|  Trade Manager EA (MT4) — break-even and trailing management, and  |
//|  a hard refusal to open anything while protection is active (§11). |
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
input int    InpDemoStopPoints   = 400;   // Demo entry stop distance (points)

//+------------------------------------------------------------------+
int OnInit()
  {
   return(AIWF_Init(InpMagic));
  }

//+------------------------------------------------------------------+
void OnTick()
  {
   AIWF_OnTick();                 // protection first — everything below depends on it
   ManageOpenOrders();

   if(InpAllowDemoEntries && AIWF_CountPositions(AIWF_Symbol(), InpMagic) == 0)
      TryOpenDemoEntry();
  }

//+------------------------------------------------------------------+
//| Break-even and trailing for the orders this EA owns.              |
//+------------------------------------------------------------------+
void ManageOpenOrders()
  {
   for(int i = OrdersTotal() - 1; i >= 0; i--)
     {
      if(!OrderSelect(i, SELECT_BY_POS, MODE_TRADES)) continue;
      if(InpMagic > 0 && OrderMagicNumber() != InpMagic) continue;
      if(AIWF_IsPendingType(OrderType())) continue;              // pending orders are not managed
      if(!InpManageAllSymbols && OrderSymbol() != Symbol()) continue;

      string symbol = OrderSymbol();
      double point  = AIWF_PointSize(symbol);
      int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
      double open   = OrderOpenPrice();
      double sl     = OrderStopLoss();
      double tp     = OrderTakeProfit();
      bool   isBuy  = (OrderType() == OP_BUY);

      RefreshRates();
      double price = isBuy ? MarketInfo(symbol, MODE_BID) : MarketInfo(symbol, MODE_ASK);
      if(price <= 0.0) continue;

      double profitPoints = isBuy ? (price - open) / point : (open - price) / point;
      double newSl        = sl;

      if(InpBreakEven && profitPoints >= InpBreakEvenPoints)
        {
         double breakEven = isBuy ? open + InpBreakEvenLock * point : open - InpBreakEvenLock * point;
         if(isBuy  && (sl == 0.0 || breakEven > sl)) newSl = breakEven;
         if(!isBuy && (sl == 0.0 || breakEven < sl)) newSl = breakEven;
        }

      if(InpTrailing && profitPoints >= InpBreakEvenPoints)
        {
         double trail = isBuy ? price - InpTrailPoints * point : price + InpTrailPoints * point;
         if(isBuy  && (newSl == 0.0 || trail > newSl + InpTrailStepPoints * point)) newSl = trail;
         if(!isBuy && (newSl == 0.0 || trail < newSl - InpTrailStepPoints * point)) newSl = trail;
        }

      if(MathAbs(newSl - sl) > point / 2.0)
        {
         bool ok = OrderModify(OrderTicket(), OrderOpenPrice(), NormalizeDouble(newSl, digits),
                               NormalizeDouble(tp, digits), 0, clrNONE);
         AIWF_RecordOrderResult(ok, 0.0);
        }
     }
  }

//+------------------------------------------------------------------+
//| Optional test entry — through the same gate as any other order.   |
//+------------------------------------------------------------------+
void TryOpenDemoEntry()
  {
   if(!AIWF_AllowNewTrades())
     {
      Print("AI WORKFORCE: entry refused — ", AIWF_BlockReason());
      return;
     }
   if(AIWF_SpreadPoints() > InpMaxSpreadPoints)
     {
      Print("AI WORKFORCE: entry refused — spread ", DoubleToString(AIWF_SpreadPoints(), 1), " points");
      return;
     }

   string symbol = AIWF_Symbol();
   int    digits = (int)SymbolInfoInteger(symbol, SYMBOL_DIGITS);
   double point  = AIWF_PointSize(symbol);
   RefreshRates();
   double price = MarketInfo(symbol, MODE_ASK);
   if(price <= 0.0) return;

   int ticket = OrderSend(symbol, OP_BUY, InpDemoLots, NormalizeDouble(price, digits),
                          (int)InpMaxSlippagePoints,
                          NormalizeDouble(price - InpDemoStopPoints * point, digits),
                          NormalizeDouble(price + InpDemoStopPoints * 2.0 * point, digits),
                          "ai-workforce trade manager", InpMagic, 0, clrNONE);
   AIWF_RecordOrderResult(ticket > 0, 0.0);
  }
//+------------------------------------------------------------------+

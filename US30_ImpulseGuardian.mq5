#property copyright "PowerUX"
#property version   "1.00"
#property strict

#include <Trade/Trade.mqh>

CTrade trade;

input long     InpMagicNumber                = 3002001;
input bool     InpEnableLogs                 = true;

input ENUM_TIMEFRAMES InpSignalTimeframe     = PERIOD_M5;
input int      InpImpulseLookbackBars        = 8;
input int      InpATRPeriod                  = 14;
input double   InpImpulseBodyATRMult         = 0.70;
input double   InpInitialSL_ATRMult          = 1.20;
input double   InpTakeProfitR                = 2.20;
input double   InpMinATRPoints               = 40.0;
input int      InpMaxTradesPerDay            = 18;
input int      InpMinMinutesBetweenTrades    = 8;

input bool     InpUseRiskPercent             = true;
input double   InpFixedLot                   = 0.10;
input double   InpRiskPercentPerTrade        = 0.60;
input double   InpMaxDailyLossPercent        = 3.0;
input double   InpMaxDailyLossUSD            = 120.0;

input double   InpBE_TriggerR                = 0.45;
input double   InpBE_LockR                   = 0.08;
input double   InpTrailStartR                = 0.85;
input double   InpTrailATRMult               = 1.10;

input int      InpNYOpenHour                 = 9;
input int      InpNYOpenMinute               = 30;
input int      InpNYCloseHour                = 16;
input int      InpNYCloseMinute              = 0;
input int      InpBrokerMinusNY_Hours        = 7;

int atrHandle = INVALID_HANDLE;
datetime lastSignalBarTime = 0;
datetime lastTradeTime = 0;

void Log(string msg)
{
   if(InpEnableLogs)
      Print("[ImpulseGuardian] ", msg);
}

int OnInit()
{
   trade.SetExpertMagicNumber(InpMagicNumber);

   atrHandle = iATR(_Symbol, InpSignalTimeframe, InpATRPeriod);
   if(atrHandle == INVALID_HANDLE)
   {
      Print("Nie mogę utworzyć ATR handle.");
      return(INIT_FAILED);
   }

   Log("EA zainicjalizowany poprawnie.");
   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   if(atrHandle != INVALID_HANDLE)
      IndicatorRelease(atrHandle);
}

void OnTick()
{
   ManageOpenPosition();

   if(PositionSelect(_Symbol))
      return;

   if(!IsNYSession())
      return;

   if(IsDailyLossLimitHit())
      return;

   if(GetTradesCountToday() >= InpMaxTradesPerDay)
      return;

   if((TimeCurrent() - lastTradeTime) < InpMinMinutesBetweenTrades * 60)
      return;

   datetime signalBar = GetCurrentSignalBarTime();
   if(signalBar == 0 || signalBar == lastSignalBarTime)
      return;

   lastSignalBarTime = signalBar;

   TryOpenTrade();
}

datetime GetCurrentSignalBarTime()
{
   MqlRates rates[];
   if(CopyRates(_Symbol, InpSignalTimeframe, 0, 2, rates) < 2)
      return 0;
   return rates[0].time;
}

bool IsNYSession()
{
   datetime nowBroker = TimeCurrent();
   datetime nowNY = nowBroker - InpBrokerMinusNY_Hours * 3600;

   MqlDateTime ny;
   TimeToStruct(nowNY, ny);
   int currentMins = ny.hour * 60 + ny.min;
   int openMins = InpNYOpenHour * 60 + InpNYOpenMinute;
   int closeMins = InpNYCloseHour * 60 + InpNYCloseMinute;

   if(openMins <= closeMins)
      return (currentMins >= openMins && currentMins < closeMins);

   return (currentMins >= openMins || currentMins < closeMins);
}

bool IsDailyLossLimitHit()
{
   double pnlToday = GetTodayClosedPnL();
   double bal = AccountInfoDouble(ACCOUNT_BALANCE);

   double maxLossPctUsd = bal * InpMaxDailyLossPercent / 100.0;
   double maxAllowedLoss = MathMin(maxLossPctUsd, InpMaxDailyLossUSD);

   if(-pnlToday >= maxAllowedLoss)
   {
      Log(StringFormat("Limit straty dziennej osiągnięty. PnL=%.2f, limit=%.2f", pnlToday, -maxAllowedLoss));
      return true;
   }

   return false;
}

double GetTodayClosedPnL()
{
   datetime dayStart = GetBrokerDayStart();
   datetime now = TimeCurrent();

   if(!HistorySelect(dayStart, now))
      return 0.0;

   double sum = 0.0;
   int totalDeals = (int)HistoryDealsTotal();
   for(int i = 0; i < totalDeals; i++)
   {
      ulong dealTicket = HistoryDealGetTicket(i);
      if(dealTicket == 0)
         continue;

      if((long)HistoryDealGetInteger(dealTicket, DEAL_MAGIC) != InpMagicNumber)
         continue;
      if(HistoryDealGetString(dealTicket, DEAL_SYMBOL) != _Symbol)
         continue;
      if((ENUM_DEAL_ENTRY)HistoryDealGetInteger(dealTicket, DEAL_ENTRY) != DEAL_ENTRY_OUT)
         continue;

      double profit = HistoryDealGetDouble(dealTicket, DEAL_PROFIT);
      double swap = HistoryDealGetDouble(dealTicket, DEAL_SWAP);
      double commission = HistoryDealGetDouble(dealTicket, DEAL_COMMISSION);
      sum += profit + swap + commission;
   }

   return sum;
}

int GetTradesCountToday()
{
   datetime dayStart = GetBrokerDayStart();
   datetime now = TimeCurrent();

   if(!HistorySelect(dayStart, now))
      return 0;

   int count = 0;
   int totalDeals = (int)HistoryDealsTotal();
   for(int i = 0; i < totalDeals; i++)
   {
      ulong dealTicket = HistoryDealGetTicket(i);
      if(dealTicket == 0)
         continue;

      if((long)HistoryDealGetInteger(dealTicket, DEAL_MAGIC) != InpMagicNumber)
         continue;
      if(HistoryDealGetString(dealTicket, DEAL_SYMBOL) != _Symbol)
         continue;
      if((ENUM_DEAL_ENTRY)HistoryDealGetInteger(dealTicket, DEAL_ENTRY) != DEAL_ENTRY_IN)
         continue;

      count++;
   }

   return count;
}

datetime GetBrokerDayStart()
{
   MqlDateTime t;
   TimeToStruct(TimeCurrent(), t);
   t.hour = 0;
   t.min = 0;
   t.sec = 0;
   return StructToTime(t);
}

void TryOpenTrade()
{
   MqlRates rates[];
   int needBars = MathMax(InpImpulseLookbackBars + 2, 20);
   if(CopyRates(_Symbol, InpSignalTimeframe, 1, needBars, rates) < needBars)
   {
      Log("Brak wystarczających danych świec.");
      return;
   }

   double atrBuf[];
   if(CopyBuffer(atrHandle, 0, 1, 3, atrBuf) < 3)
   {
      Log("Brak danych ATR.");
      return;
   }

   double atr = atrBuf[0];
   if((atr / _Point) < InpMinATRPoints)
   {
      Log("ATR za niski, pomijam sygnał.");
      return;
   }

   MqlRates c1 = rates[0];
   double body = MathAbs(c1.close - c1.open);

   double highest = rates[1].high;
   double lowest = rates[1].low;
   for(int i = 1; i <= InpImpulseLookbackBars; i++)
   {
      highest = MathMax(highest, rates[i].high);
      lowest = MathMin(lowest, rates[i].low);
   }

   bool bullishImpulse = (c1.close > c1.open) && (c1.close > highest) && (body >= atr * InpImpulseBodyATRMult);
   bool bearishImpulse = (c1.close < c1.open) && (c1.close < lowest) && (body >= atr * InpImpulseBodyATRMult);

   if(!bullishImpulse && !bearishImpulse)
   {
      Log("Brak impulsu wejściowego.");
      return;
   }

   bool isBuy = bullishImpulse;
   double price = isBuy ? SymbolInfoDouble(_Symbol, SYMBOL_ASK) : SymbolInfoDouble(_Symbol, SYMBOL_BID);
   double slDistance = atr * InpInitialSL_ATRMult;
   double sl = isBuy ? price - slDistance : price + slDistance;

   double tpDistance = slDistance * InpTakeProfitR;
   double tp = isBuy ? price + tpDistance : price - tpDistance;

   double lot = CalculateLotSize(price, sl);
   if(lot <= 0.0)
   {
      Log("Wyliczony lot <= 0. Pomijam transakcję.");
      return;
   }

   string cmt = BuildComment(slDistance / _Point);
   trade.SetDeviationInPoints(20);
   bool ok = isBuy ? trade.Buy(lot, _Symbol, price, sl, tp, cmt)
                   : trade.Sell(lot, _Symbol, price, sl, tp, cmt);

   if(ok)
   {
      lastTradeTime = TimeCurrent();
      Log(StringFormat("OPEN %s lot=%.2f price=%.2f sl=%.2f tp=%.2f", isBuy ? "BUY" : "SELL", lot, price, sl, tp));
   }
   else
   {
      Log(StringFormat("Błąd otwarcia pozycji: %d", GetLastError()));
   }
}

double CalculateLotSize(double entry, double sl)
{
   if(!InpUseRiskPercent)
      return NormalizeVolume(InpFixedLot);

   double riskMoney = AccountInfoDouble(ACCOUNT_BALANCE) * InpRiskPercentPerTrade / 100.0;
   double stopDistance = MathAbs(entry - sl);

   if(stopDistance <= 0.0)
      return 0.0;

   double tickValue = SymbolInfoDouble(_Symbol, SYMBOL_TRADE_TICK_VALUE);
   double tickSize = SymbolInfoDouble(_Symbol, SYMBOL_TRADE_TICK_SIZE);
   if(tickValue <= 0.0 || tickSize <= 0.0)
      return 0.0;

   double lossPerLot = (stopDistance / tickSize) * tickValue;
   if(lossPerLot <= 0.0)
      return 0.0;

   double rawLot = riskMoney / lossPerLot;
   return NormalizeVolume(rawLot);
}

double NormalizeVolume(double vol)
{
   double minLot = SymbolInfoDouble(_Symbol, SYMBOL_VOLUME_MIN);
   double maxLot = SymbolInfoDouble(_Symbol, SYMBOL_VOLUME_MAX);
   double step = SymbolInfoDouble(_Symbol, SYMBOL_VOLUME_STEP);

   if(step <= 0)
      return 0.0;

   vol = MathMax(minLot, MathMin(maxLot, vol));
   vol = MathFloor(vol / step) * step;

   int digits = 2;
   if(step < 0.1) digits = 3;
   if(step < 0.01) digits = 4;

   return NormalizeDouble(vol, digits);
}

string BuildComment(double riskPoints)
{
   int rp = (int)MathRound(riskPoints);
   return StringFormat("IG:%d", rp);
}

int ParseRiskPoints(string cmt)
{
   if(StringLen(cmt) < 4)
      return 0;

   if(StringSubstr(cmt, 0, 3) != "IG:")
      return 0;

   string v = StringSubstr(cmt, 3);
   return (int)StringToInteger(v);
}

void ManageOpenPosition()
{
   if(!PositionSelect(_Symbol))
      return;

   long type = PositionGetInteger(POSITION_TYPE);
   double open = PositionGetDouble(POSITION_PRICE_OPEN);
   double sl = PositionGetDouble(POSITION_SL);
   double tp = PositionGetDouble(POSITION_TP);
   string cmt = PositionGetString(POSITION_COMMENT);

   int riskPoints = ParseRiskPoints(cmt);
   if(riskPoints <= 0)
      return;

   double riskPrice = riskPoints * _Point;
   if(riskPrice <= 0)
      return;

   double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
   double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);
   double current = (type == POSITION_TYPE_BUY) ? bid : ask;

   double profitMove = (type == POSITION_TYPE_BUY) ? (current - open) : (open - current);
   double r = profitMove / riskPrice;

   double newSL = sl;

   if(r >= InpBE_TriggerR)
   {
      double beLock = InpBE_LockR * riskPrice;
      double beSL = (type == POSITION_TYPE_BUY) ? (open + beLock) : (open - beLock);

      if(type == POSITION_TYPE_BUY)
         newSL = MathMax(newSL, beSL);
      else if(newSL == 0.0)
         newSL = beSL;
      else
         newSL = MathMin(newSL, beSL);
   }

   if(r >= InpTrailStartR)
   {
      double atrBuf[];
      if(CopyBuffer(atrHandle, 0, 0, 1, atrBuf) == 1)
      {
         double atr = atrBuf[0];
         double trailDist = atr * InpTrailATRMult;
         double trailSL = (type == POSITION_TYPE_BUY) ? (current - trailDist) : (current + trailDist);

         if(type == POSITION_TYPE_BUY)
            newSL = MathMax(newSL, trailSL);
         else
            newSL = (newSL == 0.0) ? trailSL : MathMin(newSL, trailSL);
      }
   }

   newSL = NormalizeDouble(newSL, (int)SymbolInfoInteger(_Symbol, SYMBOL_DIGITS));

   bool shouldModify = false;
   if(type == POSITION_TYPE_BUY)
      shouldModify = (newSL > sl + _Point * 0.5);
   else
      shouldModify = (sl == 0.0 && newSL > 0.0) || (newSL < sl - _Point * 0.5);

   if(shouldModify)
   {
      if(trade.PositionModify(_Symbol, newSL, tp))
         Log(StringFormat("SL update -> %.2f (R=%.2f)", newSL, r));
      else
         Log(StringFormat("Błąd modyfikacji SL: %d", GetLastError()));
   }
}

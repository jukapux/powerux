#property copyright "PowerUX"
#property version   "1.10"
#property strict

#include <Trade/Trade.mqh>

CTrade trade;

input long     InpMagicNumber                = 3002001;
input bool     InpEnableLogs                 = true;

input ENUM_TIMEFRAMES InpSignalTimeframe     = PERIOD_M5;
input int      InpImpulseLookbackBars        = 6;
input int      InpATRPeriod                  = 14;
input double   InpImpulseBodyATRMult         = 0.55;
input double   InpImpulseRangeATRMult        = 0.95;
input double   InpImpulseCloseInRangeMin     = 0.60;
input double   InpInitialSL_ATRMult          = 1.10;
input double   InpTakeProfitR                = 1.90;
input double   InpMinATRPoints               = 30.0;
input double   InpMaxSpreadPoints            = 35.0;
input int      InpMaxTradesPerDay            = 14;
input int      InpMinMinutesBetweenTrades    = 5;

input bool     InpUseRiskPercent             = true;
input double   InpFixedLot                   = 0.10;
input double   InpRiskPercentPerTrade        = 0.50;
input double   InpMaxDailyLossPercent        = 2.8;
input double   InpMaxDailyLossUSD            = 90.0;

input bool     InpUseTrendFilter             = true;
input ENUM_TIMEFRAMES InpTrendTimeframe      = PERIOD_M15;
input int      InpEMAPeriod                  = 50;
input double   InpMinEmaDistanceATR          = 0.05;

input bool     InpUsePartialClose            = true;
input double   InpPartialCloseAtR            = 0.90;
input double   InpPartialClosePercent        = 50.0;
input double   InpBE_TriggerR                = 0.30;
input double   InpBE_LockR                   = 0.06;
input double   InpTrailStartR                = 0.70;
input double   InpTrailATRMult               = 0.95;
input int      InpMaxBarsInTrade             = 18;

input int      InpNYOpenHour                 = 9;
input int      InpNYOpenMinute               = 30;
input int      InpNYCloseHour                = 16;
input int      InpNYCloseMinute              = 0;
input int      InpBrokerMinusNY_Hours        = 7;

int atrHandle = INVALID_HANDLE;
int emaHandle = INVALID_HANDLE;
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

   if(InpUseTrendFilter)
   {
      emaHandle = iMA(_Symbol, InpTrendTimeframe, InpEMAPeriod, 0, MODE_EMA, PRICE_CLOSE);
      if(emaHandle == INVALID_HANDLE)
      {
         Print("Nie mogę utworzyć EMA handle.");
         return(INIT_FAILED);
      }
   }

   Log("EA v1.10 zainicjalizowany poprawnie.");
   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   if(atrHandle != INVALID_HANDLE)
      IndicatorRelease(atrHandle);
   if(emaHandle != INVALID_HANDLE)
      IndicatorRelease(emaHandle);
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

   if(GetSpreadPoints() > InpMaxSpreadPoints)
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

double GetSpreadPoints()
{
   double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);
   double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
   if(ask <= 0 || bid <= 0)
      return 9999.0;
   return (ask - bid) / _Point;
}

bool GetAtr(double &atrValue, int shift=1)
{
   double atrBuf[];
   if(CopyBuffer(atrHandle, 0, shift, 1, atrBuf) != 1)
      return false;
   atrValue = atrBuf[0];
   return atrValue > 0.0;
}

bool PassTrendFilter(bool isBuy, double atr)
{
   if(!InpUseTrendFilter)
      return true;

   MqlRates tr[];
   if(CopyRates(_Symbol, InpTrendTimeframe, 1, 1, tr) < 1)
      return false;

   double emaBuf[];
   if(CopyBuffer(emaHandle, 0, 1, 1, emaBuf) != 1)
      return false;

   double closePrice = tr[0].close;
   double ema = emaBuf[0];
   double minDist = atr * InpMinEmaDistanceATR;

   if(isBuy)
      return (closePrice > ema + minDist);

   return (closePrice < ema - minDist);
}

int DetectImpulseSignal(double atr)
{
   MqlRates rates[];
   int needBars = MathMax(InpImpulseLookbackBars + 2, 20);
   if(CopyRates(_Symbol, InpSignalTimeframe, 1, needBars, rates) < needBars)
      return 0;

   MqlRates c1 = rates[0];
   double body = MathAbs(c1.close - c1.open);
   double range = c1.high - c1.low;

   if(range <= 0.0)
      return 0;

   if(body < atr * InpImpulseBodyATRMult)
      return 0;

   if(range < atr * InpImpulseRangeATRMult)
      return 0;

   double closePos = (c1.close - c1.low) / range;

   double highest = rates[1].high;
   double lowest = rates[1].low;
   for(int i = 1; i <= InpImpulseLookbackBars; i++)
   {
      highest = MathMax(highest, rates[i].high);
      lowest = MathMin(lowest, rates[i].low);
   }

   bool bullishImpulse = (c1.close > c1.open) && (c1.close > highest) && (closePos >= InpImpulseCloseInRangeMin);
   bool bearishImpulse = (c1.close < c1.open) && (c1.close < lowest) && ((1.0 - closePos) >= InpImpulseCloseInRangeMin);

   if(bullishImpulse)
      return 1;
   if(bearishImpulse)
      return -1;

   return 0;
}

void TryOpenTrade()
{
   double atr = 0.0;
   if(!GetAtr(atr, 1))
   {
      Log("Brak danych ATR.");
      return;
   }

   if((atr / _Point) < InpMinATRPoints)
      return;

   int signal = DetectImpulseSignal(atr);
   if(signal == 0)
      return;

   bool isBuy = (signal > 0);
   if(!PassTrendFilter(isBuy, atr))
      return;

   double price = isBuy ? SymbolInfoDouble(_Symbol, SYMBOL_ASK) : SymbolInfoDouble(_Symbol, SYMBOL_BID);
   double slDistance = atr * InpInitialSL_ATRMult;
   double sl = isBuy ? (price - slDistance) : (price + slDistance);

   double tpDistance = slDistance * InpTakeProfitR;
   double tp = isBuy ? (price + tpDistance) : (price - tpDistance);

   double lot = CalculateLotSize(price, sl);
   if(lot <= 0.0)
   {
      Log("Wyliczony lot <= 0. Pomijam transakcję.");
      return;
   }

   string cmt = BuildComment(slDistance / _Point, lot);
   trade.SetDeviationInPoints(20);
   bool ok = isBuy ? trade.Buy(lot, _Symbol, price, sl, tp, cmt)
                   : trade.Sell(lot, _Symbol, price, sl, tp, cmt);

   if(ok)
   {
      lastTradeTime = TimeCurrent();
      Log(StringFormat("OPEN %s lot=%.2f spread=%.1f sl=%.2f tp=%.2f", isBuy ? "BUY" : "SELL", lot, GetSpreadPoints(), sl, tp));
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

string BuildComment(double riskPoints, double initLot)
{
   int rp = (int)MathRound(riskPoints);
   int v = (int)MathRound(initLot * 100.0);
   return StringFormat("IG:%d:%d", rp, v);
}

void ParseComment(string cmt, int &riskPoints, double &initLot)
{
   riskPoints = 0;
   initLot = 0.0;

   string parts[];
   int n = StringSplit(cmt, ':', parts);
   if(n < 3)
      return;

   if(parts[0] != "IG")
      return;

   riskPoints = (int)StringToInteger(parts[1]);
   initLot = (double)StringToInteger(parts[2]) / 100.0;
}

void ManageOpenPosition()
{
   if(!PositionSelect(_Symbol))
      return;

   long type = PositionGetInteger(POSITION_TYPE);
   double open = PositionGetDouble(POSITION_PRICE_OPEN);
   double sl = PositionGetDouble(POSITION_SL);
   double tp = PositionGetDouble(POSITION_TP);
   double vol = PositionGetDouble(POSITION_VOLUME);
   string cmt = PositionGetString(POSITION_COMMENT);
   datetime openTime = (datetime)PositionGetInteger(POSITION_TIME);

   int riskPoints = 0;
   double initLot = 0.0;
   ParseComment(cmt, riskPoints, initLot);

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

   if(InpUsePartialClose && initLot > 0.0 && r >= InpPartialCloseAtR)
      TryPartialClose(vol, initLot);

   if(InpMaxBarsInTrade > 0 && IsTradeStale(openTime, type))
   {
      if(trade.PositionClose(_Symbol))
         Log("Zamknięcie czasowe pozycji (brak follow-through).");
      return;
   }

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
      double atr = 0.0;
      if(GetAtr(atr, 0))
      {
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

void TryPartialClose(double currentVolume, double initLot)
{
   double step = SymbolInfoDouble(_Symbol, SYMBOL_VOLUME_STEP);
   if(step <= 0.0)
      return;

   // Jeśli już częściowo zamknięta (wolumen istotnie mniejszy od startowego), nie rób ponownie.
   if(currentVolume <= initLot * 0.75)
      return;

   double closeVol = initLot * (InpPartialClosePercent / 100.0);
   closeVol = NormalizeVolume(closeVol);

   if(closeVol <= 0.0 || closeVol >= currentVolume)
      return;

   if(trade.PositionClosePartial(_Symbol, closeVol))
      Log(StringFormat("Partial close: %.2f lot at volume %.2f", closeVol, currentVolume));
   else
      Log(StringFormat("Błąd partial close: %d", GetLastError()));
}

bool IsTradeStale(datetime openTime, long type)
{
   int barsPassed = iBarShift(_Symbol, InpSignalTimeframe, openTime, false);
   if(barsPassed < InpMaxBarsInTrade)
      return false;

   double open = PositionGetDouble(POSITION_PRICE_OPEN);
   double bid = SymbolInfoDouble(_Symbol, SYMBOL_BID);
   double ask = SymbolInfoDouble(_Symbol, SYMBOL_ASK);
   double current = (type == POSITION_TYPE_BUY) ? bid : ask;

   double move = (type == POSITION_TYPE_BUY) ? (current - open) : (open - current);
   return (move <= 0.15 * _Point);
}

# US30 Impulse Guardian (MQL5)

Ulepszony Expert Advisor pod US30 z naciskiem na:
- bezpieczeństwo dla konta ~2000 USD,
- aktywny intraday trading (kilka/kilkanaście wejść dziennie po optymalizacji),
- szybkie zabezpieczanie zysku,
- pełną konfigurowalność w Strategy Tester.

## Plik
- `US30_ImpulseGuardian.mq5`

## Co zostało ulepszone po pierwszych wynikach testera
1. **Lepsza definicja impulsu**
   - wybicie high/low z lookback,
   - minimalny korpus względem ATR,
   - minimalny range świecy względem ATR,
   - zamknięcie świecy blisko ekstremum (jakość momentum).
2. **Filtr trendu (opcjonalny)**
   - EMA na wyższym TF (domyślnie M15),
   - wymagany minimalny dystans ceny od EMA (w ATR).
3. **Filtr spreadu**
   - brak wejścia przy zbyt wysokim spreadzie.
4. **Lepsze zarządzanie pozycją**
   - szybki BE+,
   - trailing ATR,
   - częściowa realizacja zysku (partial close),
   - wyjście czasowe dla „martwych” pozycji.

## Główne parametry
- **Sygnał impulsowy**
  - `InpImpulseLookbackBars`
  - `InpImpulseBodyATRMult`
  - `InpImpulseRangeATRMult`
  - `InpImpulseCloseInRangeMin`
- **Ryzyko i limity**
  - `InpUseRiskPercent` / `InpFixedLot`
  - `InpRiskPercentPerTrade`
  - `InpMaxDailyLossPercent`
  - `InpMaxDailyLossUSD`
- **Aktywność i koszty**
  - `InpMaxTradesPerDay`
  - `InpMinMinutesBetweenTrades`
  - `InpMaxSpreadPoints`
- **Trend filter**
  - `InpUseTrendFilter`
  - `InpTrendTimeframe`
  - `InpEMAPeriod`
  - `InpMinEmaDistanceATR`
- **Wyjścia / ochrona zysku**
  - `InpUsePartialClose`
  - `InpPartialCloseAtR`
  - `InpPartialClosePercent`
  - `InpBE_TriggerR`
  - `InpBE_LockR`
  - `InpTrailStartR`
  - `InpTrailATRMult`
  - `InpMaxBarsInTrade`
- **Sesja NY**
  - `InpNYOpenHour/Minute`, `InpNYCloseHour/Minute`
  - `InpBrokerMinusNY_Hours`
- **Logi**
  - `InpEnableLogs`

## Proponowany start dla konta 2000 USD
- `InpUseRiskPercent = true`
- `InpRiskPercentPerTrade = 0.35 - 0.60`
- `InpMaxDailyLossPercent = 2.0 - 3.0`
- `InpMaxDailyLossUSD = 70 - 100`
- `InpMaxSpreadPoints = 20 - 40` (zależnie od brokera)
- `InpPartialCloseAtR = 0.8 - 1.2`
- `InpBE_TriggerR = 0.25 - 0.40`

## Jak stroić po Twoim raporcie (PF 1.14, 75 transakcji)
1. Żeby zwiększyć liczbę transakcji:
   - obniż `InpImpulseBodyATRMult` i/lub `InpImpulseRangeATRMult`,
   - skróć `InpImpulseLookbackBars`,
   - zmniejsz `InpMinMinutesBetweenTrades`.
2. Żeby podnieść PF:
   - podnieś `InpImpulseCloseInRangeMin`,
   - włącz filtr trendu (`InpUseTrendFilter = true`),
   - zacieśnij `InpMaxSpreadPoints`.
3. Żeby zmniejszyć DD:
   - obniż `InpRiskPercentPerTrade`,
   - zwiększ agresywność BE (`InpBE_TriggerR` niżej),
   - skróć `InpMaxBarsInTrade`.

## Strategy Tester
EA jest przygotowany do:
- testów historycznych,
- optymalizacji parametrów,
- walk-forward (manualnie na różnych zakresach dat).

## Uwaga
Przed live tradingiem obowiązkowo:
- przetestuj na demie,
- sprawdź poprawny offset `InpBrokerMinusNY_Hours`,
- zweryfikuj specyfikację US30 u brokera (tick value/tick size, minimalny lot, spread).

# US30 Impulse Guardian (MQL5)

Expert Advisor dla MetaTrader 5 zaprojektowany pod US30, z naciskiem na:
- kontrolę ryzyka dla małego konta (np. 2000 USD),
- handel tylko podczas sesji NY,
- 1 pozycję naraz,
- szybkie zabezpieczanie zysku,
- możliwość strojenia w Strategy Tester.

## Plik
- `US30_ImpulseGuardian.mq5`

## Jak działa strategia (skrót)
1. Szuka impulsu na zamkniętej świecy (`InpSignalTimeframe`):
   - świeca wybija maksimum/minimum z `InpImpulseLookbackBars`,
   - korpus świecy ma minimum `InpImpulseBodyATRMult * ATR`.
2. Stop Loss ustawiany jako `InpInitialSL_ATRMult * ATR`.
3. Take Profit ustawiany jako wielokrotność ryzyka (`InpTakeProfitR`).
4. Zarządzanie pozycją:
   - szybkie przesunięcie SL na BE+ (`InpBE_TriggerR`, `InpBE_LockR`),
   - potem trailing oparty o ATR (`InpTrailStartR`, `InpTrailATRMult`).

## Najważniejsze parametry
- **Money management**
  - `InpUseRiskPercent` (true = % kapitału, false = stały lot)
  - `InpRiskPercentPerTrade`
  - `InpFixedLot`
- **Bezpieczeństwo dzienne**
  - `InpMaxDailyLossPercent`
  - `InpMaxDailyLossUSD`
- **Aktywność intraday**
  - `InpMaxTradesPerDay`
  - `InpMinMinutesBetweenTrades`
- **Sesja NY**
  - `InpNYOpenHour/Minute`
  - `InpNYCloseHour/Minute`
  - `InpBrokerMinusNY_Hours` (różnica czasu brokera względem NY)
- **Logi**
  - `InpEnableLogs`

## Wskazówki startowe dla konta 2000 USD
- `InpUseRiskPercent = true`
- `InpRiskPercentPerTrade = 0.4 - 0.8`
- `InpMaxDailyLossPercent = 2.5 - 3.5`
- `InpMaxDailyLossUSD = 80 - 130`
- `InpBE_TriggerR = 0.35 - 0.55`
- `InpBE_LockR = 0.05 - 0.15`

## Strategy Tester
EA ma wszystkie kluczowe parametry jako `input`, więc nadaje się do:
- testów historycznych,
- optymalizacji,
- walk-forward (manualnie przez zakresy dat).

## Uwaga
Przed live tradingiem przetestuj na demie i sprawdź:
- realny spread/prowizję brokera,
- poprawną wartość `InpBrokerMinusNY_Hours`,
- specyfikację instrumentu US30 (tick size/tick value).

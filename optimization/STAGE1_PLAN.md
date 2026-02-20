# Etap 1 – optymalizacja zgrubna (coarse) dla US30_ImpulseGuardian v1.10

Ten etap ma znaleźć **stabilne obszary parametrów** (nie pojedynczy najlepszy wynik).

## 1) Ustawienia testera (stałe)
- Model: **Every tick based on real ticks**
- Okres: **M15**, zakres np. `2023-05-01 -> 2026-01-20`
- Symbol: **US30**
- Deposit: **2000 USD**
- Leverage: jak na koncie docelowym (np. 1:100)
- Forward optimization: **wyłączone** (włączymy dopiero w etapie 3)
- Inputs stałe:
  - `InpEnableLogs=false`
  - `InpUseRiskPercent=true`
  - `InpRiskPercentPerTrade=0.50`
  - `InpMaxDailyLossPercent=2.8`
  - `InpMaxDailyLossUSD=90`

## 2) Parametry do optymalizacji (coarse ranges)

### A. Wejście impulsowe
- `InpImpulseLookbackBars`: 4 .. 10 (step 1)
- `InpImpulseBodyATRMult`: 0.40 .. 0.80 (step 0.05)
- `InpImpulseRangeATRMult`: 0.70 .. 1.30 (step 0.10)
- `InpImpulseCloseInRangeMin`: 0.50 .. 0.85 (step 0.05)
- `InpMinATRPoints`: 20 .. 60 (step 5)

### B. Koszt wejścia i aktywność
- `InpMaxSpreadPoints`: 15 .. 45 (step 5)
- `InpMinMinutesBetweenTrades`: 2 .. 12 (step 1)
- `InpMaxTradesPerDay`: 8 .. 24 (step 2)

### C. Ochrona pozycji
- `InpPartialCloseAtR`: 0.60 .. 1.40 (step 0.10)
- `InpPartialClosePercent`: 30 .. 70 (step 10)
- `InpBE_TriggerR`: 0.20 .. 0.55 (step 0.05)
- `InpBE_LockR`: 0.02 .. 0.16 (step 0.02)
- `InpTrailStartR`: 0.45 .. 1.00 (step 0.05)
- `InpTrailATRMult`: 0.70 .. 1.30 (step 0.10)
- `InpMaxBarsInTrade`: 8 .. 28 (step 2)

### D. Filtr trendu
- `InpUseTrendFilter=true`
- `InpEMAPeriod`: 30 .. 100 (step 10)
- `InpMinEmaDistanceATR`: 0.00 .. 0.20 (step 0.02)

## 3) Jak ograniczyć liczbę kombinacji (praktycznie)
Żeby test nie trwał tygodniami, zrób to w 3 przebiegach:

1. **Pass A – tylko wejście impulsowe** (sekcja A) + reszta na domyślnych.
2. **Pass B – tylko zarządzanie pozycją** (sekcja C) + najlepsze 5 zestawów z Pass A.
3. **Pass C – aktywność/spread/trend** (sekcje B i D) + najlepsze 3 zestawy z Pass B.

## 4) Kryteria odrzutu (hard filters)
Odrzuć każdy wynik, który nie spełnia wszystkich:
- Profit Factor < 1.30
- Equity DD Relative > 4.0%
- Total Trades < 80
- Recovery Factor < 0.9

## 5) Ranking wyników (score)
Po hard filter licz score:

`Score = (PF * 40) + (RecoveryFactor * 25) + (ExpectedPayoff * 10) - (EquityDD% * 15)`

Wybierz top 10 po score i sprawdź ręcznie kształt equity curve.

## 6) Co wysłać do dalszego ulepszania
Po etapie 1 zapisz i prześlij:
- Top 10 wierszy z optymalizacji (CSV lub screenshot tabeli),
- jeden pełny report dla #1 i #5,
- logi z pojedynczego testu dla #1 (`InpEnableLogs=true`).

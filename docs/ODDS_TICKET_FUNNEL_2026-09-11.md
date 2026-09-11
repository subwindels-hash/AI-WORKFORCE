# Odds Prediction Ticket Engine — funnel for 2026-09-11

Requirement #14: the complete funnel after the fix, produced by the engine
itself. Reproduce it with

```
php index.php tools ticket_funnel 2026-09-11
```

(or, on the offline dev bridge, `node runtime/show-ticket-funnel.mjs 2026-09-11`).

## Before

The reported production run ended with no ticket and nothing to diagnose:

```
50 eligible -> 1 with-form -> 15 fresh-odds -> 0 sufficient-data -> 0 predictions
-> 0 confidence-qualified -> 0 positive-value -> 0 risk-qualified -> 0 final
INSUFFICIENT_DATA: 15 - SUPPORTED_ODDS_UNAVAILABLE: 35
```

The same shape reproduced on the sandbox provider: 6 real candidates, every
one of them positive-value and risk-approved, all rejected `LOW_CONFIDENCE`
against a fixed 75% floor while measuring 68.82-72.96.

## After

```
====================================================================================================
ODDS PREDICTION TICKET ENGINE — 2026-09-11
====================================================================================================
status            : PENDING_USER_APPROVAL
ticket            : d7ba3b2a-ed76-5b2c-ae4b-c6264d0bad1a
message           : odds prediction ticket generated; awaiting user approval

CONFIGURED THRESHOLDS
  min confidence 75%   min data quality 80   min EV 0.020   odds 5.00-8.00   max selections 5   max correlation LOW

ADAPTIVE CONFIDENCE POLICY (data quality band -> confidence required)
  EXCELLENT  data quality >= 85   -> 75% confidence required   markets: ALL
  GOOD       data quality >= 75   -> 70% confidence required   markets: ALL
  LIMITED    data quality >= 65   -> 65% confidence required   markets: SAFE
  REJECT     data quality <  65   -> no prediction is qualified at any confidence

FUNNEL
  fixtures evaluated         12
  eligible                   6
  with-form                  12
  fresh-odds                 6
  sufficient-data fixtures   6
  markets evaluated          96
  predictions generated      96
  predictions reused         0
  confidence-qualified       23
  positive-value             62
  risk-qualified             50
  eligible ticket pool       50
  preferred pool             4
  correlation-qualified      4
  FINAL (ticket legs)        2
  average confidence         69.66%
  selection tier             PREFERRED   fallback used: no

CONFIDENCE DISTRIBUTION (evaluated candidates)
  >=85               0    
  75-84              23   #######################
  70-74              25   #########################
  65-69              21   #####################
  60-64              16   ################
  <60                11   ###########
  unmeasured         0    

DATA QUALITY DISTRIBUTION (evaluated candidates)
  >=85 (EXCELLENT)   96   ########################################
  75-84 (GOOD)       0    
  65-74 (LIMITED)    0    
  <65 (REJECT)       0    
  unmeasured         0    

CANDIDATES BY ADAPTIVE TIER (the requirement each leg actually faced)
  EXCELLENT          96
  market-restricted  0

REJECTIONS (one primary reason per rejected fixture/candidate)
  LOW_MODEL_EDGE                     46
  FIXTURE_NOT_NS_OR_TOO_SOON         6

REJECTION AUDIT (reason - missing - available - data quality vs minimum)
  Glacier Town vs Horizon FC   BTTS           NO             LOW_MODEL_EDGE
      Missing:   (nothing — every required field was present)
      Available: externalId, homeTeam, awayTeam, competition, kickoff, recentForm, odds, oddsFreshness, providerReliability, marketLiquidity, restDays
      Data quality 96 (minimum allowed 65, tier EXCELLENT)   confidence 67.69 (required 75.00)
  Glacier Town vs Horizon FC   DOUBLE_CHANCE  AWAY_OR_DRAW   LOW_MODEL_EDGE
      Missing:   (nothing — every required field was present)
      Available: externalId, homeTeam, awayTeam, competition, kickoff, recentForm, odds, oddsFreshness, providerReliability, marketLiquidity, restDays
      Data quality 96 (minimum allowed 65, tier EXCELLENT)   confidence 61.77 (required 75.00)
  … (the ledger keeps one row per hard-rejected candidate)

CANDIDATE DECISIONS — fixture -> market -> model probability -> confidence -> data quality -> odds -> value -> risk -> correlation -> decision
  Delta Athletic vs Ember Rove MATCH_RESULT   DRAW           p=0.2415   conf=79.58   dq=96   odds=4.23    ev=0.0215    risk=MEDIUM  corr=LOW     => NOT_SELECTED             NOT_IN_BEST_COMBINATION
      adaptive requirement     tier=EXCELLENT  required conf=75.00  required dq=65  
  Comet Rangers vs Delta Athle MATCH_RESULT   DRAW           p=0.2669   conf=78.59   dq=96   odds=4.11    ev=0.0969    risk=LOW     corr=LOW     => SELECTED                 
      adaptive requirement     tier=EXCELLENT  required conf=75.00  required dq=65  
  Horizon FC vs Apex FC        TOTAL_GOALS    UNDER_3_5      p=0.7575   conf=75.29   dq=96   odds=1.4     ev=0.0605    risk=MEDIUM  corr=LOW     => SELECTED                 
      adaptive requirement     tier=EXCELLENT  required conf=75.00  required dq=65  
  Apex FC vs Bronze Valley     TOTAL_GOALS    UNDER_3_5      p=0.7352   conf=75.09   dq=96   odds=1.42    ev=0.0439    risk=MEDIUM  corr=LOW     => NOT_SELECTED             NOT_IN_BEST_COMBINATION
      adaptive requirement     tier=EXCELLENT  required conf=75.00  required dq=65  
  … (96 candidates in total; the full table is printed by the command above)

====================================================================================================
GENERATED TICKET d7ba3b2a-ed76-5b2c-ae4b-c6264d0bad1a
====================================================================================================
  combined odds 5.7540   legs 2   confidence(min) 75.29   avg confidence 76.94   data quality(min) 96   risk MEDIUM   correlation LOW   status PENDING_USER_APPROVAL

  1. Comet Rangers          vs Delta Athletic          MATCH_RESULT   DRAW           @ 4.11    model p=0.266875 conf=78.59   dq=96   EV=0.096856 risk=LOW
  2. Horizon FC             vs Apex FC                 TOTAL_GOALS    UNDER_3_5      @ 1.4     model p=0.757496 conf=75.29   dq=96   EV=0.060494 risk=MEDIUM

TICKET-FUNNEL-RESULT: TICKET
```

## What the numbers say

* **Nothing was inflated.** Every confidence in the table is the value the
  model measured. The two selected legs report 78.59% and 75.29% because
  that is what they scored — they were not lifted to a threshold.
* **The bar moved, not the score.** All 96 candidates sat in the EXCELLENT
  data band (quality 96), so they faced the full 75% requirement and 23 of
  them cleared it. On a thinner day the GOOD (70%) and LIMITED (65%,
  safer markets only) tiers apply instead, and below quality 65 nothing
  qualifies at any confidence.
* **Multiple markets per fixture.** 6 fixtures produced 96 evaluated
  market:selection candidates across 1X2, Double Chance, Draw No Bet, the
  goal lines and BTTS — instead of one Over 1.5 price each.
* **The ticket is a PREFERRED-tier ticket.** `fallback used: no`: it cleared
  every configured criterion, including the LOW correlation cap and the
  5.00-8.00 odds range. Nothing was forced.
* **Every rejection is auditable.** Each decision row carries the tier it was
  judged in and the exact confidence and data quality required of it, next to
  what it actually measured. The `REJECTION AUDIT` block goes further, giving
  each rejected candidate its Reason, a `Missing:` list, an `Available:` list,
  its Data Quality and the minimum that quality was judged against. On this
  run the missing lists are empty and the available lists are full — the
  rejections are genuine price judgements (`LOW_MODEL_EDGE`), not hidden data
  gaps, which is precisely the distinction the old aggregate counts hid.

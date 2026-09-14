# BUILD BRIEF — EUROPMILLIONS · LOTTERY INTELLIGENCE MODULE
**Complete specification. Everything needed to build it is in this document. Nothing else is required.**

---

## SECTION A — WHAT YOU ARE BUILDING

A full-stack **EuroMillions Lottery Intelligence** module. EuroMillions = 5 main numbers
from 1–50 + 2 Lucky Stars from 1–12, drawn Tuesdays & Fridays 21:00 UTC.

The module has 10 capabilities:

1. Ingest real draw results from the **loteriasapi.com API** (vendor base
   `https://api.loteriasapi.com/api/v1`, auth header `X-API-Key`, SELAE data,
   game code `euromillones`).
2. Validate and store every draw in a historical database (source attribution,
   idempotent, conflict-protected).
3. Statistics: per-number frequency, gaps, hot/cold, distribution (odd/even, low/high,
   sum, spread, consecutive), pairs, triplets, star-pairs.
4. Line generator with 5 modes: RANDOM, BALANCED, HISTORICAL, DIVERSIFIED, ANTI-POPULAR,
   with lock/exclude constraints and seeded reproducibility.
5. Diversification scoring for a set of lines.
6. System (wheel) builder: every C(N,5) × C(S,2) line from a pool, lazily enumerated.
7. Strategy backtesting with no look-ahead and a mandatory random baseline.
8. User tickets: create, auto-check against stored draws, assign official prize tiers.
9. A persisted "Lottery Intelligence report": ranked candidate lines with explanations.
10. Honest-state governance (below) enforced everywhere.

**Stack:** original was PHP 8 + CodeIgniter 3 + MySQL/SQLite + vanilla JS + optional
Next.js. The design is stack-neutral: pure domain classes over a repository interface.
Build it in whatever stack you use — keep the contracts, formulas and rules identical.

---

## SECTION B — NON-NEGOTIABLE RULES (THE HONESTY CONTRACT)

These are hard requirements. Every one is testable. Do not skip any.

1. **Nothing is fabricated.** Missing API key / disabled provider / non-JSON response /
   HTTP error / network failure → state `UNCONFIGURED` / `DISABLED` / `OFFLINE` /
   `NO_DATA` / `DATA UNAVAILABLE` and **zero draws**. Never invent numbers, dates or jackpots.
2. **Honest state machine:** feed online → `ONLINE`; feed down but verified draws stored →
   `STORED DATA`; feed configured-but-unreachable and nothing stored → `DATA UNAVAILABLE`;
   never configured → `NO_DATA`.
3. **Every draw is validated** before storage: counts, ranges, duplicates, real calendar
   date, non-empty source, non-empty source timestamp. Rejected draws are audited, never
   stored as official.
4. **Idempotent ingestion:** re-importing the same draw is a no-op. A VERIFIED draw is
   never silently overwritten — conflicting provider data is audited for manual correction.
5. **Order-insensitive canonicalization:** store and display every number group as
   ascending integers. "46 27 12 19 11" IS "11 12 19 27 46".
6. **Source attribution everywhere:** every stored draw carries `source`,
   `sourceTimestamp`, `retrieved_at`. Every report carries a dataset stamp
   `n=<count>;last=<newest draw date>`.
7. **Scores are STATISTICAL BALANCE SCORES and DIVERSITY SCORES — never probabilities.**
   Win-chance wording is banned. Every surface carries a `scoreMeaning` string.
8. **No "due" numbers.** A number absent for X draws is reported as "absent for X draws"
   only — never "likely to appear next".
9. **Backtests are labeled HISTORICAL SIMULATION.** No look-ahead. Strategies compared on
   the same period. The random baseline is mandatory in every comparison. No strategy is
   declared "better". No fabricated cost or winnings (official pricing/amounts unavailable
   → stay null with an explanatory note).
10. **Actual ticket results, backtest results and sandbox/demo data are three separate
    sections — never mixed.**
11. **Credentials never leak:** API key stored encrypted, redacted from every error
    message/log/view/audit, HTTPS-only upstreams.
12. **Every statistical output carries this exact DISCLAIMER:**

```
Lottery draws are independent random events. Historical frequency, gaps and patterns
are statistical observations only — they do not change the probability of future draws
and are not forecasts of future results.
```

13. **Every generated report carries this exact honesty note:**

```
Every valid EuroMillions combination has exactly the same mathematical chance of being
drawn. A number that appeared frequently, or has not appeared recently, is NOT more
likely to appear next — these are suggestions shaped by historical statistics only,
not predictions, and no suggestion can guarantee or predict the winning numbers.
```

14. **Model versions are immutable** — historical results stay connected to the model
    version that produced them. History-backed generation (BALANCED, HISTORICAL,
    DIVERSIFIED, ANTI-POPULAR) **refuses to run on an empty dataset** with a
    `DATA UNAVAILABLE` error — it must never silently degrade to random. RANDOM remains
    available as an explicitly labeled baseline.

---

## SECTION C — DATABASE (create these 14 tables; MySQL DDL — adapt types to your DB)

```sql
CREATE TABLE lotteries (
 id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1, rules_version VARCHAR(16) NOT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_rules (
 id INT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, version VARCHAR(16) NOT NULL,
 main_count INT NOT NULL, main_min INT NOT NULL, main_max INT NOT NULL,
 star_count INT NOT NULL, star_min INT NOT NULL, star_max INT NOT NULL,
 schedule VARCHAR(255) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_lottery_rules (lottery_code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_data_sources (
 id INT AUTO_INCREMENT PRIMARY KEY, provider_code VARCHAR(64) NOT NULL UNIQUE,
 display_name VARCHAR(120) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
 synthetic TINYINT(1) NOT NULL DEFAULT 0,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_provider_health (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, provider_id INT NOT NULL, status VARCHAR(32) NOT NULL,
 response_ms INT NULL, records_received INT NOT NULL DEFAULT 0, invalid_records INT NOT NULL DEFAULT 0,
 error_rate DECIMAL(8,5) NULL, last_success_at VARCHAR(32) NULL, last_failure_at VARCHAR(32) NULL,
 last_draw_retrieved VARCHAR(32) NULL, data_freshness_seconds INT NULL, synthetic TINYINT(1) NOT NULL DEFAULT 0,
 observed_at VARCHAR(32) NOT NULL, KEY idx_lottery_provider_health (provider_id, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_draws (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, provider_id INT NULL,
 external_id VARCHAR(64) NOT NULL, draw_date DATE NOT NULL, jackpot VARCHAR(32) NULL,
 rollover TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(120) NOT NULL, source_timestamp VARCHAR(40) NOT NULL,
 retrieved_at VARCHAR(32) NOT NULL, verification_status VARCHAR(32) NOT NULL, payload MEDIUMTEXT NOT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
 UNIQUE KEY uq_lottery_draws (lottery_code, external_id), KEY idx_lottery_draws_date (lottery_code, draw_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_draw_numbers (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, draw_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL,
 position INT NOT NULL, number INT NOT NULL, KEY idx_lottery_draw_numbers_draw (draw_id, kind, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_sync_runs (
 id VARCHAR(64) PRIMARY KEY, provider_id INT NULL, job_type VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL,
 started_at VARCHAR(32) NOT NULL, ended_at VARCHAR(32) NULL,
 records_processed INT NOT NULL DEFAULT 0, records_created INT NOT NULL DEFAULT 0, records_updated INT NOT NULL DEFAULT 0,
 errors TEXT NULL, payload MEDIUMTEXT NULL, execution_key VARCHAR(128) NOT NULL UNIQUE,
 KEY idx_lottery_sync_runs_job (job_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_combinations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, `mode` VARCHAR(32) NOT NULL,
 model_version VARCHAR(64) NOT NULL, seed VARCHAR(32) NULL, line_count INT NOT NULL DEFAULT 0,
 `lines` MEDIUMTEXT NOT NULL, `constraints` MEDIUMTEXT NOT NULL, score_summary MEDIUMTEXT NOT NULL,
 created_by INT NULL, created_at VARCHAR(32) NOT NULL, KEY idx_lottery_combinations_code (lottery_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_ai_decisions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, combination_id BIGINT NULL,
 model_version VARCHAR(64) NOT NULL, mode VARCHAR(32) NULL, decision MEDIUMTEXT NOT NULL,
 created_at VARCHAR(32) NOT NULL, KEY idx_lottery_ai_decisions_comb (combination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_tickets (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, lottery_code VARCHAR(32) NOT NULL,
 name VARCHAR(120) NOT NULL, draw_date DATE NULL, generation_method VARCHAR(32) NOT NULL,
 model_version VARCHAR(64) NOT NULL, configuration MEDIUMTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'OPEN', result MEDIUMTEXT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_tickets_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_ticket_lines (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT NOT NULL, position INT NOT NULL,
 mains TEXT NOT NULL, stars TEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_ticket_lines_ticket (ticket_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_backtests (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, strategy VARCHAR(40) NOT NULL,
 model_version VARCHAR(64) NOT NULL, lines_per_draw INT NOT NULL DEFAULT 1, draws_tested INT NOT NULL DEFAULT 0,
 period_from DATE NULL, period_to DATE NULL, dataset_version VARCHAR(128) NULL,
 report MEDIUMTEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_backtests_strategy (strategy, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lottery_model_versions (
 id INT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(64) NOT NULL, model_version VARCHAR(16) NOT NULL,
 config MEDIUMTEXT NOT NULL, dataset_version VARCHAR(128) NULL, status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
 created_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_lottery_model_versions (model_name, model_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Notes:
- `lottery_draws.payload` is JSON: `{"main":[..],"stars":[..],"jackpot":..,"rollover":..,
  "winners":..,"prizes":[{category,label,winners,amount,currency}]|"null",
  "totalWinners":..,"drawDate":"..","extra":{"elMillon","status","dayOfWeek",
  "prizeTiers","numberLayout","rawCombination","feedSource","jackpotNext"}}`
- `lottery_draws.verification_status` = `'VERIFIED'` (only ever set AFTER validation passes).
- `lottery_sync_runs.execution_key` UNIQUE is the entire idempotency mechanism. Key formats:
```
sync:EUROMILLIONS:YYYY-MM-DD
backtest:EUROMILLIONS:<STRATEGY>:YYYY-MM-DD
intelligence:EUROMILLIONS:YYYY-MM-DD:<newest-draw-date>
system:EUROMILLIONS:md5(implode(',',mainPool) . '|' . implode(',',starPool))
```
- `lottery_rules.schedule` is JSON: `{"days":[2,5],"time":"21:00","timezone":"UTC"}`
  (days 0=Sunday … 6=Saturday).
- Ticket statuses: `OPEN | CHECKED | ARCHIVED`.
- Combination modes: `RANDOM | BALANCED | HISTORICAL | DIVERSIFIED | ANTI-POPULAR |
  INTELLIGENCE | SYSTEM`.

---

## SECTION D — DOMAIN CODE (build these 12 components as pure, framework-free classes)

### D1. Rules engine

Rules are DATA, not code assumptions. Interface:

```
code(): 'EUROMILLIONS'      name(): 'EuroMillions'      version(): '1.0'
mainCount(): 5   mainMin(): 1   mainMax(): 50
starCount(): 2   starMin(): 1   starMax(): 12
drawSchedule(): {days:[2,5], time:'21:00', timezone:'UTC'}
validateLine(main[], stars[]): {valid: bool, errors: string[]}
fromArray(code, name, storedRow): rules      // rebuild from DB row
```

`validateLine` must produce these EXACT error strings (tests depend on wording):
- `line must contain 5 main numbers (got 7)` (count mismatch; same pattern for Lucky Star)
- `main number out of range 1-50: 99`
- `duplicate main numbers`

The active rules are read from the `lottery_rules` table when present; the code default
(above) is authoritative otherwise — so rule changes need no deploy.

### D2. Provider abstraction

```
interface LotteryProvider {
  id(): string                      // 'loteriasapi' | 'official-euromillions' | 'sandbox-sim' | 'unconfigured'
  name(): string                    // user-facing display label
  health(): {state: ONLINE|OFFLINE|UNCONFIGURED|DISABLED, licensed: bool, synthetic: bool, message: string}
  draws(from?: 'YYYY-MM-DD', to?: 'YYYY-MM-DD', limit=100): normalizedDraw[]
  jackpotInfo(): {source, value, currency, observedAt, note} | null
}

normalizedDraw = {
  externalId: string, drawDate: 'YYYY-MM-DD', main: int[], stars: int[],
  jackpot: string|null (e.g. "130000000.00"), rollover: bool, winners: string|null,
  prizes: [{category,label,winners,amount,currency}]|null, totalWinners: int|null,
  source: string, sourceTimestamp: ISO-8601,
  extra: {status, dayOfWeek, prizeTiers, elMillon, numberLayout, rawCombination, feedSource, jackpotNext}
}
```

Implement FOUR providers. Selection order at the composition root — first configured wins:
`LoteriasAPI → Official → Sandbox → Unavailable`.

**Provider 1 — UnavailableProvider (the default):** health `UNCONFIGURED` with message
"No lottery data provider configured", zero draws, jackpotInfo null.

**Provider 2 — SandboxProvider (simulation, for pipeline testing):** only active when env
`WINDELS_LOTTERY_SANDBOX=1`. Deterministic seeded PRNG (seed 42). Walks backwards
day-by-day emitting only on schedule days (Tue/Fri). source = `sandbox-simulation`,
synthetic = true, externalId = `SIM-YYYY-MM-DD`, jackpot randomized €1M–€20M, rollover
when rand() > 0.8. Health message: "Simulated draws for pipeline testing only — NOT
official results". Never presented as official.

**Provider 3 — OfficialProvider (generic authorized feed):** speaks the neutral contract:
`GET {url}/draws?from=&to=&limit=` → `{data:{draws:[...]}}` and `GET {healthUrl}` →
`{ok:true,version}`. Bearer-token auth. **Fail-closed:** offline unless explicitly
enabled AND HTTPS url AND license metadata AND source identifier are all configured.
Env vars: `WINDELS_LOTTERY_OFFICIAL_{URL,HEALTH_URL,TOKEN,LICENSE,SOURCE,ENABLED,JACKPOT_URL}`.

**Provider 4 — LoteriasApiProvider (the real adapter). Full requirements:**

Constants:
```
DEFAULT_BASE_URL = 'https://api.loteriasapi.com/api/v1'
DEFAULT_GAME     = 'euromillones'        // 'euromillions' normalized to it
MAX_RANGE_DAYS   = 364                   // vendor caps /range at 365 days
MAX_PAGES        = 20                    // paging guard per window
PAGE_SIZE        = 100                   // requested page size
MIN_PAGE_SIZE    = 5                     // documented Free-plan floor (400-retry size)
LATEST_TTL       = 60 seconds            // memoize /latest (status + jackpot = 1 request)
UNFINISHED_STATUSES = ['PENDING','SCHEDULED','PROGRAMADO','PROCESSING','IN_PROGRESS','CANCELLED','CANCELED']
GAME_FORMATS = {'euromillones': {main:[5,1,50], stars:[2,1,12]}}
Backfill ceiling: 5000 draws (only ~2,300 exist since 2004)
```

Config resolution order: explicit constructor args → admin "API Management" row (only when
driver === 'loteriasapi') → env vars:
```
WINDELS_LOTTERY_LOTERIASAPI_KEY        (required; presence alone also enables)
WINDELS_LOTTERY_LOTERIASAPI_ENABLED=1
WINDELS_LOTTERY_LOTERIASAPI_BASE_URL   (default above)
WINDELS_LOTTERY_LOTERIASAPI_GAME       (default euromillones)
WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT    (default 8s, cap 60)
WINDELS_LOTTERY_LOTERIASAPI_SOURCE     (display label, default your brand)
```

Endpoints (all GET, header `X-API-Key`):
```
/results/{game}/latest                         → {success, data:{draw}, timestamp}
/results/{game}/range?from=&to=&page=&limit=   → {success, data:[draws], meta:{hasNext,limit}}
/results/{game}/date/{yyyy-mm-dd}              → {success, data:[draws]}
/results/{game}?page=&limit=&sort=drawDate&order=desc
```

**Base-URL self-healing (normalizeBaseUrl):** the marketing host `loteriasapi.com` → API
root; `/v1` → `/api/v1` (the vendor's advertised `/v1` 404s on every route — the `/api`
prefix is REQUIRED); a pasted full docs URL reduces to its versioned root; any other host
(custom gateway) stays exactly as configured. Refuse plain `http://` (HTTPS only) and any
URL containing userinfo.

**Payload mapping (vendor → neutral):**
```
drawId | draw_id | id                      → externalId
drawDate | draw_date | date | fecha        → drawDate
combination | numbers | combinacion        → main
resultData.estrellas | stars | luckyStars
  | extraNumbers | winningExtraNumbers     → stars
jackpotFormatted "130.000.000,00 €" (authoritative)
  else jackpot "13000000000" = integer CENTS → /100          → jackpot "130000000.00"
prizes[top tier].winners == 0              → rollover=true, winners="0"
updatedAt | envelope timestamp
  | fallback drawDate+"T21:00:00+00:00"    → sourceTimestamp
status, dayOfWeek, El Millón code,
  prize count, layout provenance           → extra.*
full prizes[] (category, label, winners, amount) → prizes + totalWinners
```

Top-tier detection: category `"1"`, `"1a"`, `"1ª"`, or match `"5+2"`, or categoryName
contains `5+2` (whitespace stripped).

Money parsing: formatted string `"130.000.000,00 €"` → strip everything except digits
and `.,` → the LAST separator with ≤2 trailing digits is the decimal separator →
`"130000000.00"`. An integer with no separators = cents. A rolled-over top-tier prize
(≤ 0) stays null — never store a zero jackpot.

**THE FLAT-COMBINATION SPLIT (critical).** The live feed sometimes publishes the whole
winning line as ONE array: `combination: [7,12,29,33,45,3,9]` (5 numbers then 2 stars).
Taken literally it fails validation ("got 7"). Re-group ONLY when provably safe:

| Situation | Split? |
|---|---|
| Row also has `resultData.estrellas` and the flat list ENDS with exactly those values | YES → extra.numberLayout = 'flat-combination-split' |
| …and the flat list BEGINS with exactly those values (stars first) | YES → 'flat-combination-split-stars-first' |
| No star field, list is exactly mainCount+starCount long, first 5 unique & in 1–50, last 2 unique & in 1–12 | YES (the only possible 5+2 layout) |
| Anything else (unknown game, out-of-range, duplicates inside a group, wrong length) | **NO** — leave untouched → it gets rejected and audited. NEVER reshape a broken line into a valid-looking one. |

Every re-grouped draw keeps the vendor's original list in `extra.rawCombination`.

**draws() — windowed, quota-aware backfill:**
1. Clamp limit 1..5000. Without an explicit window: `weeks = ceil(limit/2)+2`, capped at
   1300 (~25 years — covers the 2004 launch without wasting calls on pre-2004 emptiness);
   from = to − weeks.
2. Split the window into ≤364-day chunks; walk **NEWEST-FIRST**.
3. Page through /range per window following `meta.hasNext` (max 20 pages).
4. De-duplicate raw rows by `id|date` across overlapping windows.
5. **Implicit backfill stops at the first window that adds nothing new** (a 7-day-history
   Free key then costs ~2 requests, not one per year of unreadable archive). Explicit
   caller windows are answered in full.
6. If /range yields nothing → try the listing endpoint → then /latest as LAST RESORT,
   **only if that single draw is finished AND has both number groups** (on draw day
   /latest answers the NEXT draw as a PENDING placeholder with no numbers — never offer
   it as history).
7. Normalize → skip unfinished statuses → enforce explicit windows → de-duplicate by
   `externalId|drawDate` → sort newest-first → slice to limit.

**Plan-capped page sizes:** ask limit=100. On HTTP 400 naming the limit, retry ONCE at 5.
Then adopt `meta.limit` (what the plan actually served) for all later pages.

**drawById():** accepts `YYYY-MM-DD` (uses /date/ route) or a vendor id `2026029` /
`2026/029` → ±30-day bracketed /range query with exact drawId matching. Never infer
anything from the id itself.

**Error mapping (exact messages):**
- 401/403 → `authentication rejected (HTTP n)`
- 429 → `rate limited (HTTP 429) — the plan request quota is exhausted`
- 404 → `not found (HTTP 404) — the base URL must be https://api.loteriasapi.com/api/v1`
- 400 → quote the vendor's own error body (code, message, field)
- no response → `no HTTP response (network/SSL/firewall)`
- non-JSON 200 → `invalid JSON payload`
- 200 with `{success:false}` envelope → treat as FAILURE (vendor code/message;
  UNAUTHORIZED/FORBIDDEN = auth failure) — never as a draw.

**Key redaction (safeMessage):** replace the API key string with `••••` everywhere,
regex-redact `x-api-key: <anything>`, truncate to 180 chars. The key must never appear in
any error, log, view, or audit trail.

**Branding rule:** the class is the vendor integration, but `name()` returns your product
label (e.g. "YourBrand API — EuroMillions"); the STORED source attribution keeps the real
feed identity. Admin UI and CLI keep the vendor name (operators need it).

### D3. Validator (ingestion gatekeeper)

Every draw must pass ALL 5 checks before storage:
1. non-empty externalId
2. drawDate matches `YYYY-MM-DD` AND is a real calendar date
3. main/stars coerced to ints (non-numeric → −1, always out of range) then rules
   validation (counts, ranges, duplicates)
4. non-empty source ("no draw may be stored without a source")
5. non-empty sourceTimestamp

Return `{valid, status: VALID|DATA_VALIDATION_FAILED, errors[]}`. Failures are audited
(event `LOTTERY_DRAW_VALIDATION_FAILED`), never stored.

### D4. Statistics engine (pure functions)

Input shape everywhere: `[{drawDate, main:int[], stars:int[]}]` sorted ASC by date.

- **numberStats / starStats(draws, min, max, window=0)** — per number: `appearances`,
  `appearancePct`, `lastAppearance` (date), `drawsSinceLast` (alias `currentGap`),
  `avgGap` / `minGap` / `maxGap` (gaps between consecutive appearances),
  `recentAppearances` / `recentPct` (last-N window), `trend` (recent share − overall
  share — an observation label, not a forecast).
- **hotCold(draws, field, min, max, window=50, top=5)** — top/bottom 5 by count in the
  window. Note text: "Historical frequency within the last N draw(s) only. This does NOT
  predict future draws — every number keeps its exact probability each draw."
- **distribution(draws, min, max, mainCount)** — odd/even + low/high histograms (low ≤
  midpoint = 25) with percentages; sum (min/max/avg/median); spread (min/max/avg);
  consecutive (draws containing runs, %, longest run, run-length distribution).
- **groupStats(draws, field, min, max, k, topN=20)** — k-subset co-occurrence (k=2 pairs,
  3 triplets, k capped at 5): all k-combinations of each draw's numbers, key `"a-b-c"`
  sorted; per group: count, lastSeen, avgGap, maxGap; sorted count desc, top slice.

Window parsing: `0`/`all` = all history; `1y`/`2y` = ×104 draws; `6m` = ×52; plain
number = last N draws. (EuroMillions ≈ 104 draws/year.)

### D5. Combination analyzer (per-line profile + BALANCE SCORE)

For one validated, sorted line vs the stored draws, compute:
- **Composition:** odd/even, low/high (low ≤ 25), sum, spread, adjacent pairs, longest
  run, runs of 3+
- **Pattern traits:** all-same-last-digit, within-single-decade, birthday count (1–31),
  multiples of 5
- **Historical context:** sum & spread percentiles; most-common odd and low counts (mode
  of the historical count distribution, ties → nearest ideal → smaller); best historical
  overlap (draw sharing most numbers); draws sharing ≥3 numbers; same-odd/even count;
  draws with |Δsum| ≤ 10
- **numberProfile / starProfile:** per-number stats for each selected number

**STATISTICAL BALANCE SCORE (0–100) — exact formulas:**

```
WEIGHTS = {sum: 0.30, oddEven: 0.20, lowHigh: 0.20, spread: 0.15, consecutives: 0.15}

sumFit          = histStd > 0
                  ? max(0, 100 * (1 - |sum - histAvg| / (2 * histStd)))
                  : (sum == histAvg ? 100 : 50)
oddEvenFit      = max(0, 100 - 20 * |odd - bestOdd|)
lowHighFit      = max(0, 100 - 20 * |low - bestLow|)
spreadFit       = spreadAvg > 0
                  ? max(0, 100 * (1 - |spread - spreadAvg| / spreadAvg))
                  : 100
consecutivesFit = max(0, 100 - 25 * adjacentPairs)

score = round(Σ weight_i * fit_i)        // zero draws → neutral score 50
```

Labels: `scoreLabel = "STATISTICAL BALANCE SCORE: N/100"`.
scoreMeaning: "How closely this line matches typical historical draw composition (sum,
odd/even, low/high, spread, consecutive patterns). It is NOT a probability and it does
not indicate how likely the line is to be drawn."

### D6. Generator (5 modes, deterministic)

`MODES = [RANDOM, BALANCED, HISTORICAL, DIVERSIFIED, ANTI-POPULAR]`, `MAX_LINES = 100`.

**Seeded PRNG — xorshift32 (port exactly for reproducibility):**

```
s = (seed != 0) ? seed & 0xFFFFFFFF : 1
next(): s ^= (s << 13) & 0xFFFFFFFF
        s ^= s >> 17
        s ^= (s << 5)  & 0xFFFFFFFF
        return s / 4294967296.0        // uniform [0,1)
```
Seed: caller-supplied else `(int)(microtime*1e6) % 2147483647` (0 → 1). Same seed + same
inputs → identical lines forever. Record the seed in every report (`reproducible: true`).

**Locks/excludes (validate up front):** locked numbers always present (≤ pick count),
excluded never present, lock ∩ exclude = error, the remaining pool must have enough
numbers. Errors: `cannot lock more than 5 main numbers`, `a main number is both locked
and excluded`, `not enough main numbers remain after exclusions`.

**Mode mechanics (rejection sampling, per-line attempt cap kMax):**

| Mode | kMax | Rule |
|---|---|---|
| RANDOM | 1 | Uniform sample without replacement (seeded Fisher–Yates prefix) |
| BALANCED | 60 | Targets computed from history: sum within ±1σ of average; odd count AND low count within ±1 of the most common historical split; ≤1 adjacent pair. Reject candidates until one fits. |
| HISTORICAL | 1 | Weighted sample without replacement, weight = 1 + appearances |
| DIVERSIFIED | 24 | Generate candidates; pick the lowest overlap penalty vs context lines (10 per shared main, 20 per shared star). Context = caller-provided lines + lines already generated in this call. |
| ANTI-POPULAR | 80 | Reject: >2 numbers in 1–31 (birthday-heavy); ascending run ≥3; >1 adjacent pair; all-same last digit; all numbers within one decade (max−min < 10). |

If nothing passes after kMax attempts, fall back to the best-so-far pool (never fail
silently). Re-validate every emitted line against the rules, then profile it (score +
composition summary).

**Report shape (persist it — this is the "AI decision report"):**
```json
{
  "model": "<YourBrand> Lottery Model v1.0", "lottery": "EUROMILLIONS", "mode": "BALANCED",
  "lineCount": 5,
  "lines": [{"mains":[..],"stars":[..],"score":87,
             "scoreLabel":"STATISTICAL BALANCE SCORE: 87/100",
             "profile":{"sum":..,"spread":..,"oddEven":"3 odd / 2 even",
                        "lowHigh":"2 low / 3 high","adjacentPairs":0}}],
  "averageBalanceScore": 84.3,
  "inputs": {"seed":123,"rulesVersion":"1.0","drawsUsed":60,"lastDrawDate":"2026-09-04",
             "datasetVersion":"n=60;last=2026-09-04",
             "locks":{"mains":[],"stars":[]},"excludes":{"mains":[],"stars":[]},
             "contextLines":0},
  "factors": {"method":"...","targets":{...},"historicalBasis":{...},"note":"..."},
  "generatedAt":"...","disclaimer":"<DISCLAIMER>","honestyNote":"<HONESTY NOTE>"
}
```
`factors` records the ACTUAL method/targets/weights used — never invented after the fact.
Include per-mode notes, e.g. ANTI-POPULAR: "Avoiding popular patterns may reduce the
chance of sharing a prize if a line happens to win. It never changes the chance of
winning."

### D7. Diversification engine (set-diversity score)

All-pairs comparison of validated, sorted lines (O(n²), bounded by line size):

```
sharedMain = |mainA ∩ mainB|      sharedStar = |starA ∩ starB|
sharedPairs = C(sharedMain, 2)    sharedTriplets = C(sharedMain, 3)
sameOE / sameLH  = identical odd/even (or low/high) split counts
avgAbsSumDiff    = mean |sumA − sumB|        duplicates = identical lines
pairReuse        = (total pair instances − unique pairs) / total pair instances

DIVERSITY SCORE (0–100):
penalty = 45*(avgMain/mainCount) + 15*(avgStar/starCount) + 10*(sameOEPct/100)
        + 10*(sameLHPct/100) + 5*(1 − min(1, avgAbsSumDiff/30)) + 30*(identicalPairs/pairCount)
score   = clamp(100 − penalty, 0, 100)
```
Report: overlaps (avg+max for mains/stars/pairs/triplets), distributionSimilarity,
pairReuse, `scoreLabel: "DIVERSITY SCORE: N/100"` + meaning note ("NOT a measure of how
likely any line is to be drawn").

### D8. System (wheel) builder

Constants: `SYNC_LINE_LIMIT = 10000` (larger systems go to a background queue),
`MAX_BACKGROUND_LINES = 200000`, `MAX_PAGE = 500`.

- **plan(mainPool, starPool):** normalize pools (unique in-range ints, sorted; ≥5 mains,
  ≥2 stars). totalLines = C(N,5) × C(S,2) computed combinatorially — NEVER hardcoded.
  Binomial: `k=min(k,n−k); c=1; for i in 1..k: c = c*(n−k+i)/i`. Return the formula
  string (`"C(9,5) x C(4,2) = 126 x 6"`), `estimatedCost: null` + note "Official line
  pricing is not available in this environment — no cost is fabricated.", 100% pool
  coverage note ("coverage of the pool is not a statement about winning"), and a
  `requiresBackground` flag when totalLines > SYNC_LINE_LIMIT.
- **lines():** lazy generator (constant memory): lexicographic k-subset iteration
  (index array; advance the last possible index; reset the tail), main combinations
  outer, star combinations nested.
- **page(mains, stars, offset, limit):** windowed slice for interactive use (limit ≤ 500).
- **allLines():** materialize — only for bounded builds.

### D9. Backtester (Strategy Lab)

`STRATEGIES = [RANDOM_BASELINE, BALANCED_PROFILE, HISTORICAL_FREQ, ANTI_POPULAR]`,
`MIN_HISTORY = 10` (draws required before the first test draw), `MAX_WINDOW = 100`,
`MAX_LINES = 10`.

- **No look-ahead:** for test draw i the strategy sees only draws[0..i-1].
- **Deterministic seed per draw:**
  `seed = crc32(strategy) ^ (drawIndex * 2654435761) ^ crc32('<Brand>-Lottery-Model-v' + version)`
- Strategy→mode mapping: RANDOM_BASELINE→RANDOM, BALANCED_PROFILE→BALANCED,
  HISTORICAL_FREQ→HISTORICAL, ANTI_POPULAR→ANTI-POPULAR.
- Report (labeled `HISTORICAL SIMULATION`): period (from/to/drawsTested/minHistoryDraws),
  linesPerDraw, totalLines, matchDistribution (mains 0–5, stars 0–2 histograms),
  tierCounts, bestLine, `simulatedCost: null` + note, `simulatedWinnings: null` + note
  (NO cost or winnings ever fabricated — tier counts only), perDraw detail, disclaimer,
  random-baseline note.
- **compare(strategies, lines, window):** all strategies replayed on the SAME period.
  **Reject with an error if RANDOM_BASELINE is missing** ("the random baseline must be
  part of every comparison"). Never declare a strategy "better".

**Official prize tier table (labels only — amounts vary per draw and are NEVER stored):**
```
5 main + 2 stars → TIER_1        5+1 → TIER_2        5+0 → TIER_3
4+2 → TIER_4     4+1 → TIER_5    3+2 → TIER_6        3+1 → TIER_7
2+2 → TIER_8     1+2 → TIER_9    0+2 → TIER_10       else null
```

### D10. Facade (the service everything calls)

Constants:
```
MODEL_VERSION = '1.0'      LOTTERY = 'EUROMILLIONS'
FULL_HISTORY_LIMIT = 5000  DRAWS_PER_YEAR = 104
MIN_RELIABLE_DRAWS = 50    INTELLIGENCE_MODE = 'INTELLIGENCE'
HISTORY_BACKED_MODES = [BALANCED, HISTORICAL, ANTI-POPULAR, DIVERSIFIED]
MAX_TICKET_LINES = 50
TICKET_METHODS = [MANUAL, RANDOM, BALANCED, HISTORICAL, DIVERSIFIED, ANTI-POPULAR]
```

Wires: statistics, rules (stored→default), provider, analyzer, generator,
diversification, systemBuilder, backtester. Ensures the lottery registry row.

**Canonicalization:** ints ascending on storage AND on every read surface (expose
`numbers.main/stars`, `main_numbers`, `lucky_stars`, `draw_no`). Idempotency is
order-insensitive (compare sorted groups).

**status()** — one dashboard payload: provider health, rules, engine state, drawsTracked,
lastDraw, disclaimer, plus widget aliases: `status` (ONLINE / STORED DATA / DATA
UNAVAILABLE / NO_DATA per the state machine), `jackpot`, `jackpotSource` `{origin:
PROVIDER_FEED|STORED_DRAW, provider, observedAt, currency, hardcoded:false}` (proves the
amount is feed data), `verifiedDraws`, `dataAvailable`, `historicalDataset`, 
`lastSuccessfulSync`, `lastSyncAttempt`, `syncStatus` (OK/DEGRADED/STALE/FAILED/
NEVER_SYNCED), `syncMessage`, `nextEstimated` (next Tue/Fri within 8 days — a hint).

**sync(limit):** health gate — if not ONLINE and state is offline-ish: record an OFFLINE
health row + audit `LOTTERY_SYNC_FAILED` + return `DATA UNAVAILABLE` (unconfigured →
`NO_PROVIDER`). Else: draws() → importDraws() → write a health row (ONLINE/DEGRADED,
response_ms, records received/invalid, newest draw date) → audit
`LOTTERY_SYNC_COMPLETED` → return summary + dataset stamp. A database write failure is
reported as a FAILED sync carrying the error — never as a clean sync.

**importDraws(raw)** — per draw: validate → normalize ascending → look up by externalId:
- identical numbers (order-insensitive) → count `unchanged` (no-op)
- exists & VERIFIED & different → count `conflicts` + audit `LOTTERY_RESULT_CONFLICT`
  ("NOT overwritten; manual correction required")
- exists & unverified & different → correct it + audit `LOTTERY_DRAW_CORRECTED`
- new → store as `VERIFIED` (strictly AFTER validation passed) + audit
  `LOTTERY_DRAW_IMPORTED`

**historicalDataset()** — THE single accessor for stored VERIFIED draws, read by
statistics, analyzer, generator, backtests alike. datasetInfo() →
`{source:'VERIFIED_HISTORICAL_DATABASE', draws:n, from, to, available,
datasetVersion:'n=60;last=2026-09-04'}`.

**generate()** — history-backed mode + empty dataset → throw `DATA UNAVAILABLE — <MODE>
generation requires the verified historical dataset, which is empty. Synchronize the
lottery provider first, or use mode RANDOM for an explicit random baseline.`

**intelligenceReport(lines=5, seed)** — the flagship report, verified data ONLY:
- dataState: `INSUFFICIENT_DATA` (0 draws → **NO candidate lines at all** + warning),
  `LIMITED_DATA` (<50 draws → provisional warning), `RELIABLE`
- main/star field stats (most/least frequent, recent-hot, longest absence — each with the
  "does NOT make any number more likely" note), recurring pairs/triplets/star-pairs,
  full distribution
- candidates: BALANCED generation (seeded), ranked by score, each with scoreBreakdown,
  composition, and a plain-English explanation list, e.g.:
  - "Sum 132 — the historical sum average is 129 (typical range 107–151)."
  - "3 odd / 2 even — matches the most common historical split (3 odd / 2 even)."
  - "Spread 41 (historical average 35)."
  - "One adjacent pair."
  - "Includes 2 of the most frequent main numbers (7, 19)."
  - "Includes 1 main number(s) with the longest current absence (32) — absence is a
    historical observation, not a reason to expect them."
- provenance: provider id/name ONLY ("the API key is never exposed"), verifiedOnly true,
  "Nothing is hard-coded or fabricated."
- scoreMeaning, scoreWeights, disclaimer, honestyNote, generatedAt, asOfDrawDate, seed,
  reproducibleNote

**runIntelligence()** — sync first (full backfill on empty DB, else 100-draw delta) →
report → persist (combination row with mode INTELLIGENCE + AI decision row) → audit
`LOTTERY_INTELLIGENCE_RUN`.

**Tickets:**
- createTicket(userId, name, lines, method, drawDate?, modelVersion?, configuration):
  validate EVERY line (one bad line rejects the whole ticket — error `line 2 invalid:
  <reasons>`); ≤50 lines; method whitelist; strict YYYY-MM-DD check; sort lines on save;
  audit `LOTTERY_TICKET_CREATED`
- checkTicket(id, userId?): compare against the latest VERIFIED draw on or before the
  ticket's date (latest overall if no date). Per line: mainMatches, starMatches,
  prizeTier. Marks the ticket CHECKED with the result JSON. No stored draw →
  `{status:'NO_DRAW', note:'no stored VERIFIED draw on or before <date>'}`. Audit
  `LOTTERY_TICKET_CHECKED`.
- archiveTicket: soft delete (status ARCHIVED), user-scoped, audited.
- **User isolation: a user only ever sees their own tickets** (unless they hold the
  manage permission).

**Model versioning:** ensureModelVersion() upserts an immutable row (unique name+version)
recording score weights, generator modes, backtester strategies/limits. Rows are never
deleted or replaced.

**performance()** — three sections, never mixed: ACTUAL TICKET RESULTS (checked tickets,
tier histogram), HISTORICAL BACKTEST RESULTS (recent runs), DEMO/SANDBOX DATA (synthetic
flag) — each with a note + a separation note + disclaimer.

**syncNotice(result)** — one-line operator message: "Sync complete: N imported, N
unchanged, N rejected; N verified draws stored." plus, whenever anything was rejected or
failed, " First issue: <first underlying reason>" (capped at 200 chars). An operator must
never have to decode a bare "1 rejected".

### D11. Cron service (8 jobs)

`JOBS = [sync, health, statistics, systems, tickets, backtests, intelligence, cleanup]`.
Run via CLI (`tools lottery-cron [job]`) and a platform scheduler entry **"Lottery
sweep", every 6 hours (21600s), default enabled**. runAll() isolates failures (one failed
job never aborts the sweep; each failure audited `LOTTERY_JOB_FAILED`).

| Job | Behavior |
|---|---|
| sync | execution key `sync:EUROMILLIONS:YYYY-MM-DD` — once per day. Full backfill (5000) on empty DB, else 100-draw delta. |
| health | live provider state; **DEGRADED when last success > 8 days old** (draws are twice weekly). |
| statistics | integrity sweep — re-validate EVERY stored draw; violations audited `LOTTERY_INTEGRITY_VIOLATIONS` (fix path = manual correction, never silent rewriting). |
| systems | process queued `system` job runs (execution-key idempotent); save SYSTEM combinations; reject > 200,000 lines. |
| tickets | auto-check OPEN tickets whose draw date ≤ latest verified draw (CHECKED tickets never re-checked). |
| backtests | one backtest per strategy per day (`backtest:EUROMILLIONS:<strategy>:<date>` key); the random baseline runs every day. |
| intelligence | regenerate the report ONLY when a new verified draw landed since the last persisted report (else `UP_TO_DATE`); key includes the newest draw date. |
| cleanup | delete job runs > 90 days, health rows > 30 days. |

---

## SECTION E — PERSISTENCE INTERFACES (implement over your ORM; domain code never touches the framework)

```
LotteryRepository:
  ensureLottery(code, name, rulesVersion) → row
  listLotteries() → rows
  activeRules(lotteryCode) → row|null            // null → code default applies
  saveRules(row) → id
  ensureProvider(code, name) → row
  listProviders(enabledOnly=false) → rows
  saveHealth(providerId, health)                  // ALWAYS insert (history)
  latestHealth(providerId) → row|null
  listHealth(providerId, limit=20) → rows
  findDraw(id) → row|null
  findDrawByExternal(lotteryCode, externalId) → row|null
  listDraws(filter, limit=100, order='DESC') → rows
  saveDraw(row) → {row, created}
  listDrawNumbers(drawId) → rows
  saveDrawNumbers(drawId, numbers)
  drawsForStats(lotteryCode, limit=10000) → [{drawDate, main[], stars[]}] ASC decoded
  countDraws(lotteryCode) → int
  startJobRun(run) → row|null                     // null when execution_key already exists
  finishJobRun(id, result)
  listJobRuns(jobType?, limit=50) → rows
  findJobRunByKey(key) → row|null
  deleteOldJobRuns(cutoff); deleteOldHealth(cutoff)
  saveCombination(c) → {row, created}; findCombination(id); listCombinations(limit, offset)
  saveAiDecision(d) → {row, created}; findAiDecision(id); listAiDecisions(combinationId?, limit)
  saveTicket(t) → {row, created}; findTicket(id, userId?)          // userId given = scoped
  listTickets(userId, limit=50); listAllTickets(limit=200); updateTicket(id, patch)
  ticketLines(ticketId); saveTicketLines(ticketId, lines)
  ensureModelVersion(m) → row                     // upsert by (name, version), immutable
  listModelVersions() → rows
  saveBacktest(b); listBacktests(limit=50); findBacktest(id)

AuditRepository:
  emit(type, summary, detail, actor)              // append-only
  recent(limit=100) → rows
```

**Complete audit-event vocabulary (use these exact event names):**
```
LOTTERY_DRAW_IMPORTED         LOTTERY_DRAW_CORRECTED
LOTTERY_RESULT_CONFLICT       LOTTERY_DRAW_VALIDATION_FAILED
LOTTERY_SYNC_COMPLETED        LOTTERY_SYNC_FAILED
LOTTERY_COMBINATION_GENERATED LOTTERY_SYSTEM_QUEUED
LOTTERY_SYSTEM_BUILT          LOTTERY_TICKET_CREATED
LOTTERY_TICKET_CHECKED        LOTTERY_TICKET_ARCHIVED
LOTTERY_BACKTEST_RUN          LOTTERY_BACKTEST_FAILED
LOTTERY_INTELLIGENCE_RUN      LOTTERY_INTELLIGENCE_FAILED
LOTTERY_JOB_FAILED            LOTTERY_CRON_RUN
LOTTERY_INTEGRITY_VIOLATIONS
```

---

## SECTION F — API SPECIFICATION (31 endpoints)

**Security model (implement exactly):**
- A **route allowlist** in the JSON base controller decides public routes. Any
  non-listed route without a session → 401 JSON `{"error":"unauthenticated"}`, or a
  login redirect for HTML GET requests (preserve return URL + query string).
- Mutations require RBAC **and** a timing-safe CSRF check: compare the `X-CSRF-Token`
  request header against the session token using a constant-time comparison.
- Permissions refresh from the database on every call (never trust a sign-in snapshot).
- JSON body: read the raw request body, decode, fall back to form POST.

**RBAC:** `lottery.view` = view draws/statistics/tickets/performance.
`lottery.manage` = manage providers, sync, configuration. Roles: `lottery_admin` → both;
`lottery_viewer` → view; base member role includes view.

| # | Route | Method | Auth | Request | Response / rules |
|---|---|---|---|---|---|
| 1 | `/api/lottery/status` | GET | public | — | full status payload (D10) |
| 2 | `/api/lottery/dashboard` | GET | public | — | widget shape: status, jackpot + jackpotFormatted (€130.0M / €850K), recent 3 draws, myTicketsCount, sync block. On ANY failure return the honest default: status NO_DATA, syncMessage "DATA UNAVAILABLE — the lottery module could not be read." |
| 3 | `/api/lottery/lotteries` | GET | public | — | `[{code,name,enabled}]` |
| 4 | `/api/lottery/rules` | GET | public | — | counts/ranges/schedule |
| 5 | `/api/lottery/draws` | GET | public | `?limit=50&from&to` | newest-first, canonical ascending groups |
| 6 | `/api/lottery/draws/{id}` | GET | public | — | draw detail + numbers; 404 if missing |
| 7 | `/api/lottery/statistics/{kind}` | GET | public | `?kind&window` — kinds: frequency, gap, hot-cold, distribution, stars, pairs, triplets, star-pairs; window: 0=all, N, 1y, 2y, 6m | stats + dataset stamp. **Content negotiation:** browsers (Accept: text/html) get a rendered HTML page; `?format=json` forces JSON; API clients get JSON. Unknown kind → 400 |
| 8 | `/api/lottery/analyze` | GET | public | `?mains=1,2,3,4,5&stars=2,5` | full line profile + balance score; 400 on invalid line |
| 9 | `/api/lottery/combinations` | GET | public | `?limit&offset` | persisted generations newest-first |
| 10 | `/api/lottery/combinations/{id}` | GET | public | — | combination + its AI decisions |
| 11 | `/api/lottery/system` | GET/POST | public | `{mains:[pool], stars:[pool], page, limit}` | plan + page of lines (limit ≤ 500). Above the sync limit (10,000) → **HTTP 409** "system has N lines — above the synchronous limit; use POST api/lottery/system-build" (HTML page for browsers). 400 on invalid pools |
| 12 | `/api/lottery/system-build` | POST | manage + CSRF | `{mains, stars}` | inline build ≤10k lines; larger → idempotent background queue (note: "processed by the systems cron job") |
| 13 | `/api/lottery/generate` | POST | view + CSRF | `{mode, count, seed?, locks:{mains,stars}, excludes:{mains,stars}, contextLines?}` | AI report + `{saved:{combinationId, decisionId}}`. Empty dataset + history-backed mode → **400 DATA UNAVAILABLE**. Unknown mode → 400 |
| 14 | `/api/lottery/diversity` | POST | view + CSRF | `{lines:[{mains,stars}]}` | diversity report; 400 on invalid lines |
| 15 | `/api/lottery/tickets` | POST | view + CSRF | `{name, lines, generationMethod?, drawDate?, modelVersion?, configuration?}` | created ticket + lines; 400 with the first invalid-line reason |
| 16 | `/api/lottery/tickets` | GET | view | — | the caller's OWN tickets only |
| 17 | `/api/lottery/tickets/{id}` | GET | view | — | own ticket (any ticket with manage) |
| 18 | `/api/lottery/tickets/{id}/check` | POST | view + CSRF | — | match results + tiers; no stored draw → **409** "no stored draw to compare yet (sync the provider first)" |
| 19 | `/api/lottery/tickets/{id}/delete` | POST | view + CSRF | — | archive (soft delete) |
| 20 | `/api/lottery/backtest` | POST | view + CSRF | `{strategy, lines:1-10, window:0-100}` | HISTORICAL SIMULATION report, persisted; 400 unknown strategy / insufficient history (needs ≥11 draws) |
| 21 | `/api/lottery/backtest-compare` | POST | view + CSRF | `{strategies:[must include RANDOM_BASELINE], lines, window}` | same-period comparison; **400 if RANDOM_BASELINE missing** |
| 22 | `/api/lottery/backtests` | GET | public | `?limit=50` | persisted backtests (HTML page for browsers) |
| 23 | `/api/lottery/backtests/{id}` | GET | view | — | report detail |
| 24 | `/api/lottery/models` | GET | view | — | model versions (immutable registry) |
| 25 | `/api/lottery/performance` | GET | view | — | the 3 separated sections |
| 26 | `/api/lottery/intelligence` | GET | public | — | latest persisted report + live status |
| 27 | `/api/lottery/intelligence/run` | POST | manage + CSRF | `{lines:1-20, seed?}` | sync + analysis + persist |
| 28 | `/api/lottery/providers` | GET | public | — | provider registry |
| 29 | `/api/lottery/health` | GET | public | — | live health + history |
| 30 | `/api/lottery/jobs` | GET | public | `?jobType&limit` | job runs |
| 31 | `/api/lottery/sync` | POST | manage + CSRF | `{limit:1-1000}` | idempotent provider sync |

Public-allowlist routes (bypass session): 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 22, 26, 28,
29, 30. Everything else requires a session + RBAC (+ CSRF on POST).

**CLI tools:**
- `lottery-smoke [--raw]` — print provider health + 3 draws + jackpot as JSON. Exit 0 =
  live draws received, 1 = unreachable, 2 = unconfigured. `--raw` also prints the
  vendor's unmapped latest row (feed-shape diagnosis).
- `lottery-cron [job]` — run one job or all; print the JSON summary.

---

## SECTION G — UI SPECIFICATION

### G1. Dashboard page (`/lottery`, login + lottery.view required)

**Hydration pattern:** the server gathers status, 20 draws, 20 my-tickets, 10 recent
combinations, 10 backtests, the intelligence snapshot, `{id, name, canManage}` for the
current user, and an endpoint map — then embeds it all as JSON in the page:

```html
<script>window.__AI_LOTTERY_STATE__ = {…json…};</script>
<script src="/assets/js/lottery.js" defer></script>
```

An inline no-framework JS client renders:
- **Lottery Intelligence panel** — latest verified draw (rendered as ball chips), draws
  analyzed, last sync, data source + note, generated-at/model/mode/seed "(reproducible)",
  main-number analysis (most frequent / recent / longest absence), Lucky-Star analysis,
  ranked candidate table (rank, line, score, why-summary), best line with score
  breakdown + full explanation list, score meaning + weights + disclaimer + honestyNote,
  and (manage permission only) a **Run Lottery Intelligence** button that POSTs to
  `/api/lottery/intelligence/run` with the page's CSRF token and reloads.
- **Cards:** Next draw (jackpot + provenance line: "jackpot source: live feed response
  (observed …)" / "stored verified draw" / "no feed value — nothing displayed"; provider
  label + health message; actions: Generate 5 AI lines / My tickets / Strategy Lab),
  Last verified draw, Historical data sync (status badge OK/DEGRADED/STALE/FAILED/
  NEVER_SYNCED, verified draw count, last successful sync, last attempt, dataset span or
  "DATA UNAVAILABLE — no verified historical draws stored", sync message), Quick links.
- **Tabs:** Recent draws / Recent AI combinations / My tickets / Backtests.
- Status badges: green ONLINE, **red DATA UNAVAILABLE**, amber anything else.
- **Visual language:** main numbers = yellow ball chips (#ffd24a), Lucky Stars =
  light-blue star chips (#7dd3fc), 32px circles (24px small); meta text in monospace.
- A `noscript` fallback explains the JS client. The Generate button POSTs
  `{mode:'HISTORICAL', count:5}` and **alerts the DATA UNAVAILABLE error instead of
  silently falling back to random numbers**.

### G2. Public pages (no login; content-negotiated with the JSON endpoints)

- **/lottery/statistics** — kind tabs (frequency/gap/distribution, windows 1y/2y), hot
  and cold chip rows, per-number table (appearances, %, last seen, draws since, avg gap,
  max gap), distribution panels (odd/even, low/high ≤25, sum min/max/avg/median, spread,
  consecutive). Disclaimer at the top.
- **/lottery/system** — pool form (GET to the same endpoint; mains "comma-separated,
  ≥5 numbers 1–50", stars "≥2 numbers 1–12"), plan card (formula, line counts,
  background-build notice when >10k), paginated line table with ball/star chips.
- **/lottery/backtests** — table (id, strategy, model, draws, period, created) linking to
  the detail endpoint. Headline: "HISTORICAL SIMULATION only. Every comparison includes
  a mandatory same-period random baseline. No strategy is declared 'better'."

### G3. Optional Next.js layer

- Rewrite browser `/api/*` to the backend server-side (browser only ever uses relative
  URLs; it never learns the backend address).
- Server-side bridge with an honest EMPTY dashboard constant (mirrors endpoint 2's
  defaults) + a fetch helper: 8s abort timeout, no-store cache, null on ANY failure.
- `/api/lottery/dashboard` route: proxy; backend unreachable → the honest NO_DATA shape
  with **HTTP 200** (never an invented draw).
- `/api/lottery/statistics` route: proxy `statistics/hot-cold?window=0`; convert the
  backend's associative `{number:count}` map into ascending number arrays (hot, cold);
  backend down → empty arrays (no invented hot/cold numbers).
- Widgets: jackpot + 1-second countdown to nextEstimated + recent results; hot/cold
  chips; a client-side quick generator (uniform random within the rules — clearly a
  client toy, NOT the AI engine).

---

## SECTION H — ADMIN INTEGRATION

- **Provider admin page:** service "Lottery / EuroMillions", driver "loteriasapi". Form
  fields: Base URL (optional, defaults to the /api/v1 root; a pasted /v1 or marketing URL
  is rewritten automatically), API Key (secret — stored encrypted, never rendered back),
  Game code (optional, default euromillones), Timeout (optional).
- **Test button:** normalize base/game, call `/latest`, show precise operator messages:
  "Connected to LoteriasAPI (euromillones) — latest draw YYYY-MM-DD" / "An API key is
  required…" / "Invalid API key" / "Endpoint not found (HTTP 404) — base URL must be
  https://api.loteriasapi.com/api/v1" / "Rate limited — the plan request quota is
  exhausted" / "Could not reach LoteriasAPI (network/SSL/firewall)".
- **Sync Now button** (lottery providers only; admin permission + CSRF + confirm dialog):
  run a full-history sync; audit it; flash the syncNotice() line ("Sync complete: … First
  issue: …") — never a bare count. Non-lottery providers answer "Manual synchronization
  is only available for the lottery service."
- **Scheduler entry:** "Lottery sweep" — every 6 hours, default enabled.
- If the platform has AI agents, grant the lottery module exactly two tools:
  `lottery.getResults`, `lottery.generateCombinations`.
- If the platform has a trading kill switch: lottery is explicitly **never gated** by it.

---

## SECTION I — TESTS TO WRITE (acceptance = all of these pass)

Write tests with the provider transport STUBBED — no test ever hits the network.

**Rules:** count mismatch / out-of-range / duplicate errors with the exact wording.

**Ingestion (idempotency):** re-import identical draw → unchanged, no duplicate row;
same numbers in a different order → unchanged (NOT a conflict); conflicting numbers on a
VERIFIED draw → NOT overwritten + conflict audited; unverified row with new data →
corrected + audited; invalid draw (wrong count, out of range, bad date, missing source,
missing timestamp) → rejected + audited, never stored.

**Statistics:** frequency/gap math on a known fixture; hot/cold within a window;
distribution histograms; pair/triplet counts.

**Generator:** all 5 modes produce valid lines; same seed → identical lines; locks always
present; excludes never present; lock∩exclude rejected; insufficient pool rejected;
BALANCED targets respected; ANTI-POPULAR filters respected; DIVERSIFIED minimizes overlap.

**Diversification:** overlap math (shared mains/stars/pairs/triplets), duplicate
detection, penalty formula, score bounds.

**System builder:** C(N,5)×C(S,2) counts (e.g. 9 mains + 4 stars = 126×6 = 756);
pagination windows; lazy enumeration never materializes; >10k → requiresBackground;
pool validation errors.

**Tickets:** create with valid lines; one invalid line rejects the whole ticket with the
line number; check against the right draw (latest on or before the ticket date); tier
assignment correct (5+2 → TIER_1 … 0+2 → TIER_10); archive; user isolation (user B
cannot read user A's ticket).

**Backtesting:** no look-ahead (strategy for draw i only sees draws before i); tier
counts; comparison rejects a missing RANDOM_BASELINE; same period for all strategies;
insufficient history (<11 draws) rejected.

**Vendor adapter (the big suite):** base-URL rewriting (marketing host → API root, /v1 →
/api/v1, pasted docs URL → root, custom gateway untouched); live camelCase payload
mapped; legacy snake_case payload mapped; FLAT 7-number combination split (stars-last,
stars-first, and the UNSAFE cases left untouched); 200-with-success:false treated as
failure; HTTP 400 page-size retry at 5 + meta.limit adoption; 364-day window walking
newest-first + stop-at-empty-window; unfinished (PENDING) draws never offered as
history; cents → euros; formatted money parsing ("130.000.000,00 €" → "130000000.00");
API key redacted from every error message; end-to-end: vendor payload → validation →
stored VERIFIED row.

**Last verified draw:** newest VERIFIED only (never unverified); ascending canonical
presentation on every surface.

**Intelligence report:** ranked candidates from the verified dataset; reproducible with
the same seed; explanations present; 0 draws → INSUFFICIENT_DATA + NO candidates;
<50 draws → LIMITED_DATA warning; the API key never appears in the report/storage/UI.

**Test fixture payloads (copy these shapes):**
```json
// live camelCase
{"id":"clx8j2k9m0002abcd1234efgh","game":{"slug":"euromillones","name":"Euromillones"},
 "drawId":"2026029","drawDate":"2026-04-10","dayOfWeek":"Viernes","status":"COMPLETED",
 "combination":[7,12,29,33,45],"resultData":{"estrellas":[3,9]},
 "jackpot":"13000000000","jackpotFormatted":"130.000.000,00 €",
 "prizes":[{"category":1,"categoryName":"5 + 2 estrellas","winners":0,
            "prizeAmount":"13000000000","formattedPrize":"130.000.000,00 €"}]}
// flat variant: numeric id, no drawId/resultData, combination [7,12,29,33,45,3,9]
// envelope: {"success":true,"data":<draw>,"timestamp":"2026-04-10T22:00:00.000Z"}
// legacy snake_case: draw_date, draw_id "2026/029", numbers, stars, el_millon,
//   jackpot_next, meta{source:"SELAE", updated_at}
```

---

## SECTION J — BUILD ORDER (do it in this sequence)

1. Create the 14 tables (Section C).
2. Rules engine (D1).
3. Provider abstraction + the 4 providers (D2) — the LoteriasAPI adapter is the biggest
   single piece; port every rule in it exactly.
4. Validator (D3).
5. Statistics engine (D4).
6. Analyzer (D5) → Generator (D6) → Diversification (D7) → System builder (D8) →
   Backtester (D9).
7. Facade (D10) with all audit events.
8. Repository implementation (Section E) over your ORM.
9. Cron service (D11) + scheduler entry.
10. API layer — 31 endpoints (Section F) with the allowlist + CSRF model.
11. UI — dashboard + 3 public pages (Section G).
12. Admin integration (Section H).
13. Tests (Section I) — all of them, transport stubbed.
14. Verify every rule in Section B (the honesty contract) is enforced and tested.

**Definition of done:** all Section I tests pass; a configured API key ingests real
draws; an unconfigured/dead feed shows honest states with zero fabricated data; every
statistical surface carries the disclaimer; no API key is ever visible anywhere; the
Lottery Intelligence report produces ranked, reproducible, explained candidates from
verified data only.

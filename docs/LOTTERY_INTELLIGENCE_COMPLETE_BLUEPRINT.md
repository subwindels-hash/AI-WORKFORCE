# EUROPMILLIONS · LOTTERY INTELLIGENCE — COMPLETE STANDALONE BLUEPRINT

> **One document. Everything needed to rebuild the feature from scratch in any stack.**
> This is the complete reverse-engineered specification of the EuroMillions · Lottery
> Intelligence module built in the WINDELS / AI-WORKFORCE project: every layer, class,
> table, endpoint, algorithm, constant, security rule, cron job, test and governance
> contract — with the critical code embedded verbatim so nothing needs to be looked up.
>
> Original stack: PHP 8 / CodeIgniter 3 + SQLite/MySQL + vanilla JS + optional Next.js.
> The design is stack-neutral: pure domain classes over a repository interface.

---

# PART 0 — WHAT THE FEATURE IS

A full-stack lottery intelligence module for **EuroMillions** (5 main numbers 1–50 +
2 Lucky Stars 1–12, drawn Tuesdays & Fridays 21:00 UTC) that:

1. **Ingests real draw results** from loteriasapi.com (SELAE data) via a provider-neutral adapter.
2. **Validates and stores** every draw with source attribution, idempotency, conflict protection.
3. **Computes statistics** — frequency, gaps, hot/cold, distribution, pairs/triplets/star-pairs.
4. **Generates lines** in 5 modes with lock/exclude constraints and seeded reproducibility.
5. **Scores set diversification.**
6. **Builds full wheels** — every C(N,5) × C(S,2) line from a pool, lazily, paginated.
7. **Backtests strategies** with no look-ahead and a mandatory random baseline.
8. **Tracks user tickets**, auto-checks them against stored draws, assigns official prize tiers.
9. **Produces a persisted Lottery Intelligence report** — ranked candidate lines with explanations.
10. **Never fabricates data** — a hard honesty contract runs through every layer.

Sizing: 13 domain classes (~4,600 lines), 14 tables, ~30 endpoints, 8 cron jobs,
118 tests, ~700 lines of views, ~620-line optional Next.js layer.

## The 13 governance rules (the feature's identity — adopt verbatim)

1. **Nothing is fabricated.** Missing key / disabled provider / non-JSON / HTTP error / network failure → `UNCONFIGURED` / `DISABLED` / `OFFLINE` / `NO_DATA` / `DATA UNAVAILABLE` and **zero draws**.
2. **Honest state machine:** feed online → `ONLINE`; feed down but verified draws stored → `STORED DATA`; feed configured-but-unreachable, nothing stored → `DATA UNAVAILABLE`; never configured → `NO_DATA`.
3. **Every draw is validated** (counts, ranges, duplicates, real calendar date, source, timestamp) before storage; rejections are audited, never stored as official.
4. **Idempotent ingestion** — re-import is a no-op; a VERIFIED draw is never silently overwritten; conflicts are audited for manual correction.
5. **Order-insensitive canonicalization** — number groups stored and displayed as ascending ints; "46 27 12 19 11" IS "11 12 19 27 46".
6. **Source attribution everywhere** — every stored draw carries `source`, `sourceTimestamp`, `retrieved_at`; every report carries a dataset stamp (`n=<count>;last=<date>`).
7. **Scores are STATISTICAL BALANCE / DIVERSITY scores — never probabilities.** Win-chance wording is banned; every surface carries a `scoreMeaning`.
8. **No "due" numbers** — absence is reported as absence only ("absent for X draws"), never "likely to appear next".
9. **Backtests are HISTORICAL SIMULATION** — no look-ahead, same-period comparisons, random baseline mandatory, no strategy declared better, no fabricated cost/winnings.
10. **Actual ticket results, backtests and sandbox data are three separate sections — never mixed.**
11. **Credentials never leak** — encrypted at rest, redacted from every message/log/error, HTTPS-only upstreams.
12. **Every output carries the DISCLAIMER** (exact text below) and an honestyNote stating all combinations have identical odds.
13. **Model versions are immutable** — historical results stay connected to the model that produced them. History-backed generation **refuses to run on an empty dataset** (`DATA UNAVAILABLE`) instead of degrading to random.

**The DISCLAIMER (exact string, on every statistical output):**

```
Lottery draws are independent random events. Historical frequency, gaps and patterns
are statistical observations only — they do not change the probability of future draws
and are not forecasts of future results.
```

**The honesty note (on every generated report):**

```
Every valid EuroMillions combination has exactly the same mathematical chance of being
drawn. A number that appeared frequently, or has not appeared recently, is NOT more
likely to appear next — these are suggestions shaped by historical statistics only,
not predictions, and no suggestion can guarantee or predict the winning numbers.
```

---

# PART 1 — ARCHITECTURE

Strict layering, dependency-injected; all domain logic in pure classes (no framework
calls inside engines — fully unit-testable, portable to any language):

```
UI:      hydrated dashboard (JSON state + inline JS) · public pages · Next.js widgets
API:     ~30 JSON endpoints · RBAC (lottery.view / lottery.manage) · CSRF on mutations
FACADE:  LotteryIntelligence — wires provider→validator→repo→engines; audit events
ENGINES: Rules · Provider(+4) · Validator · Statistics · Analyzer · Generator
         · Diversification · SystemBuilder · Backtester · CronService
PERSIST: LotteryRepository interface + AuditRepository interface (any ORM)
DB:      14 tables (schema in Part 2)
```

Composition root (provider precedence — first configured wins):

```php
$loterias         = new LoteriasApiProvider();      // real data (loteriasapi.com)
$officialLottery  = new OfficialLotteryProvider();  // generic authorized feed
$lotteryProvider  = $loterias->configured()
    ? $loterias
    : ($officialLottery->configured()
        ? $officialLottery
        : (getenv('WINDELS_LOTTERY_SANDBOX') === '1'
            ? new SandboxLotteryProvider()          // labeled simulation
            : new UnavailableLotteryProvider()));   // honest "nothing configured"

$lottery = new LotteryIntelligence($lotteryRepo, $auditRepo, $lotteryProvider);
// The whole module hangs off this one service object.
```

---

# PART 2 — DATABASE SCHEMA (complete MySQL DDL)

Three dialect mirrors ship in the original (mysql / pgsql / sqlite — same columns).
Canonical MySQL:

```sql
CREATE TABLE IF NOT EXISTS lotteries (
 id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1, rules_version VARCHAR(16) NOT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_rules (
 id INT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, version VARCHAR(16) NOT NULL,
 main_count INT NOT NULL, main_min INT NOT NULL, main_max INT NOT NULL,
 star_count INT NOT NULL, star_min INT NOT NULL, star_max INT NOT NULL,
 schedule VARCHAR(255) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_lottery_rules (lottery_code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_data_sources (
 id INT AUTO_INCREMENT PRIMARY KEY, provider_code VARCHAR(64) NOT NULL UNIQUE,
 display_name VARCHAR(120) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
 synthetic TINYINT(1) NOT NULL DEFAULT 0,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_provider_health (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, provider_id INT NOT NULL, status VARCHAR(32) NOT NULL,
 response_ms INT NULL, records_received INT NOT NULL DEFAULT 0, invalid_records INT NOT NULL DEFAULT 0,
 error_rate DECIMAL(8,5) NULL, last_success_at VARCHAR(32) NULL, last_failure_at VARCHAR(32) NULL,
 last_draw_retrieved VARCHAR(32) NULL, data_freshness_seconds INT NULL, synthetic TINYINT(1) NOT NULL DEFAULT 0,
 observed_at VARCHAR(32) NOT NULL, KEY idx_lottery_provider_health (provider_id, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_draws (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, provider_id INT NULL,
 external_id VARCHAR(64) NOT NULL, draw_date DATE NOT NULL, jackpot VARCHAR(32) NULL,
 rollover TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(120) NOT NULL, source_timestamp VARCHAR(40) NOT NULL,
 retrieved_at VARCHAR(32) NOT NULL, verification_status VARCHAR(32) NOT NULL, payload MEDIUMTEXT NOT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
 UNIQUE KEY uq_lottery_draws (lottery_code, external_id), KEY idx_lottery_draws_date (lottery_code, draw_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_draw_numbers (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, draw_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL,
 position INT NOT NULL, number INT NOT NULL, KEY idx_lottery_draw_numbers_draw (draw_id, kind, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_sync_runs (
 id VARCHAR(64) PRIMARY KEY, provider_id INT NULL, job_type VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL,
 started_at VARCHAR(32) NOT NULL, ended_at VARCHAR(32) NULL,
 records_processed INT NOT NULL DEFAULT 0, records_created INT NOT NULL DEFAULT 0, records_updated INT NOT NULL DEFAULT 0,
 errors TEXT NULL, payload MEDIUMTEXT NULL, execution_key VARCHAR(128) NOT NULL UNIQUE,
 KEY idx_lottery_sync_runs_job (job_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_combinations (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, `mode` VARCHAR(32) NOT NULL,
 model_version VARCHAR(64) NOT NULL, seed VARCHAR(32) NULL, line_count INT NOT NULL DEFAULT 0,
 `lines` MEDIUMTEXT NOT NULL, `constraints` MEDIUMTEXT NOT NULL, score_summary MEDIUMTEXT NOT NULL,
 created_by INT NULL, created_at VARCHAR(32) NOT NULL, KEY idx_lottery_combinations_code (lottery_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_ai_decisions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, combination_id BIGINT NULL,
 model_version VARCHAR(64) NOT NULL, mode VARCHAR(32) NULL, decision MEDIUMTEXT NOT NULL,
 created_at VARCHAR(32) NOT NULL, KEY idx_lottery_ai_decisions_comb (combination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_tickets (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, lottery_code VARCHAR(32) NOT NULL,
 name VARCHAR(120) NOT NULL, draw_date DATE NULL, generation_method VARCHAR(32) NOT NULL,
 model_version VARCHAR(64) NOT NULL, configuration MEDIUMTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'OPEN', result MEDIUMTEXT NULL,
 created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_tickets_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_ticket_lines (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT NOT NULL, position INT NOT NULL,
 mains TEXT NOT NULL, stars TEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_ticket_lines_ticket (ticket_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_backtests (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, lottery_code VARCHAR(32) NOT NULL, strategy VARCHAR(40) NOT NULL,
 model_version VARCHAR(64) NOT NULL, lines_per_draw INT NOT NULL DEFAULT 1, draws_tested INT NOT NULL DEFAULT 0,
 period_from DATE NULL, period_to DATE NULL, dataset_version VARCHAR(128) NULL,
 report MEDIUMTEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
 KEY idx_lottery_backtests_strategy (strategy, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lottery_model_versions (
 id INT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(64) NOT NULL, model_version VARCHAR(16) NOT NULL,
 config MEDIUMTEXT NOT NULL, dataset_version VARCHAR(128) NULL, status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
 created_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_lottery_model_versions (model_name, model_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Table purposes:
- `lotteries` — registry (`code='EUROMILLIONS'`)
- `lottery_rules` — admin-updatable rule versions (schedule = JSON `{"days":[2,5],"time":"21:00","timezone":"UTC"}`)
- `lottery_data_sources` — provider registry (synthetic flag)
- `lottery_provider_health` — append-only sync history per provider
- `lottery_draws` — THE historical store. `payload` JSON: `{main, stars, jackpot, rollover, winners, prizes, totalWinners, drawDate, extra:{elMillon, status, dayOfWeek, prizeTiers, numberLayout, rawCombination, feedSource, jackpotNext}}`
- `lottery_draw_numbers` — normalized rows (`kind` = main|star)
- `lottery_sync_runs` — idempotent job runs; `execution_key` UNIQUE is the whole idempotency mechanism
- `lottery_combinations` — persisted generations/systems (`mode` ∈ RANDOM|BALANCED|HISTORICAL|DIVERSIFIED|ANTI-POPULAR|INTELLIGENCE|SYSTEM)
- `lottery_ai_decisions` — full decision report JSON per generation
- `lottery_tickets` + `lottery_ticket_lines` — user tickets (status OPEN|CHECKED|ARCHIVED)
- `lottery_backtests` — persisted backtest reports
- `lottery_model_versions` — immutable model registry

**Idempotency key formats:**
```
sync:EUROMILLIONS:YYYY-MM-DD
backtest:EUROMILLIONS:<STRATEGY>:YYYY-MM-DD
intelligence:EUROMILLIONS:YYYY-MM-DD:<newest-draw-date>
system:EUROMILLIONS:md5(implode(',',mainPool) . '|' . implode(',',starPool))
```

---

# PART 3 — DOMAIN LAYER (every class)

## 3.1 Rules engine

Rules are **data, not code** — every engine reads counts/ranges from a rules object.

```php
interface LotteryRules {
    public function code(): string;      // 'EUROMILLIONS'
    public function name(): string;      // 'EuroMillions'
    public function version(): string;   // '1.0'
    public function mainCount(): int;    // 5
    public function mainMin(): int;      // 1
    public function mainMax(): int;      // 50
    public function starCount(): int;    // 2
    public function starMin(): int;      // 1
    public function starMax(): int;      // 12
    /** days 0=Sun..6=Sat (UTC) */
    public function drawSchedule(): array;   // ['days'=>[2,5],'time'=>'21:00','timezone'=>'UTC']
    /** @return array{valid:bool,errors:list<string>} */
    public function validateLine(array $main, array $stars): array;
    public static function fromArray(string $code, string $name, array $row): self;
}
```

`validateLine` error strings (exact):
- `line must contain 5 main numbers (got 7)` (count mismatch)
- `main number out of range 1-50: 99`
- `duplicate main numbers`
- same for `Lucky Star` with 2 / 1–12.

EuroMillions defaults: `main 5×(1..50)`, `stars 2×(1..12)`, schedule Tue+Fri.
The facade prefers a **stored active rules row** and falls back to the code default,
so rule changes need no deploy.

## 3.2 Provider abstraction

```php
interface LotteryProvider {
    public function id(): string;
    public function name(): string;   // user-facing label
    /** @return array{state:string,licensed:bool,synthetic:bool,message:string} */
    public function health(): array;  // state: ONLINE|OFFLINE|UNCONFIGURED|DISABLED
    /** Normalized draws (order NOT guaranteed — consumers sort by drawDate):
     *  each: externalId:string, drawDate:'YYYY-MM-DD', main:int[], stars:int[],
     *  jackpot:?string, rollover:bool, winners:?string, source:string,
     *  sourceTimestamp:ISO-8601  (+optional prizes[], totalWinners, extra{})
     */
    public function draws(?string $from = null, ?string $to = null, int $limit = 100): array;
    /** @return array{source,value,currency,observedAt,note}|null */
    public function jackpotInfo(): ?array;
}
```

**Four implementations:**

1. **`UnavailableLotteryProvider`** — safe default. health `UNCONFIGURED`, message
   "No lottery data provider configured…", zero draws. Nothing fabricated.
2. **`SandboxLotteryProvider`** — deterministic simulation for pipeline testing.
   Online only when `WINDELS_LOTTERY_SANDBOX=1`. `source='sandbox-simulation'`,
   `synthetic=true`, externalId `SIM-YYYY-MM-DD`. Walks backwards day-by-day emitting
   only on schedule days; seeded PRNG; jackpot €1M–20M; rollover when `rand()>0.8`.
   Health message: "Simulated draws for pipeline testing only — NOT official results".
3. **`OfficialLotteryProvider`** — generic authorized feed speaking the neutral contract:
   `GET {url}/draws?from=&to=&limit=` → `{data:{draws:[{externalId,drawDate,main,stars,sourceTimestamp}]}}`,
   `GET {healthUrl}` → `{ok:true,version}`. Offline until explicit ENABLED + HTTPS URL +
   license + source metadata all present (fail-closed). Bearer-token auth.
4. **`LoteriasApiProvider`** — the real-data adapter (below).

## 3.3 LoteriasApiProvider — the real-data adapter (full spec)

**Vendor contract (loteriasapi.com, SELAE data):**

| Item | Value |
|---|---|
| Base URL | `https://api.loteriasapi.com/api/v1` — **`/api` prefix required** (the `/v1` the marketing pages advertise 404s on every route) |
| Auth | header `X-API-Key: <key>` |
| Latest | `GET /results/{game}/latest` → `{success,data:{draw},timestamp}` |
| Range | `GET /results/{game}/range?from=&to=&page=&limit=` → `{success,data:[…],meta:{hasNext,limit}}` — **max 365 days per call** |
| By date | `GET /results/{game}/date/{yyyy-mm-dd}` |
| Listing | `GET /results/{game}?page=&limit=&sort=drawDate&order=desc` |
| Game code | `euromillones` (`euromillions` normalized to it) |
| Errors | a **200** carrying `{success:false,error:{code,message,statusCode}}` is a FAILURE, never a draw |

**Config resolution order:** constructor args → admin "API Management" row (only when
`driver === 'loteriasapi'`) → env vars:

```
WINDELS_LOTTERY_LOTERIASAPI_KEY        required (presence alone also enables)
WINDELS_LOTTERY_LOTERIASAPI_ENABLED=1
WINDELS_LOTTERY_LOTERIASAPI_BASE_URL   default https://api.loteriasapi.com/api/v1
WINDELS_LOTTERY_LOTERIASAPI_GAME       default euromillones
WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT    default 8s (cap 60)
WINDELS_LOTTERY_LOTERIASAPI_SOURCE     default 'windels.ai' (display label)
```

**Constants:**
```php
DEFAULT_BASE_URL = 'https://api.loteriasapi.com/api/v1'
DEFAULT_GAME     = 'euromillones'
MAX_RANGE_DAYS   = 364     // vendor caps /range at 365 days
MAX_PAGES        = 20      // paging guard per window
PAGE_SIZE        = 100     // requested; plan may cap lower
MIN_PAGE_SIZE    = 5       // documented Free-plan floor — 400-retry size
LATEST_TTL_SECONDS = 60    // memoize /latest so status+jackpot = 1 request
UNFINISHED_STATUSES = ['PENDING','SCHEDULED','PROGRAMADO','PROCESSING','IN_PROGRESS','CANCELLED','CANCELED']
GAME_FORMATS = ['euromillones' => ['main'=>[5,1,50], 'stars'=>[2,1,12]]]
FULL_HISTORY ceiling: 5000 draws (~2,300 exist since 2004)
```

**Base-URL self-healing (`normalizeBaseUrl`):** marketing host `loteriasapi.com` → API root;
`/v1` → `/api/v1`; a pasted full docs URL reduces to its versioned root; any other host
(custom gateway/proxy) stays exactly as configured. Plain `http://` → provider stays
unconfigured (HTTPS only, no userinfo in URL).

**Payload mapping (`normalizeDraw`):**

| Vendor | → Neutral |
|---|---|
| `drawId` / `draw_id` / `id` | `externalId` |
| `drawDate` / `draw_date` / `date` / `fecha` | `drawDate` |
| `combination` / `numbers` / `combinacion` / `winningCombination` | `main` |
| `resultData.estrellas` / `stars` / `luckyStars` / `extraNumbers` / `winningExtraNumbers`… | `stars` |
| `jackpotFormatted` `"130.000.000,00 €"` (authoritative) else `jackpot` `"13000000000"` = **integer cents** → /100 | `jackpot` `"130000000.00"` |
| `prizes[top tier].winners == 0` | `rollover=true`, `winners="0"` |
| `updatedAt` / envelope `timestamp` / fallback `drawDate + 'T21:00:00+00:00'` | `sourceTimestamp` |
| `status`, `dayOfWeek`, El Millón code, prize count | `extra.*` |
| full `prizes[]` (category, label, winners, amount) | `prizes` + `totalWinners` |

**Top-tier detection:** category `"1"`, `"1a"`, `"1ª"`, or match `"5+2"`, or categoryName
containing `5+2` (whitespace-stripped).

**Money parsing:** formatted string `"130.000.000,00 €"` → strip non-digits/./, → last
separator with ≤2 trailing digits = decimals → `"130000000.00"`. Integer without
separators = cents. A rolled-over top-tier prize (≤0) stays null — never a zero jackpot.

**The flat-combination split (`splitLine`)** — the live feed sometimes publishes the
whole line as ONE array: `combination:[7,12,29,33,45,3,9]`. Re-group **only** when
provably safe:

| Situation | Split? |
|---|---|
| Row also has `resultData.estrellas` and the flat list **ends** with exactly those values | yes → `extra.numberLayout='flat-combination-split'` |
| …and the flat list **begins** with exactly those values (stars first) | yes → `'flat-combination-split-stars-first'` |
| No star field, list exactly `mainCount+starCount` long, first 5 unique & in 1–50, last 2 unique & in 1–12 | yes (the only possible 5+2 layout) |
| Anything else (unknown game, out-of-range, duplicates, wrong length) | **NO** — left untouched → rejected downstream + audited (never reshaped into a valid-looking line) |

Every re-grouped draw keeps the vendor's own list in `extra.rawCombination`.

**`draws()` — windowed, quota-aware backfill:**
1. Clamp limit 1..5000. No explicit window → `weeks = ceil(limit/2)+2`, capped at 1300
   (~25 years — covers the 2004 launch without wasting calls on pre-2004 emptiness);
   `from = to - weeks`.
2. Split into ≤364-day windows; walk **newest-first**.
3. Per window: page through `/range` following `meta.hasNext` (max 20 pages).
4. De-duplicate raw rows by `id|date` across overlapping windows.
5. **Implicit backfill stops at the first window adding nothing new** (a 7-day-history
   Free key costs ~2 requests, not one per year of unreadable archive). Explicit caller
   windows are answered in full.
6. Empty range → try the listing endpoint → then `/latest` **only if that draw is
   finished AND playable** (status finished + both number groups present — on draw day
   `/latest` answers the NEXT draw as a PENDING placeholder with no numbers).
7. Normalize → skip unfinished statuses → enforce explicit windows → de-duplicate by
   `externalId|drawDate` → sort newest-first → slice to limit.

**Plan-capped page sizes:** ask `limit=100`; on HTTP 400 naming the limit, retry once at
5; then adopt `meta.limit` (what the plan actually served) for all later pages.

**`drawById()`** — accepts `YYYY-MM-DD` (uses `/date/` route) or a vendor id
(`2026029`, `2026/029` → bracketed ±30-day `/range` query, exact drawId match; nothing
inferred from the id).

**HTTP layer** — injectable transport callable (default: shared platform HTTP helper or
`file_get_contents` + stream context with SSL peer verification, `ignore_errors`,
timeout). Status → message mapping:
- 401/403 → `authentication rejected (HTTP n)`
- 429 → `rate limited — plan request quota exhausted`
- 404 → `base URL must be https://api.loteriasapi.com/api/v1`
- 400 → vendor's own message + field quoted (which parameter failed)
- 0 → `no HTTP response (network/SSL/firewall)`; non-JSON 200 → `invalid JSON payload`
- 200 `success:false` → vendor code/message; `UNAUTHORIZED`/`FORBIDDEN` = auth failure
- **`safeMessage()` redacts the API key** (string replace + `x-api-key:` regex, 180-char
  cap) — the key never appears in any error, log, view or audit.

**Branding:** the adapter class is the vendor integration, but `name()` returns the
product label (e.g. "Windels API — EuroMillions"); stored `source` attribution keeps
the real feed identity. Admin UI and CLI keep the vendor name (operators need it).

## 3.4 LotteryResultValidator — ingestion gatekeeper

Every draw must pass ALL checks before storage:
1. non-empty `externalId`
2. `drawDate` matches `YYYY-MM-DD` AND is a real calendar date (`checkdate`)
3. main/stars coerced to ints (non-numeric → `-1`, always out of range) then rules
   validation (counts, ranges, duplicates)
4. non-empty `source` — "no draw may be stored without a source"
5. non-empty `sourceTimestamp`

Returns `{valid, status: VALID|DATA_VALIDATION_FAILED, errors[]}`. Failures are never
stored — audited as `LOTTERY_DRAW_VALIDATION_FAILED`.

## 3.5 LotteryStatisticsEngine — pure functions

Input everywhere: `[{drawDate:'YYYY-MM-DD', main:int[], stars:int[]}]` **ASC by date**.

- **`numberStats(draws, min, max, window)` / `starStats(...)`** — per number:
  `appearances`, `appearancePct`, `lastAppearance` (date), `drawsSinceLast` (= `currentGap`),
  `avgGap`/`minGap`/`maxGap` (gaps between consecutive appearances),
  `recentAppearances`/`recentPct` (last-N window), `trend` (recent share − overall share,
  labeled an observation).
- **`hotCold(draws, field, min, max, window=50, top=5)`** — top/bottom 5 by count in the
  window + the note "Historical frequency within the last N draw(s) only. This does NOT
  predict future draws…".
- **`distribution(draws, min, max, mainCount)`** — odd/even + low/high histograms (low ≤
  midpoint = 25) with %, sum (min/max/avg/median), spread (min/max/avg), consecutive
  (draws containing runs, %, longest run, run-length distribution).
- **`groupStats(draws, field, min, max, k, topN=20)`** — k-subset co-occurrence
  (k=2 pairs, 3 triplets; k capped at 5): exhaustive combinations per draw, key
  `"a-b-c"` sorted; per group: count, lastSeen, avgGap, maxGap; sorted count desc.

## 3.6 CombinationAnalyzer — per-line profile + balance score

For one line (validated, sorted) vs the stored draws:

- **Composition:** odd/even, low/high (low ≤ 25), sum, spread, adjacent pairs,
  longest run, runs of 3+
- **Pattern traits:** all-same-last-digit, within-single-decade, birthday count (1–31),
  multiples of 5
- **Historical context:** sum/spread percentiles, most-common odd & low counts
  (`bestMode`: mode of count distribution, ties → nearest ideal → smaller), best
  historical overlap, draws sharing ≥3 numbers, same-odd/even count, draws with |Δsum| ≤ 10
- **numberProfile / starProfile** — per-number stats for each selected number

**STATISTICAL BALANCE SCORE (0–100), exact formulas:**

```
WEIGHTS = ['sum'=>0.30, 'oddEven'=>0.20, 'lowHigh'=>0.20, 'spread'=>0.15, 'consecutives'=>0.15]

sumFit          = histStd > 0
                  ? max(0, 100 * (1 - |sum - histAvg| / (2 * histStd)))
                  : (sum == histAvg ? 100 : 50)
oddEvenFit      = max(0, 100 - 20 * |odd - bestOdd|)
lowHighFit      = max(0, 100 - 20 * |low - bestLow|)
spreadFit       = spreadAvg > 0
                  ? max(0, 100 * (1 - |spread - spreadAvg| / spreadAvg))
                  : 100
consecutivesFit = max(0, 100 - 25 * adjacentPairs)

score = round(Σ weight_i * fit_i)        // 0 draws → neutral 50
```

Labels: `scoreLabel = 'STATISTICAL BALANCE SCORE: N/100'`; `scoreMeaning` = "How closely
this line matches typical historical draw composition (sum, odd/even, low/high, spread,
consecutive patterns). It is NOT a probability and it does not indicate how likely the
line is to be drawn."

## 3.7 CombinationGenerator — the 5-mode generator

`MODES = ['RANDOM','BALANCED','HISTORICAL','DIVERSIFIED','ANTI-POPULAR']`, `MAX_LINES = 100`.

**Determinism — xorshift32 PRNG (port exactly):**

```php
function seededRandom(int $seed): Closure {
    $s = $seed !== 0 ? ($seed & 0xFFFFFFFF) : 1;
    return function () use (&$s): float {
        $s ^= ($s << 13) & 0xFFFFFFFF; $s &= 0xFFFFFFFF;
        $s ^= $s >> 17;
        $s ^= ($s << 5) & 0xFFFFFFFF;  $s &= 0xFFFFFFFF;
        return $s / 4294967296.0;   // uniform [0,1)
    };
}
```

Seed: caller-supplied else `(int)(microtime(true)*1e6) % 2147483647` (0 → 1). Same seed +
same inputs → identical lines, forever (a documented `reproducible` promise).

**Locks/excludes (validated up front):** locked numbers always present (≤ pick count),
excluded never present, lock ∩ exclude = error, remaining pool must be sufficient.

**Mode mechanics (rejection sampling, per-line attempt caps `kMax`):**

| Mode | kMax | Mechanism |
|---|---|---|
| RANDOM | 1 | Uniform sample without replacement (seeded Fisher–Yates prefix) |
| BALANCED | 60 | Targets from history: sum within ±1σ of average; odd & low counts within ±1 of most common historical split; ≤1 adjacent pair. Reject until fit. |
| HISTORICAL | 1 | Weighted sample without replacement, **weight = 1 + appearances** |
| DIVERSIFIED | 24 | Generate candidates, pick lowest **overlap penalty** vs context lines (10 per shared main, 20 per shared star); context = provided lines + lines already generated this call |
| ANTI-POPULAR | 80 | Reject: >2 numbers in 1–31, ascending run ≥3, >1 adjacent pair, all-same last digit, single-decade confinement |

If nothing passes after kMax, use the best-so-far pool (generation never fails silently).
Every emitted line is re-validated against the rules and profiled (score + composition).

**Report shape (persisted as the AI decision):**

```json
{
  "model": "WINDELS Lottery Model v1.0", "lottery": "EUROMILLIONS", "mode": "BALANCED",
  "lineCount": 5,
  "lines": [{"mains":[...], "stars":[...], "score":87,
             "scoreLabel":"STATISTICAL BALANCE SCORE: 87/100",
             "profile":{"sum":..,"spread":..,"oddEven":"3 odd / 2 even",
                        "lowHigh":"2 low / 3 high","adjacentPairs":0}}],
  "averageBalanceScore": 84.3,
  "inputs": {"seed":123,"rulesVersion":"1.0","drawsUsed":60,"lastDrawDate":"2026-09-04",
             "datasetVersion":"n=60;last=2026-09-04",
             "locks":{"mains":[],"stars":[]},"excludes":{"mains":[],"stars":[]},
             "contextLines":0},
  "factors": {"method":"…", "targets":{…}, "historicalBasis":{…}, "note":"…"},
  "generatedAt":"…", "disclaimer":"…", "honestyNote":"…"
}
```

`factors` records the **actual** method/targets/weights used — never invented after the
fact. Mode-specific factor notes always state the limits (e.g. ANTI-POPULAR: "Avoiding
popular patterns may reduce the chance of sharing a prize if a line happens to win. It
never changes the chance of winning.").

## 3.8 DiversificationEngine — set-diversity score

All-pairs comparison of validated, sorted lines (O(n²), bounded by line size):

```
sharedMain = |mainA ∩ mainB|          sharedStar = |starA ∩ starB|
sharedPairs    = C(sharedMain, 2)     sharedTriplets = C(sharedMain, 3)
sameOE / sameLH = identical odd/even (or low/high) split counts
avgAbsSumDiff  = mean |sumA - sumB|          duplicates = identical lines
pairReuse      = (total pair instances − unique pairs) / total pair instances

DIVERSITY SCORE (0–100):
penalty = 45*(avgMain/mainCount) + 15*(avgStar/starCount) + 10*(sameOEPct/100)
        + 10*(sameLHPct/100) + 5*(1 - min(1, avgAbsSumDiff/30)) + 30*(identicalPairs/pairCount)
score   = clamp(100 - penalty, 0, 100)
```

Report includes overlaps (avg+max), distributionSimilarity, pairReuse, `scoreLabel
'DIVERSITY SCORE: N/100'` and the meaning note.

## 3.9 SystemBuilder — the wheel builder

Constants: `SYNC_LINE_LIMIT = 10000` (bigger → background), `MAX_BACKGROUND_LINES =
200000`, `MAX_PAGE = 500`.

- **`plan(mainPool, starPool)`** — normalize pools (unique in-range ints, sorted; ≥5
  mains, ≥2 stars); `totalLines = C(N,5) × C(S,2)` computed **combinatorially**
  (multiplicative binomial, never hardcoded); returns formula string
  `"C(9,5) x C(4,2) = 126 x 6"`, `estimatedCost: null` + `costNote: "Official line
  pricing is not available in this environment — no cost is fabricated."`, 100% pool
  coverage note ("coverage of the pool is not a statement about winning"),
  `requiresBackground` flag.
- **`lines()`** — generator: lexicographic k-subset iteration (index array, advance last
  possible index, reset the tail), mains outer / stars nested — **constant memory**.
- **`page(mains, stars, offset, limit)`** — windowed slice for interactive use.
- **`allLines()`** — materialized, only for bounded builds.

Binomial (exact integer):
```
C(n,k): k = min(k, n-k); c = 1; for i in 1..k: c = c*(n-k+i)/i
```

## 3.10 LotteryBacktester — Strategy Lab

`STRATEGIES = ['RANDOM_BASELINE','BALANCED_PROFILE','HISTORICAL_FREQ','ANTI_POPULAR']`,
`MIN_HISTORY = 10`, `MAX_WINDOW = 100`, `MAX_LINES = 10`.

**No look-ahead:** for test draw *i* the strategy sees only `draws[0..i-1]`.
**Deterministic seed per draw:**
```
seed = crc32(strategy) ^ (drawIndex * 2654435761) ^ crc32('WINDELS-Lottery-Model-v' + version)
```
Each strategy maps to the same-named generator mode (RANDOM_BASELINE → RANDOM, etc.).

Report (labeled `HISTORICAL SIMULATION`): period (from/to/drawsTested/minHistoryDraws),
linesPerDraw, totalLines, matchDistribution (mains 0–5, stars 0–2 histograms), tierCounts,
bestLine, `simulatedCost: null` + costNote, `simulatedWinnings: null` + winningsNote
(**no cost or winnings ever fabricated** — only tier counts), perDraw detail.

**`compare()`** — all strategies on the SAME period; **throws if RANDOM_BASELINE is
missing** ("the random baseline must be part of every comparison"); no strategy declared
"better".

**Prize tier table (official EuroMillions, stable structure — labels only, amounts never stored):**

```
5 main + 2 stars → TIER_1        5+1 → TIER_2        5+0 → TIER_3
4+2 → TIER_4     4+1 → TIER_5    3+2 → TIER_6        3+1 → TIER_7
2+2 → TIER_8     1+2 → TIER_9    0+2 → TIER_10       else null
```

## 3.11 LotteryIntelligence — the facade

Constants:
```
MODEL_VERSION = '1.0'        LOTTERY = 'EUROMILLIONS'
FULL_HISTORY_LIMIT = 5000    DRAWS_PER_YEAR = 104     (window parsing: '1y'/'2y'/'6m')
MIN_RELIABLE_DRAWS = 50      INTELLIGENCE_MODE = 'INTELLIGENCE'
HISTORY_BACKED_MODES = ['BALANCED','HISTORICAL','ANTI-POPULAR','DIVERSIFIED']
MAX_TICKET_LINES = 50        TICKET_METHODS = [MANUAL,RANDOM,BALANCED,HISTORICAL,DIVERSIFIED,ANTI-POPULAR]
Ticket statuses: OPEN | CHECKED | ARCHIVED
```

Constructor wires: statistics engine, rules (stored→default), provider, analyzer,
generator, diversification, system builder, backtester; ensures the lottery registry row.

**Canonicalization:** `normalizeGroup` (ints, ascending) on storage AND every read
(`presentDraw` exposes `numbers.main/stars`, `main_numbers`, `lucky_stars`, `draw_no`).
Idempotency is order-insensitive (`sameNumbers` compares sorted groups).

**`status()`** — the single dashboard payload: provider health, rules, engine state,
drawsTracked, lastDraw, disclaimer + widget aliases: `status` (ONLINE / STORED DATA /
DATA UNAVAILABLE / NO_DATA), `jackpot`, `jackpotSource` `{origin:
PROVIDER_FEED|STORED_DRAW, provider, observedAt, currency, hardcoded:false}` (proves the
amount is feed data), `verifiedDraws`, `dataAvailable`, `historicalDataset`,
`lastSuccessfulSync`, `lastSyncAttempt`, `syncStatus` (OK/DEGRADED/STALE/FAILED/
NEVER_SYNCED), `syncMessage`, `nextEstimated` (next Tue/Fri hint, next 8 days).

**`sync(limit)`** — health gate: not ONLINE + offline-ish state → record OFFLINE health
row + audit `LOTTERY_SYNC_FAILED` + return `DATA UNAVAILABLE` (unconfigured →
`NO_PROVIDER`). Else: `draws()` → `importDraws()` → health row (ONLINE/DEGRADED,
response_ms, records received/invalid, last_draw_retrieved) → audit
`LOTTERY_SYNC_COMPLETED` → summary + dataset stamp. DB write failures surface as failed
syncs, never clean ones.

**`importDraws(raw)`** — per draw: validate → normalize ascending → find by externalId →
- identical numbers (order-insensitive) → `unchanged`
- exists & VERIFIED & different → `conflicts` + audit `LOTTERY_RESULT_CONFLICT`
  ("NOT overwritten; manual correction required")
- exists & unverified & different → corrected + audit `LOTTERY_DRAW_CORRECTED`
- new → stored `VERIFIED` (marked strictly AFTER validation passes) + audit
  `LOTTERY_DRAW_IMPORTED`

**`historicalDataset()`** — THE single accessor for stored VERIFIED draws
(`drawsForStats`) read by statistics, analyzer, generator, Strategy Lab, backtests.
`datasetInfo()` → `{source:'VERIFIED_HISTORICAL_DATABASE', draws:n, from, to, available,
datasetVersion:'n=60;last=2026-09-04'}`.

**`generate()`** — history-backed modes on an empty dataset throw
`DATA UNAVAILABLE — <MODE> generation requires the verified historical dataset, which is
empty…` (RANDOM stays as the explicit baseline). Delegates, then stamps dataset
provenance + `usedForGeneration` / `randomBaseline` flags.

**`saveGeneration()` / `saveSystem()`** — persist combinations + AI decisions + audits
(`LOTTERY_COMBINATION_GENERATED`, `LOTTERY_SYSTEM_BUILT`).

**`intelligenceReport(lines, seed)`** — the flagship report (verified data only):
- dataState: `INSUFFICIENT_DATA` (0 draws → **no candidates at all**), `LIMITED_DATA`
  (<50 → provisional warning), `RELIABLE`
- main/star field stats (most/least frequent, recent-hot, longest absence — each with the
  "does NOT make any number more likely" note), recurring pairs/triplets/star-pairs,
  full distribution
- candidates: BALANCED generation (seeded, `reproducibleNote`), ranked by score, each
  with `scoreBreakdown`, `composition`, and a plain-English **explanation** list, e.g.:
  - "Sum 132 — the historical sum average is 129 (typical range 107–151)."
  - "3 odd / 2 even — matches the most common historical split (3 odd / 2 even)."
  - "Spread 41 (historical average 35)."
  - "One adjacent pair."
  - "Includes 2 of the most frequent main numbers (7, 19)."
  - "Includes 1 main number(s) with the longest current absence (32) — absence is a
    historical observation, not a reason to expect them."
- provenance block (provider id/name only — "the API key is never exposed",
  `verifiedOnly: true`, "Nothing is hard-coded or fabricated.")
- scoreMeaning, scoreWeights, disclaimer, honestyNote

**`runIntelligence()`** — sync first (full backfill on empty DB, else 100-draw delta) →
report → persist (INTELLIGENCE combination + AI decision) → audit
`LOTTERY_INTELLIGENCE_RUN`. `intelligenceSnapshot()` = latest persisted report + live
status (served by `GET /api/lottery/intelligence`).

**Tickets:**
- `createTicket(userId, name, lines, method, drawDate?, modelVersion?, configuration)` —
  validates EVERY line (one bad line rejects the whole ticket), ≤50 lines, method
  whitelist, `YYYY-MM-DD` date check, lines sorted on save; audited
  `LOTTERY_TICKET_CREATED`
- `checkTicket(id, userId?)` — compares against the **latest VERIFIED draw on or before**
  the ticket's date (latest overall when no date); per-line mainMatches/starMatches/
  prizeTier; marks CHECKED; audited `LOTTERY_TICKET_CHECKED`; NO_DRAW → actionable note
- `archiveTicket` — soft delete, user-scoped, audited
- user isolation: users only see their own tickets unless they hold `lottery.manage`

**Model versioning:** `ensureModelVersion()` upserts an immutable row recording score
weights, generator modes, backtester strategies/limits. Never deleted or replaced.

**`performance()`** — three sections, never mixed: ACTUAL TICKET RESULTS (tier histogram),
HISTORICAL BACKTEST RESULTS (recent), DEMO/SANDBOX DATA (synthetic flag) + separation note.

## 3.12 LotteryCronService — 8 jobs

`JOBS = ['sync','health','statistics','systems','tickets','backtests','intelligence','cleanup']`
— run via CLI (`tools lottery-cron [job]`) and a platform scheduler entry
(**"Lottery sweep", every 6 hours / 21600s, default enabled**). `runAll()` isolates
failures; each failure audited `LOTTERY_JOB_FAILED`.

| Job | Behavior |
|---|---|
| `sync` | key `sync:EUROMILLIONS:YYYY-MM-DD` — once/day. Full backfill (5000) on empty DB else 100-draw delta. |
| `health` | live state; **DEGRADED when last success >8 days old** (draws twice weekly). |
| `statistics` | integrity sweep — re-validates EVERY stored draw; violations audited `LOTTERY_INTEGRITY_VIOLATIONS` (fix path = manual correction, never silent rewriting). |
| `systems` | processes queued `system` job runs (execution-key idempotent); saves SYSTEM combinations; rejects >200k lines. |
| `tickets` | auto-checks OPEN tickets whose draw date ≤ latest verified draw (CHECKED never re-checked). |
| `backtests` | one backtest per strategy per day (`backtest:…:<strategy>:<date>` key); random baseline every day. |
| `intelligence` | regenerates the report only when a NEW verified draw landed (`UP_TO_DATE` otherwise); key includes the newest draw date. |
| `cleanup` | job runs >90 days, health rows >30 days deleted. |

---

# PART 4 — PERSISTENCE INTERFACES

Implement these over any ORM; the domain layer never touches the framework.

```php
interface LotteryRepository {
    // registry
    ensureLottery(string $code, string $name, string $rulesVersion): array;
    listLotteries(): array;
    // rules
    activeRules(string $lotteryCode): ?array;        // null → code default applies
    saveRules(array $r): int;
    // providers + health
    ensureProvider(string $code, string $name): array;
    listProviders(bool $enabledOnly = false): array;
    saveHealth(int $providerId, array $health): void;   // always INSERT (history)
    latestHealth(int $providerId): ?array;
    listHealth(int $providerId, int $limit = 20): array;
    // draws
    findDraw(int $id): ?array;
    findDrawByExternal(string $lotteryCode, string $externalId): ?array;
    listDraws(array $filter = [], int $limit = 100, string $order = 'DESC'): array;
    saveDraw(array $d): array;                          // {row, created}
    listDrawNumbers(int $drawId): array;
    saveDrawNumbers(int $drawId, array $numbers): void;
    drawsForStats(string $lotteryCode, int $limit = 10000): array; // [{drawDate,main[],stars[]}] ASC decoded
    countDraws(string $lotteryCode): int;
    // job runs (idempotency)
    startJobRun(array $run): ?array;                    // null when execution_key exists
    finishJobRun(string $id, array $result): void;
    listJobRuns(?string $jobType = null, int $limit = 50): array;
    findJobRunByKey(string $key): ?array;
    deleteOldJobRuns(string $cutoff): void;
    deleteOldHealth(string $cutoff): void;
    // combinations + decisions
    saveCombination(array $c): array;
    findCombination(int $id): ?array;
    listCombinations(int $limit = 50, int $offset = 0): array;
    saveAiDecision(array $d): array;
    findAiDecision(int $id): ?array;
    listAiDecisions(?int $combinationId = null, int $limit = 50): array;
    // tickets (user isolation: $userId given = scoped; null = system/admin)
    saveTicket(array $t): array;
    findTicket(int $id, ?int $userId = null): ?array;
    listTickets(int $userId, int $limit = 50): array;
    listAllTickets(int $limit = 200): array;
    updateTicket(int $id, array $patch): void;
    ticketLines(int $ticketId): array;
    saveTicketLines(int $ticketId, array $lines): void;
    // model versions (immutable, upsert by name+version)
    ensureModelVersion(array $m): array;
    listModelVersions(): array;
    // backtests
    saveBacktest(array $b): array;
    listBacktests(int $limit = 50): array;
    findBacktest(int $id): ?array;
}

interface AuditRepository {
    emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void;
    recent(int $limit = 100): array;
}
```

**Complete audit-event vocabulary:**
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

# PART 5 — COMPLETE API REFERENCE

Auth model: a **route allowlist** in the JSON base controller decides public routes (any
non-listed route without a session → 401 JSON, or login redirect for HTML GETs with
`return_to`). Mutations require RBAC + a timing-safe CSRF check (`hash_equals` of the
`X-CSRF-Token` header vs the session token). Permissions refresh from the DB on every
call. `jsonBody()` reads `php://input` and falls back to form POST.

**RBAC:** `lottery.view` (view draws/statistics/tickets/performance), `lottery.manage`
(providers, sync, configuration). Roles: `lottery_admin` → both, `lottery_viewer` → view,
`platform_member` includes view.

| # | Route | Method | Auth | Request | Response / notes |
|---|---|---|---|---|---|
| 1 | `/api/lottery/status` | GET | public | — | Full status payload (see 3.11) |
| 2 | `/api/lottery/dashboard` | GET | public | — | Widget shape: status, jackpot + `jackpotFormatted` (€130.0M / €850K), recent 3 draws, myTicketsCount, sync block; hard honest default on failure (`NO_DATA` + "DATA UNAVAILABLE — the lottery module could not be read.") |
| 3 | `/api/lottery/lotteries` | GET | public | — | `[{code,name,enabled}]` |
| 4 | `/api/lottery/rules` | GET | public | — | counts/ranges/schedule |
| 5 | `/api/lottery/draws` | GET | public | `?limit=50&from&to` | draws newest-first, canonical ascending groups |
| 6 | `/api/lottery/draws/{id}` | GET | public | — | draw detail + numbers |
| 7 | `/api/lottery/statistics/{kind}` | GET | public | `?kind&window` (0=all, N draws, `1y`,`2y`,`6m`; kinds: frequency, gap, hot-cold, distribution, stars, pairs, triplets, star-pairs) | stats + `dataset` stamp; **HTML/JSON negotiated** (browser Accept → rendered page) |
| 8 | `/api/lottery/analyze` | GET | public | `?mains=1,2,3,4,5&stars=2,5` | full line profile + balance score |
| 9 | `/api/lottery/combinations` | GET | public | `?limit&offset` | persisted generations newest-first |
| 10 | `/api/lottery/combinations/{id}` | GET | public | — | combination + its AI decisions |
| 11 | `/api/lottery/system` | GET/POST | public | `{mains:[pool], stars:[pool], page, limit}` | plan + page of lines; above sync limit → **409** "use system-build" (HTML page for browsers) |
| 12 | `/api/lottery/system-build` | POST | lottery.manage + CSRF | `{mains, stars}` | inline build ≤10k lines, else idempotent background queue |
| 13 | `/api/lottery/generate` | POST | lottery.view + CSRF | `{mode, count, seed?, locks:{mains,stars}, excludes:{mains,stars}, contextLines?}` | AI report + decision report + `{saved:{combinationId,decisionId}}`; empty dataset + history mode → **400 DATA UNAVAILABLE** |
| 14 | `/api/lottery/diversity` | POST | lottery.view + CSRF | `{lines:[{mains,stars}]}` | diversity report |
| 15 | `/api/lottery/tickets` | POST | lottery.view + CSRF | `{name, lines, generationMethod?, drawDate?, modelVersion?, configuration?}` | created ticket + lines |
| 16 | `/api/lottery/tickets` | GET | lottery.view | — | caller's own tickets only |
| 17 | `/api/lottery/tickets/{id}` | GET | lottery.view | — | own ticket (or any, with lottery.manage) |
| 18 | `/api/lottery/tickets/{id}/check` | POST | lottery.view + CSRF | — | match results; no stored draw → **409** actionable message |
| 19 | `/api/lottery/tickets/{id}/delete` | POST | lottery.view + CSRF | — | archive (soft delete) |
| 20 | `/api/lottery/backtest` | POST | lottery.view + CSRF | `{strategy, lines:1-10, window:0-100}` | HISTORICAL SIMULATION report, persisted |
| 21 | `/api/lottery/backtest-compare` | POST | lottery.view + CSRF | `{strategies:[…must include RANDOM_BASELINE…], lines, window}` | same-period comparison; missing baseline → **400** |
| 22 | `/api/lottery/backtests` | GET | public | `?limit=50` | persisted backtests (HTML page for browsers) |
| 23 | `/api/lottery/backtests/{id}` | GET | lottery.view | — | report detail |
| 24 | `/api/lottery/models` | GET | lottery.view | — | model versions (immutable registry) |
| 25 | `/api/lottery/performance` | GET | lottery.view | — | 3 separated sections |
| 26 | `/api/lottery/intelligence` | GET | public | — | latest persisted report + live status |
| 27 | `/api/lottery/intelligence/run` | POST | lottery.manage + CSRF | `{lines:1-20, seed?}` | sync + analysis + persist |
| 28 | `/api/lottery/providers` | GET | public | — | provider registry |
| 29 | `/api/lottery/health` | GET | public | — | live health + history |
| 30 | `/api/lottery/jobs` | GET | public | `?jobType&limit` | job runs |
| 31 | `/api/lottery/sync` | POST | lottery.manage + CSRF | `{limit:1-1000}` | idempotent provider sync |

**HTML routes (content-negotiated on 7, 11, 22):** `/lottery/statistics`, `/lottery/system`,
`/lottery/backtests` render public pages; `?format=json` forces JSON.
**Dashboard:** `/lottery` (RBAC-gated). **CLI:** `tools lottery-smoke [--raw]`
(exit 0 live / 1 unreachable / 2 unconfigured; `--raw` prints the vendor's unmapped row
for feed-shape diagnosis) and `tools lottery-cron [job]`.

---

# PART 6 — UI BLUEPRINT

## 6.1 Dashboard page (`/lottery`)

**Hydration pattern:** the controller gathers status, 20 draws, 20 my-tickets, 10 recent
combinations, 10 backtests, the intelligence snapshot, `me {id, name, canManage}` and the
endpoint map; JSON-encodes it into the page:

```html
<script>window.__AI_LOTTERY_STATE__ = <?= $stateJson /* JSON_UNESCAPED_SLASHES|UNICODE */ ?>;</script>
<script src="/assets/js/lottery.js" defer></script>
```

RBAC first (manage/admin → canManage; view/viewer → canView; else flash + redirect).
Every sub-read wrapped in try/catch with an honest fallback (`NO_DATA` + default rules).

Inline JS (no framework) renders:
- **Lottery Intelligence panel** — latest verified draw (balls), draws analyzed, last
  sync, data source + note, generated-at/model/mode/seed "(reproducible)", main/star field
  analysis, ranked candidate table (rank, line, score, why-summary), best line with score
  breakdown + full explanation list, score meaning/weights/disclaimer/honestyNote,
  admin-only **Run Lottery Intelligence** button (POST with CSRF token from the page's
  hidden input, then reload)
- **Cards:** Next draw (jackpot + `jackpot source: live feed response (observed …)` /
  `stored verified draw` / `no feed value — nothing displayed`, provider + message,
  actions: Generate 5 AI lines / My tickets / Strategy Lab), Last verified draw,
  Historical data sync (badge OK/DEGRADED/STALE/FAILED/NEVER_SYNCED, verified count,
  last success/attempt, dataset span or "DATA UNAVAILABLE — no verified historical draws
  stored", message), Quick links
- **Tabs:** Recent draws / Recent AI combinations / My tickets / Backtests
- Status badges: green ONLINE, **red DATA UNAVAILABLE**, amber otherwise
- **Visual language:** yellow balls `#ffd24a` (mains), light-blue star chips `#7dd3fc`
  (Lucky Stars), 32px circles, 24px small variants; `.lottery-meta` in the monospace group
- `noscript` fallback; `assets/js/lottery.js` progressive enhancement — the Generate
  button POSTs `{mode:'HISTORICAL', count:5}` and **alerts the DATA UNAVAILABLE error
  instead of silently falling back to random**

## 6.2 Public pages

- **statistics** — kind tabs (frequency/gap/distribution, windows 1y/2y), hot/cold chips,
  per-number table (appearances, %, last seen, draws since, avg/max gap), distribution
  panels (odd/even, low/high ≤25, sum min/max/avg/median, spread, consecutive)
- **system** — pool form (GET, same endpoint), plan card (formula, line count,
  background notice), paginated line table with balls/stars
- **backtests** — persisted list (id, strategy, model, draws, period, created) linking to
  detail; headline "HISTORICAL SIMULATION only. Every comparison includes a mandatory
  same-period random baseline. No strategy is declared 'better'."

## 6.3 Next.js layer (optional)

- `next.config.ts` rewrites browser `/api/*` → PHP backend (`LEAD_API_INTERNAL_URL`,
  default `http://127.0.0.1:3001`); browser only ever uses relative URLs
- `lib/lottery.ts` — server-side bridge: `EMPTY_LOTTERY_DASHBOARD` (honest empty shape) +
  `fetchLotteryJson()` (8s AbortController timeout, `cache:'no-store'`, null on any
  failure; target `LOTTERY_API_INTERNAL_URL ?? LEAD_API_INTERNAL_URL ?? http://127.0.0.1:8080`)
- `app/api/lottery/dashboard/route.ts` (`force-dynamic`) — proxy; unreachable backend →
  honest NO_DATA with **status 200** (never an invented draw)
- `app/api/lottery/statistics/route.ts` — proxies `statistics/hot-cold?window=0`,
  converts the associative `{number:count}` map to ascending `number[]` (hot, cold);
  backend down → empty arrays
- Dashboard page fetches both on mount; "🎰 Lottery" QuickStatCard (`{imported} draws`)
- Components: `LotteryWidget` (jackpot, countdown, recent results), `EuroMillionsWidget`
  (1-second countdown timer, hot/cold chips, client-side quick generator — uniform random
  within the rules, clearly a client toy), `types.ts` mirrors (`LiDraw`, `LiDashboard`,
  `EuroMillionsData`)

---

# PART 7 — ADMIN & PLATFORM INTEGRATION

- **API Management:** service `lottery` (label "Lottery / EuroMillions", kind data,
  drivers `loteriasapi | official_lottery | custom_http`). Driver form fields: Base URL,
  API Key (x-api-key, secret), Game code, Timeout. **Test** button → normalize base/game,
  call `/latest`, return precise operator messages (Connected — latest draw YYYY-MM-DD /
  invalid key / 404 = wrong base URL / 429 = plan quota / network). Keys encrypted at
  rest, never rendered back.
- **Admin Sync Now** (`admin.api.manage` + CSRF + confirm dialog; lottery rows only):
  runs `sync(FULL_HISTORY_LIMIT)`; audits `API_PROVIDER_SYNCED`; flash uses `syncNotice()`
  → "Sync complete: N imported, N unchanged, N rejected; N verified draws stored. First
  issue: <reason>" — never a bare count. Other services answer "Manual synchronization is
  only available for the lottery service."
- **Cron scheduler:** "Lottery sweep" — every 6h, default enabled, group "Lottery Intelligence".
- **Agent-tool registry:** the lottery module grants `lottery.getResults` +
  `lottery.generateCombinations` to platform agents.
- **Surfaces to wire:** sidebar nav (custom SVG ticket icon), workspace home lottery
  widget (jackpot card, verified count, tickets, recent draws — same status/list calls,
  same honest defaults), command-center quick action, marketing pages, chat assistants
  (topic answer + keyword router: lottery, euromillions, lucky star, ticket, wheel,
  backtest…), feature inventory entries, deployment doctor class check, deployment SQL.
- **Kill-switch scope:** lottery is explicitly listed as **never gated** by the trading
  kill switch (no order surface, no money movement).
- **Environment variables (all documented in .env.example):**
  `WINDELS_LOTTERY_LOTERIASAPI_{KEY,ENABLED,BASE_URL,GAME,TIMEOUT,SOURCE}`,
  `WINDELS_LOTTERY_OFFICIAL_{URL,HEALTH_URL,TOKEN,LICENSE,SOURCE,ENABLED,JACKPOT_URL}`,
  `WINDELS_LOTTERY_SANDBOX`, `LOTTERY_API_INTERNAL_URL` (Next.js).

---

# PART 8 — TESTING

118 tests across 12 files, run through a zero-dependency micro framework
(`test()`, `assert_true/false/equals/not_equals/close/throws`) executing the real stack.
Discovery: glob sorted; `AI_WORKFORCE_TEST_FILTER` env/arg filters by filename substring.
**The transport callable is stubbed — no test ever hits the network.**

| Suite | # | Invariants locked |
|---|---|---|
| rules-validation | 3 | counts/ranges/duplicates + exact error wording |
| import-idempotency | 7 | re-import no-op, order-insensitivity, conflict protection, VERIFIED never overwritten |
| statistics | 6 | frequency/gap/hot-cold/distribution/group math |
| governance-e2e | 3 | audit events, honest states end-to-end |
| generator | 10 | 5 modes, determinism (same seed → same lines), locks/excludes |
| diversification | 6 | overlap math, duplicates, penalty formula |
| system-builder | 6 | combinatorial counts, pagination, lazy enumeration, limits |
| tickets | 5 | create/validate/check/archive, user scoping |
| backtesting | 8 | no look-ahead, tier assignment, mandatory baseline, same-period compare |
| loteriasapi-provider | 44 | full vendor contract: base-URL rewriting, live+legacy+flat payloads, envelope errors, page-size retry, window walking, money parsing, key redaction, end-to-end ingestion |
| last-verified-draw | 10 | canonical ascending presentation, newest-verified accessor |
| intelligence-report | 10 | ranked candidates, reproducibility, explanations, insufficient-data honesty, key never in report |
| (scaffolds suite) | +1 group | OfficialLotteryProvider HTTPS/license fail-closed, source attribution |

Fixture shapes to copy (they encode the vendor contract):
- **live camelCase:** `{id, game:{slug}, drawId:"2026029", drawDate, status:"COMPLETED",
  combination:[7,12,29,33,45], resultData:{estrellas:[3,9]}, jackpot:"13000000000" (cents),
  jackpotFormatted:"130.000.000,00 €", prizes:[{category:1, categoryName:"5 + 2 estrellas",
  winners:0, prizeAmount, formattedPrize}]}`
- **flat variant:** same row, `id` numeric, no `drawId`/`resultData`,
  `combination:[7,12,29,33,45,3,9]` — the 7-number line
- **envelope:** `{success:true, data:<draw>, timestamp:"2026-04-10T22:00:00.000Z"}`
- **legacy snake_case:** `{draw_date, draw_id:"2026/029", numbers, stars, el_millon,
  jackpot_next, meta:{source:'SELAE', updated_at}}`

---

# PART 9 — REBUILD ORDER (checklist)

1. **Schema** — create the 14 tables (Part 2). Semantically critical unique keys: draws
   `(lottery_code, external_id)`, sync runs `execution_key`.
2. **Rules engine** (3.1) — keep rules as data.
3. **Providers** (3.2–3.3) — the 4-provider pattern; port the LoteriasAPI adapter details
   exactly (X-API-Key, `/api/v1` root, envelope unwrapping, flat-combination split table,
   364-day windows newest-first, page-size retry at 5 + `meta.limit` adoption,
   unfinished-draw skipping, cents→euros, memoized `/latest`, key redaction, HTTPS-only).
4. **Validator** (3.4) — the 5-check gate.
5. **Statistics engine** (3.5) — pure functions over ASC `[{drawDate, main, stars}]`.
6. **Analyzer → Generator → Diversification → SystemBuilder → Backtester** (3.6–3.10) —
   port the formulas exactly; keep the xorshift32 PRNG (or record whatever seeded PRNG
   you use in every report).
7. **Facade** (3.11) — with the full audit-event vocabulary (Part 4).
8. **Repository implementations** (Part 4) over your ORM.
9. **Cron** (3.12) — 8 jobs, execution-key idempotency, 6-hourly sweep.
10. **API** — 31 endpoints (Part 5), route allowlist + timing-safe CSRF, content
    negotiation for the three public pages.
11. **UI** — hydrated dashboard + public pages (Part 6.1–6.2).
12. **Next.js layer** if applicable (6.3).
13. **Admin + platform integration** (Part 7) — driver form, Test button, Sync Now,
    scheduler entry, agent tools, nav/widget/chat surfaces, kill-switch exemption.
14. **Tests** — port all 118 invariants (Part 8); stub the transport.
15. **Adopt the honesty contract verbatim** (Part 0) — it is the feature's identity.

---

*Source of truth: the WINDELS / AI-WORKFORCE repository — library
`application/libraries/AIWorkforce/Lottery/` (13 classes), controllers `Lottery.php` /
`Api_lottery.php`, views `application/views/lottery/`, schemas
`application/database/lottery.*.sql`, tests `tests/cases/53–61, 109–111`, vendor doc
`docs/LOTTERY_EUROMILLIONS_API.md`, spec `README.md` §Lottery Intelligence.*

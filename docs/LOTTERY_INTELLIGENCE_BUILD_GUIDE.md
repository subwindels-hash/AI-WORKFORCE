# EuroMillions · Lottery Intelligence — Complete Build Guide

This document explains **every detail** of how the *EuroMillions · Lottery Intelligence* feature was built inside this repository (WINDELS / AI-WORKFORCE), so it can be rebuilt from scratch in another project. It is a reverse-engineered blueprint of the actual code: every layer, every class, every endpoint, every table, every algorithm, every constant, and the governance rules that shaped the design.

---

## 1. What the feature is

A full-stack lottery intelligence module built around **EuroMillions** (5 main numbers from 1–50 + 2 Lucky Stars from 1–12, drawn Tuesdays and Fridays). It:

1. **Ingests real draw results** from an external API ([loteriasapi.com](https://loteriasapi.com), data sourced from SELAE), through a provider-neutral adapter layer.
2. **Validates and stores** every draw in a historical database with source attribution, idempotency and conflict protection.
3. **Computes statistics** — per-number frequency, gaps, hot/cold, distribution (odd/even, low/high, sum, spread, consecutive), pairs/triplets/star-pairs.
4. **Generates combination lines** in 5 modes (RANDOM, BALANCED, HISTORICAL, DIVERSIFIED, ANTI-POPULAR) with lock/exclude constraints, seeded reproducibility, and a "statistical balance score".
5. **Scores set diversification** (how different a set of lines is from itself).
6. **Builds full systems (wheels)**: every C(N,5) × C(S,2) line from a chosen pool, lazily enumerated and paginated.
7. **Backtests strategies** ("Strategy Lab") over stored draws without look-ahead, with a **mandatory random baseline** in every comparison.
8. **Manages saved tickets** per user, auto-checks them against stored draws and assigns official prize tiers.
9. **Produces a persisted "Lottery Intelligence report"** — ranked candidate lines with factor explanations, refreshed by cron or admin action.
10. **Never fabricates data.** A hard "honesty contract" runs through every layer: no predictions, no invented numbers, no silent fallbacks, disclaimers on every output, API keys never leaked.

**Built with:** PHP 8 (CodeIgniter 3 framework) for the backend, vanilla JS + server-hydrated JSON for the main dashboard, plus an optional Next.js/React widget layer. Zero external PHP dependencies (no Composer libraries) — the HTTP client is `file_get_contents` with a stream context, the PRNG is a hand-rolled xorshift, the test framework is ~100 lines of PHP.

---

## 2. Architecture overview

Strict layering, dependency-injected, all domain logic in pure classes (no framework calls inside the engines — they are fully unit-testable):

```
┌────────────────────────────────────────────────────────────────────┐
│ UI LAYER                                                           │
│  views/lottery/index.php   (hydrated dashboard, inline JS client)  │
│  views/lottery/{statistics,system,backtests}.php (public pages)    │
│  assets/js/lottery.js      (progressive enhancement)               │
│  apps/web (Next.js)        (proxy routes + React widgets)          │
└───────────────┬────────────────────────────────────────────────────┘
                │ fetch / JSON
┌───────────────▼────────────────────────────────────────────────────┐
│ API LAYER — application/controllers/Api_lottery.php                │
│   ~30 JSON endpoints. RBAC (lottery.view / lottery.manage), CSRF   │
│   on mutations, content negotiation (HTML vs JSON on some routes)  │
└───────────────┬────────────────────────────────────────────────────┘
                │
┌───────────────▼────────────────────────────────────────────────────┐
│ FACADE — LotteryIntelligence (application/libraries/AIWorkforce/   │
│  Lottery/LotteryIntelligence.php)                                  │
│  wires: provider → validator → repository → engines; audit events; │
│  status/sync/stats/generate/tickets/backtests/intelligence         │
├────────────────────────────────────────────────────────────────────┤
│ ENGINES (pure classes)                                             │
│  LotteryRules / EuroMillionsRules      rule engine (data, not code)│
│  LotteryProvider (+4 implementations)  provider abstraction        │
│  LoteriasApiProvider                   vendor adapter              │
│  OfficialLotteryProvider               generic authorized feed     │
│  SandboxLotteryProvider                labeled simulation          │
│  UnavailableLotteryProvider            honest "nothing configured" │
│  LotteryResultValidator                ingestion gatekeeper        │
│  LotteryStatisticsEngine               frequency/gap/distribution  │
│  CombinationAnalyzer                   per-line profile + score    │
│  CombinationGenerator                  5-mode line generator       │
│  DiversificationEngine                 set-overlap scoring         │
│  SystemBuilder                         C(N,5)×C(S,2) wheel builder │
│  LotteryBacktester                     no-look-ahead strategy replay│
│  LotteryCronService                    8 scheduled jobs            │
├────────────────────────────────────────────────────────────────────┤
│ PERSISTENCE — LotteryRepository interface (Persistence/            │
│  Repositories.php) + anonymous-class CI3 implementation in         │
│  models/AIWorkforce_model.php; AuditRepository for audit events    │
├────────────────────────────────────────────────────────────────────┤
│ DATABASE — 14 tables (application/database/lottery.{mysql,pgsql,   │
│  sqlite}.sql) + SchemaInstaller migrations                         │
└────────────────────────────────────────────────────────────────────┘
```

**Key wiring (Platform.php, the composition root):**

```php
// Provider precedence — first configured wins:
$loterias = new \AIWorkforce\Lottery\LoteriasApiProvider();
$officialLottery = new OfficialLotteryProvider();
$lotteryProvider = $loterias->configured()
    ? $loterias
    : ($officialLottery->configured()
        ? $officialLottery
        : (getenv('WINDELS_LOTTERY_SANDBOX') === '1'
            ? new \AIWorkforce\Lottery\SandboxLotteryProvider()
            : new \AIWorkforce\Lottery\UnavailableLotteryProvider()));

$this->lottery = new \AIWorkforce\Lottery\LotteryIntelligence(
    $model->lottery,   // LotteryRepository
    $model->audit,     // AuditRepository
    $lotteryProvider   // LotteryProvider
);
```

So the whole module hangs off one service object: `$this->platform->lottery`.

---

## 3. Database schema (14 tables)

Mirrored in three dialects: `application/database/lottery.mysql.sql`, `lottery.pgsql.sql`, `lottery.sqlite.sql`. Tables are also created/migrated by `SchemaInstaller.php` (which lists them and applies `ALTER TABLE` patches, e.g. adding `payload` to `lottery_sync_runs`).

| Table | Purpose | Key columns |
|---|---|---|
| `lotteries` | Registry of lotteries | `code` UNIQUE (e.g. `EUROMILLIONS`), `name`, `enabled`, `rules_version` |
| `lottery_rules` | Admin-updatable rule versions | `lottery_code`, `version`, `main_count/min/max`, `star_count/min/max`, `schedule` (JSON), `active`; UNIQUE(code,version) |
| `lottery_data_sources` | Provider registry | `provider_code` UNIQUE, `display_name`, `enabled`, `synthetic` |
| `lottery_provider_health` | Sync/health history | `provider_id`, `status`, `response_ms`, `records_received`, `invalid_records`, `last_success_at`, `last_failure_at`, `last_draw_retrieved`, `synthetic`, `observed_at` |
| `lottery_draws` | **The historical draw store** | `lottery_code`, `provider_id`, `external_id`, `draw_date`, `jackpot`, `rollover`, `source` (NOT NULL), `source_timestamp` (NOT NULL), `retrieved_at`, `verification_status`, `payload` (JSON: main/stars/prizes/extra); UNIQUE(lottery_code, external_id) |
| `lottery_draw_numbers` | Normalized per-number rows | `draw_id`, `kind` (main/star), `position`, `number` |
| `lottery_sync_runs` | Idempotent job runs | `id` (UUID, PK), `job_type`, `status`, `started_at`, `ended_at`, `records_*`, `errors`, `payload`, `execution_key` UNIQUE |
| `lottery_combinations` | Persisted generations/systems | `mode` (RANDOM/BALANCED/.../INTELLIGENCE/SYSTEM), `model_version`, `seed`, `line_count`, `lines` (JSON), `constraints` (JSON), `score_summary` (JSON), `created_by` |
| `lottery_ai_decisions` | Full decision report per generation | `combination_id`, `model_version`, `mode`, `decision` (JSON — the entire report) |
| `lottery_tickets` | User tickets | `user_id`, `name`, `draw_date`, `generation_method`, `model_version`, `configuration`, `status` (OPEN/CHECKED/ARCHIVED), `result` (JSON) |
| `lottery_ticket_lines` | Ticket lines | `ticket_id`, `position`, `mains` (JSON), `stars` (JSON) |
| `lottery_backtests` | Persisted backtest reports | `strategy`, `model_version`, `lines_per_draw`, `draws_tested`, `period_from/to`, `dataset_version`, `report` (JSON) |
| `lottery_model_versions` | Immutable model registry | `model_name`, `model_version`, `config` (JSON: score weights, modes, strategies), `status`; UNIQUE(name,version) |

Indexes: `(lottery_code, draw_date)`, `verification_status`, `(draw_id, kind, position)`, `(job_type, started_at)`, `(lottery_code, created_at)`, `(combination_id)`, `(user_id, status)`, `(ticket_id, position)`, `(strategy, created_at)`, `(provider_id, observed_at)`.

**Idempotency keys** on `lottery_sync_runs.execution_key` are the backbone of "safe to re-run": `sync:EUROMILLIONS:YYYY-MM-DD`, `backtest:EUROMILLIONS:<strategy>:<date>`, `intelligence:EUROMILLIONS:<date>:<draw_date>`, `system:EUROMILLIONS:md5(mainPool|starPool)`.

---

## 4. Domain layer — class by class

All classes live in `application/libraries/AIWorkforce/Lottery/`, namespace `AIWorkforce\Lottery`, autoloaded via `application/libraries/AIWorkforce/autoload.php`.

### 4.1 `LotteryRules` interface + `EuroMillionsRules`

Rules are **data, not code assumptions** — every engine reads counts/ranges from a rules object, so a new lottery is a config change:

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
    /** @return array{days:int[],time:string,timezone:string} — days 0=Sun..6=Sat */
    public function drawSchedule(): array;   // ['days'=>[2,5],'time'=>'21:00','timezone'=>'UTC']
    public function validateLine(array $main, array $stars): array;  // {valid, errors[]}
    public static function fromArray(string $code, string $name, array $row): self;
}
```

`EuroMillionsRules::validateField()` produces precise errors:
- `line must contain 5 main numbers (got 7)` (count mismatch)
- `main number out of range 1-50: 99`
- `duplicate main numbers`
(same for "Lucky Star" with 2 / 1–12.)

`fromArray()` rebuilds rules from a stored `lottery_rules` row (schedule stored as JSON). The facade prefers **stored** rules (`repo->activeRules()`) and falls back to the code default — so admins can change rules without a deploy.

### 4.2 `LotteryProvider` interface (+ Unavailable & Sandbox)

The provider-neutral contract every source implements:

```php
interface LotteryProvider {
    public function id(): string;    // 'loteriasapi' | 'official-euromillions' | 'sandbox-sim' | 'unconfigured'
    public function name(): string;  // user-facing display name
    /** @return array{state:string,licensed:bool,synthetic:bool,message:string} */
    public function health(): array; // state: ONLINE|OFFLINE|UNCONFIGURED|DISABLED
    /** Normalized draws (order NOT guaranteed; consumers sort by drawDate):
     *  externalId, drawDate 'YYYY-MM-DD', main:int[], stars:int[], jackpot:?string,
     *  rollover:bool, winners:?string, source:string, sourceTimestamp:ISO-8601 */
    public function draws(?string $from = null, ?string $to = null, int $limit = 100): array;
    public function jackpotInfo(): ?array;  // {source, value, currency, observedAt, note} or null
}
```

**`UnavailableLotteryProvider`** — the safe default: health `UNCONFIGURED` with the message "No lottery data provider configured…", zero draws. *Nothing fabricated.*

**`SandboxLotteryProvider`** — deterministic simulated draws for pipeline testing:
- Only online when `WINDELS_LOTTERY_SANDBOX=1`
- `source: 'sandbox-simulation'`, `synthetic: true`, externalId `SIM-YYYY-MM-DD`
- Walks backwards day-by-day, only emits on schedule days (Tue/Fri), uses `MathUtils::seededRandom(42)`, jackpot randomized €1M–20M, rollover when `rand() > 0.8`
- Never presented as official; health message: "Simulated draws for pipeline testing only — NOT official results"

### 4.3 `LoteriasApiProvider` — the real-data adapter (1,051 lines)

The most detailed class. **Vendor contract** (loteriasapi.com):

- Base URL: `https://api.loteriasapi.com/api/v1` — **the `/api` prefix is required**; the marketing pages advertise `/v1` which 404s on every route
- Auth: header `X-API-Key: <key>`
- Endpoints:
  - `GET /results/{game}/latest` → `{success, data:{draw}, timestamp}`
  - `GET /results/{game}/range?from=&to=&page=&limit=` → `{success, data:[...], meta:{hasNext, limit}}` (max 365 days per call)
  - `GET /results/{game}/date/{yyyy-mm-dd}` → draw by date
  - `GET /results/{game}?page=&limit=&sort=&order=` → paged history listing
- Game code: `euromillones` (English `euromillions` normalized to it)
- Error envelope: 200 with `{success:false, error:{code,message,statusCode}}` is treated as **failure, never as a draw**

**Configuration resolution order** (constructor): explicit constructor args → Admin "API Management" row (`ApiProviders::resolve('lottery')`, only adopted when `driver === 'loteriasapi'`) → environment variables:
```
WINDELS_LOTTERY_LOTERIASAPI_KEY        (required; presence alone also enables)
WINDELS_LOTTERY_LOTERIASAPI_ENABLED=1
WINDELS_LOTTERY_LOTERIASAPI_BASE_URL   (default https://api.loteriasapi.com/api/v1)
WINDELS_LOTTERY_LOTERIASAPI_GAME       (default euromillones)
WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT    (default 8s, capped 60)
WINDELS_LOTTERY_LOTERIASAPI_SOURCE     (default 'windels.ai')
```

**Base URL self-healing** (`normalizeBaseUrl()`): the marketing host `loteriasapi.com` → API root; `/v1` → `/api/v1`; a pasted full docs URL is reduced to its versioned root; any other host (custom gateway) is left exactly as configured.

**Payload mapping** (`normalizeDraw()`):

| Vendor field | → Neutral field |
|---|---|
| `drawId` / `draw_id` / `id` | `externalId` |
| `drawDate` / `draw_date` / `date` / `fecha` | `drawDate` |
| `combination` / `numbers` / `combinacion` | `main` |
| `resultData.estrellas` / `stars` / `luckyStars` / `extraNumbers`… | `stars` |
| `jackpotFormatted` `"130.000.000,00 €"` (authoritative) else `jackpot` `"13000000000"` = integer **cents** → `/100` | `jackpot` `"130000000.00"` |
| `prizes[category 1].winners == 0` | `rollover=true`, `winners="0"` |
| `updatedAt` / envelope `timestamp` / fallback `drawDate + T21:00:00+00:00` | `sourceTimestamp` |
| provider instance source label | `source` |
| `status`, `dayOfWeek`, prize count, El Millón code, raw combination | `extra.{status,dayOfWeek,prizeTiers,elMillon,rawCombination,numberLayout}` |
| full `prizes[]` (category, label, winners, amount) | `prizes` + `totalWinners` |

**The flat-combination problem and `splitLine()`** — the live feed sometimes publishes the whole winning line as ONE array: `combination: [7,12,29,33,45,3,9]` (5 numbers + 2 stars). Taken literally it fails validation ("got 7"). The adapter re-groups it **only** when the split is provably safe:

| Situation | Split? |
|---|---|
| Row also has `resultData.estrellas` and the flat list **ends** with exactly those values | yes → `extra.numberLayout = 'flat-combination-split'` |
| …and the flat list **begins** with exactly those values (stars first) | yes → `'flat-combination-split-stars-first'` |
| No star field, list is exactly 7 long, first 5 unique & in 1–50, last 2 unique & in 1–12 | yes (only possible 5+2 layout) |
| Anything else (unknown game, out-of-range, duplicates, wrong length) | **no** — left untouched, rejected downstream and audited |

Every re-grouped draw keeps the vendor's original list in `extra.rawCombination` for traceability. Known game layouts live in `GAME_FORMATS` (`'euromillones' => ['main'=>[5,1,50], 'stars'=>[2,1,12]]`) — a flat list is **never** split for an unknown game.

**`draws()` — windowed, quota-aware backfill:**
1. Clamp limit to 5000. Without explicit dates, derive a window: `weeks = ceil(limit/2)+2` capped at 1300 (~25 years, covering the 2004 launch without wasting calls on pre-2004 emptiness).
2. Split the window into ≤364-day chunks (`windows()`); walk **newest-first** (`array_reverse`).
3. Per window, `rangeRows()` pages through `/range` (`MAX_PAGES = 20` guard), following `meta.hasNext`.
4. De-duplicate raw rows by `rawKey` (id|date) across overlapping windows.
5. **Stop at the first window that adds nothing** (implicit backfill only) — a Free-plan key with 7-day history costs ~2 requests, not 26.
6. If `/range` yields nothing → try the history listing endpoint; if that fails → `/latest` as last resort, **but only if that draw is finished and playable** (`playableLatest()`: status finished + both number groups present — on draw day `/latest` answers a PENDING placeholder with no numbers).
7. Normalize, skip unfinished draws (`UNFINISHED_STATUSES = ['PENDING','SCHEDULED','PROGRAMADO','PROCESSING','IN_PROGRESS','CANCELLED','CANCELED']`), enforce explicit caller windows, de-duplicate by `externalId|drawDate`, sort newest-first, slice to limit.

**Plan-capped page sizes** (the vendor enforces per-request limits with HTTP 400): ask for `PAGE_SIZE=100`; on a 400 naming the limit, retry once at `MIN_PAGE_SIZE=5` (the documented Free floor); then adopt whatever the plan actually served (`meta.limit`) via `learnPageSize()` for all later pages.

**`jackpotInfo()`** — legacy feeds carry `jackpot_next` (euros); the live API publishes the pot with the latest draw (`jackpotFormatted`/`jackpot` cents). Returns `{source, value, currency:'EUR', observedAt, note:'Published jackpot from Windels API; not used to infer future draw outcomes'}`. Memoized with the `/latest` call (60s TTL, `LATEST_TTL_SECONDS`) so a status render costs one request, not two.

**`drawById()`** — accepts a date (`/date/{d}` route) or a vendor id like `2026029` / `2026/029` (resolved through a ±30-day windowed `/range` query with exact drawId matching — nothing inferred from the id).

**HTTP layer** — injectable `$transport` callable (default: `ApiProviders::http()` if present, else `file_get_contents` with SSL peer verification, `ignore_errors`, timeout). Status mapping:
- 401/403 → `authentication rejected` ; 429 → `rate limited — plan quota exhausted`; 404 → `base URL must be …/api/v1`; 400 → vendor's own message quoted (which parameter failed); 0 → `no HTTP response (network/SSL/firewall)`; non-JSON 200 → `invalid JSON payload`; 200 with `success:false` → vendor error code/message (`UNAUTHORIZED`/`FORBIDDEN` treated as auth failure).
- `safeMessage()` redacts the API key (string replace + `x-api-key:` regex) and truncates to 180 chars — **the key never appears in any error, log or view**.
- `httpsUrl()` refuses plain `http://` (and userinfo in URLs) — the provider stays unconfigured on a non-HTTPS base.

**Public branding note:** the class is the vendor adapter, but `name()` returns `'Windels API — EuroMillions'` — users see the product name, never the upstream vendor; the stored `source` attribution stays the real feed identity. A test locks this in.

### 4.4 `OfficialLotteryProvider` — generic authorized feed

For a custom/authorized upstream that already speaks (or is mapped to) the neutral contract: `GET {url}/draws?from=&to=&limit=` and `GET {healthUrl}`. Offline until explicitly ENABLED + HTTPS URL + license + source metadata are all present. Config from API Management (driver `official_lottery`/`custom_http`) or `WINDELS_LOTTERY_OFFICIAL_*` env vars. Bearer-token auth. Deliberately fails closed.

### 4.5 `LotteryResultValidator` — the ingestion gatekeeper

Every draw must pass **all** checks before storage:
1. non-empty `externalId`
2. `drawDate` matches `YYYY-MM-DD` **and** is a real calendar date (`checkdate`)
3. `main`/`stars` coerced to ints (non-numeric → `-1`, always out of range) then `rules->validateLine()` (counts, ranges, duplicates)
4. non-empty `source` ("no draw may be stored without a source")
5. non-empty `sourceTimestamp`

Returns `{valid, status: VALID|DATA_VALIDATION_FAILED, errors[]}`. Failures are **never stored** — they're audited as `LOTTERY_DRAW_VALIDATION_FAILED`.

### 4.6 `LotteryStatisticsEngine` — pure statistical functions

Constant: `DISCLAIMER = 'Lottery draws are independent random events. Historical frequency, gaps and patterns are statistical observations only — they do not change the probability of future draws and are not forecasts of future results.'` — attached to **every** output. The engine deliberately has **no "due" concept**: a long gap is reported as "absent for X draws", never "likely to appear next".

- **`numberStats($draws, $min, $max, $window)` / `starStats(...)`** — per number: `appearances`, `appearancePct`, `lastAppearance` (date), `drawsSinceLast`/`currentGap`, `avgGap`/`minGap`/`maxGap` (gaps between consecutive appearances), `recentAppearances`/`recentPct` (last-N window), `trend` (recent share minus overall share — labeled an observation). Draws must be sorted ASC by date.
- **`hotCold($draws, $field, $min, $max, $window=50, $top=5)`** — top-5 by count in the window (hot) and bottom-5 (cold), with the observation note.
- **`distribution($draws, $min, $max, $mainCount)`** — odd/even and low/high split histograms (low ≤ midpoint 25) + percentages, sum stats (min/max/avg/median), spread stats, consecutive analysis (draws containing runs, %, longest run, run-length distribution).
- **`groupStats($draws, $field, $min, $max, $k, $topN=20)`** — k-subset co-occurrence (k = 2 pairs, 3 triplets): exhaustive `combinations()` of each draw's numbers (k capped at 5), keyed `"a-b-c"` sorted; per group: count, lastSeen, avgGap, maxGap; sorted by count desc; `top` slice.

### 4.7 `CombinationAnalyzer` — per-line profile + balance score

Full statistical profile of one line against the stored draws:

- **Composition:** odd/even, low/high (low ≤ 25), sum, spread, adjacent pairs / longest run / runs of 3+
- **Pattern traits:** all-same-last-digit, within-single-decade, birthday count (1–31), multiples of 5
- **Historical context:** sum percentile, spread percentile, most-common odd/low counts (`bestMode()` — mode of the count distribution, ties resolved toward the ideal then the smaller value), best historical overlap (draw with most shared numbers), draws sharing ≥3 numbers, same-odd/even draws, draws with sum within ±10
- **`numberProfile` / `starProfile`** — per-number stats for each selected number

**The STATISTICAL BALANCE SCORE (0–100)** — weighted fit of the line's composition to typical historical draws:

```
WEIGHTS = ['sum'=>0.30, 'oddEven'=>0.20, 'lowHigh'=>0.20, 'spread'=>0.15, 'consecutives'=>0.15]

sumFit        = max(0, 100 * (1 - |sum - histAvg| / (2*histStd)))     (0 if std=0 & mismatch, 100 if equal)
oddEvenFit    = max(0, 100 - 20 * |odd - bestOdd|)
lowHighFit    = max(0, 100 - 20 * |low - bestLow|)
spreadFit     = max(0, 100 * (1 - |spread - spreadAvg| / spreadAvg))
consecutivesFit = max(0, 100 - 25 * adjacentPairs)
score = round(Σ weight_i * fit_i)
```

With zero historical draws: neutral score 50. Labeled `STATISTICAL BALANCE SCORE: N/100` with `scoreMeaning`: "…NOT a probability and it does not indicate how likely the line is to be drawn."

### 4.8 `CombinationGenerator` — the 5-mode AI generator

Constants: `MODES = ['RANDOM','BALANCED','HISTORICAL','DIVERSIFIED','ANTI-POPULAR']`, `MAX_LINES = 100`.

**Determinism:** seed (caller-supplied or `microtime*1e6 % 2147483647`, 0→1) drives `MathUtils::seededRandom()` — a **xorshift32 PRNG** returning a closure over `&$s`:

```php
$s ^= ($s << 13) & 0xFFFFFFFF;
$s ^= $s >> 17;
$s ^= ($s << 5) & 0xFFFFFFFF;
return $s / 4294967296.0;
```

Same seed + same inputs → same lines, forever (a documented `reproducible` promise).

**Locks/excludes** (validated up front): locked numbers always present (can't lock more than the pick count), excluded never present, lock∩exclude = error, pools checked for sufficiency (`not enough main numbers remain after exclusions`).

**Mode mechanics** (rejection sampling with per-mode attempt caps):

| Mode | kMax attempts/line | Mechanism |
|---|---|---|
| RANDOM | 1 | Uniform sample without replacement (seeded Fisher–Yates prefix) |
| BALANCED | 60 | Compute targets from history: sum within ±1 std of average; odd/low counts within ±1 of the most common historical split; ≤1 adjacent pair. Reject until fit. |
| HISTORICAL | 1 | Weighted sampling without replacement, **weight = 1 + appearances** |
| DIVERSIFIED | 24 | Generate candidates, pick the one with the lowest **overlap penalty** vs context lines (10 per shared main, 20 per shared star); context = provided lines + lines already generated in this call |
| ANTI-POPULAR | 80 | Reject lines that: have >2 numbers in 1–31 (birthday-heavy), contain ascending runs ≥3, >1 adjacent pair, all-same last digit, or are confined to a single decade |

Each emitted line is re-validated (`rules->validateLine`) and profiled by the analyzer (score + composition summary). If no candidate passes after kMax attempts, the best-so-far pool is used ("all" fallback) so generation never fails silently.

**The report** (persisted as an AI decision):
```json
{
  "model": "WINDELS Lottery Model v1.0", "lottery": "EUROMILLIONS", "mode": "...",
  "lineCount": 5,
  "lines": [{"mains":[...], "stars":[...], "score": 87, "scoreLabel": "STATISTICAL BALANCE SCORE: 87/100", "profile": {...}}],
  "averageBalanceScore": 84.3,
  "inputs": {"seed": 123, "rulesVersion": "1.0", "drawsUsed": 60, "lastDrawDate": "...",
             "datasetVersion": "n=60;last=2026-09-04", "locks": {...}, "excludes": {...}, "contextLines": 0},
  "factors": { "method": "...", mode-specific evidence ... },
  "generatedAt": "...", "disclaimer": "...",
  "honestyNote": "Every valid EuroMillions combination has exactly the same mathematical chance of being drawn..."
}
```

`factors` records the **actual** method/targets/weights used — never invented after the fact.

### 4.9 `DiversificationEngine` — set-diversity scoring

For a set of lines (all validated + sorted): all-pairs comparison (O(n²), bounded by line size):
- shared mains / stars (avg + max), shared pairs = C(|A∩B|, 2), shared triplets = C(|A∩B|, 3) — computed combinatorially, not enumerated
- distribution similarity: same odd/even split %, same low/high split %, avg |sum difference|
- duplicates (identical lines), pair-reuse share across the whole set

**DIVERSITY SCORE (0–100):**
```
penalty = 45*(avgMain/mainCount) + 15*(avgStar/starCount) + 10*(sameOEPct/100)
        + 10*(sameLHPct/100) + 5*(1 - min(1, avgAbsSumDiff/30)) + 30*(identicalPairs/pairCount)
score = clamp(100 - penalty, 0, 100)
```

### 4.10 `SystemBuilder` — the wheel builder

Constants: `SYNC_LINE_LIMIT = 10000` (bigger systems are background-built), `MAX_BACKGROUND_LINES = 200000`, `MAX_PAGE = 500`.

- **`plan(mainPool, starPool)`** — validates/normalizes pools (unique in-range ints, sorted; ≥5 mains, ≥2 stars), computes `totalLines = C(N,5) × C(S,2)` **combinatorially** (`comb()` multiplicative formula — never hardcoded), returns formula string (`"C(9,5) x C(4,2) = 126 x 6"`), 100% pool coverage note, `estimatedCost: null` + `costNote: 'Official line pricing is not available in this environment — no cost is fabricated.'`, `requiresBackground` flag.
- **`lines()`** — PHP generator: lexicographic k-subset iteration (`combinationsOf()` with an index array), main combos outer, star combos nested — **constant memory** even for 200k-line systems.
- **`page(mains, stars, offset, limit)`** — paginated window over the generator (for the interactive UI).
- **`allLines()`** — materialized (only for bounded builds).

### 4.11 `LotteryBacktester` — Strategy Lab

Constants: `STRATEGIES = ['RANDOM_BASELINE','BALANCED_PROFILE','HISTORICAL_FREQ','ANTI_POPULAR']`, `MIN_HISTORY = 10` (pre-draw history before the first test draw), `MAX_WINDOW = 100`, `MAX_LINES = 10`.

**No look-ahead:** for test draw *i*, the strategy only sees `draws[0..i-1]` (`array_slice($draws, 0, $i)`). Deterministic per strategy+draw+model: `seed = crc32(strategy) ^ (drawIndex * 2654435761) ^ crc32('WINDELS-Lottery-Model-v' + version)`.

Per draw: generate `lines` lines with the matching generator mode, match against the real draw (main/star matches via array_flip intersections), assign the official prize tier. Report:
- period (from/to/drawsTested/minHistoryDraws/windowCap), linesPerDraw, totalLines
- matchDistribution (mains 0–5, stars 0–2 histograms)
- tierCounts, bestLine
- `simulatedCost: null` / `simulatedWinnings: null` with explicit notes — **no cost or winnings are ever fabricated**; only tier counts (the tier structure is stable)
- label `HISTORICAL SIMULATION` + the random-baseline note

**`compare()`** — every strategy replayed on the **same** period; **throws if `RANDOM_BASELINE` is missing** ("the random baseline must be part of every comparison"); no strategy is ever declared "better".

### 4.12 `LotteryIntelligence` — the facade (1,516 lines)

Constants: `MODEL_VERSION = '1.0'`, `LOTTERY = 'EUROMILLIONS'`, `FULL_HISTORY_LIMIT = 5000` (backfill ceiling; EuroMillions has ~2,300 draws since 2004), `DRAWS_PER_YEAR = 104` (for `parseWindow('1y'|'2y'|'6m')`), `MIN_RELIABLE_DRAWS = 50`, `HISTORY_BACKED_MODES = ['BALANCED','HISTORICAL','ANTI-POPULAR','DIVERSIFIED']`, `MAX_TICKET_LINES = 50`, `INTELLIGENCE_MODE = 'INTELLIGENCE'`.

Constructor wires everything: statistics engine, rules (stored→default), provider (default Unavailable), analyzer, generator, diversification, system builder, backtester; ensures the lottery registry row.

**Canonicalization** — the winning line is a *set*: `normalizeGroup()` (ints, ascending) is applied on storage **and** on every read surface (`presentDraw()` exposes `numbers.main/stars`, `main_numbers`, `lucky_stars`, `draw_no`). Idempotency is order-insensitive too (`sameNumbers()`).

**`status()`** — the single dashboard payload: provider health, rules, engine state, drawsTracked, lastDraw, disclaimer, and the widget aliases: `status` (ONLINE / STORED DATA / DATA UNAVAILABLE / NO_DATA), `jackpot`, `jackpotSource` (`{origin: PROVIDER_FEED|STORED_DRAW, provider, observedAt, currency, hardcoded:false}` — *proves the amount is feed data, never hardcoded*), `verifiedDraws`, `dataAvailable`, `historicalDataset`, `lastSuccessfulSync`, `lastSyncAttempt`, `syncStatus` (OK/DEGRADED/STALE/FAILED/NEVER_SYNCED), `syncMessage`, `nextEstimated` (next Tue/Fri hint). Honest-state logic:
- feed ONLINE → ONLINE
- feed failed but verified draws stored → **STORED DATA** (history still real)
- feed configured but unreachable, nothing stored → **DATA UNAVAILABLE**
- never configured → **NO_DATA**

**`sync($limit)`** — health check → if not ONLINE: record OFFLINE health row + audit `LOTTERY_SYNC_FAILED` + return `DATA UNAVAILABLE` (or `NO_PROVIDER` when unconfigured). Else: `provider->draws()` → `importDraws()` → health row (ONLINE/DEGRADED, response_ms, records received/invalid, last_draw_retrieved) → audit `LOTTERY_SYNC_COMPLETED` → return summary + dataset stamp. Database failures are surfaced as failed syncs, never clean ones.

**`importDraws($raw)`** — per draw: validate → normalize ascending → find by externalId →
- identical numbers (order-insensitive) → `unchanged` (idempotent no-op)
- exists & VERIFIED & different → `conflicts` + audit `LOTTERY_RESULT_CONFLICT` ("NOT overwritten; manual correction required")
- exists & unverified & different → corrected + audit `LOTTERY_DRAW_CORRECTED`
- new → stored `VERIFIED` (the row is marked VERIFIED **strictly after** validation passes) + `LOTTERY_DRAW_IMPORTED`
Payload JSON stores: main, stars, jackpot, rollover, winners, prizes, totalWinners, drawDate, extra (El Millón, numberLayout, rawCombination…).

**`historicalDataset()`** — THE single accessor for stored VERIFIED draws (`drawsForStats()`), read by statistics, analyzer, generator, Strategy Lab and backtests alike — "which dataset was used" is always auditable via `datasetInfo()`: `{source:'VERIFIED_HISTORICAL_DATABASE', draws:n, from, to, available, datasetVersion:'n=60;last=2026-09-04'}`.

**`generate()`** — refuses history-backed modes on an empty dataset with `DATA UNAVAILABLE — …` (never silently degrades to random; RANDOM stays as the explicit baseline), then delegates and stamps dataset provenance + `usedForGeneration` / `randomBaseline` flags.

**`saveGeneration()` / `saveSystem()`** — persist `lottery_combinations` + `lottery_ai_decisions` rows + `LOTTERY_COMBINATION_GENERATED` / `LOTTERY_SYSTEM_BUILT` audit events.

**`intelligenceReport($lines, $seed)`** — the flagship report, computed **only** from verified data:
- dataState: `INSUFFICIENT_DATA` (0 draws — **no candidates generated at all**), `LIMITED_DATA` (<50 — provisional warning), `RELIABLE`
- analysis blocks: main/star field stats (most/least frequent, recent-hot, longest absence — each with the "does NOT make any number more likely" note), recurring pairs/triplets/star-pairs, full distribution
- candidate lines: BALANCED generation (seeded, reproducible — `reproducibleNote`), ranked by score, each with `scoreBreakdown`, `composition`, and a plain-English **explanation** list ("Sum 132 — the historical sum average is 129 (typical range 107–151).", "3 odd / 2 even — matches the most common historical split.", "Includes 2 of the most frequent main numbers (7, 19)…")
- provenance block: provider id/name only ("the API key is never exposed"), draw source, verifiedOnly:true, "Nothing is hard-coded or fabricated."
- `scoreMeaning`, `scoreWeights`, `disclaimer`, `honestyNote`

**`runIntelligence()`** — sync first (idempotent; full backfill on empty DB, else 100-draw delta) → report → persist (INTELLIGENCE combination + AI decision) → audit `LOTTERY_INTELLIGENCE_RUN`. `intelligenceSnapshot()` = latest persisted report + live status (served by `GET /api/lottery/intelligence`).

**Tickets:** `createTicket()` (validates EVERY line — one bad line rejects the whole ticket; ≤50 lines; methods whitelist; lines sorted on save), `listMyTickets()` (user-scoped), `checkTicket()` (compares against the latest VERIFIED draw on or before the ticket's date; per-line mainMatches/starMatches/`prizeTier` via the official 13-tier table; marks CHECKED, audited), `archiveTicket()` (soft delete).

**`prizeTier($mainMatches, $starMatches)`** — the official EuroMillions tier table: 5+2=TIER_1, 5+1=TIER_2, 5+0=TIER_3, 4+2=TIER_4, 4+1=TIER_5, 3+2=TIER_6, 3+1=TIER_7, 2+2=TIER_8, 1+2=TIER_9, 0+2=TIER_10; else null. Tier labels only — amounts vary per draw and are never stored.

**Model versioning:** `ensureModelVersion()` upserts an immutable `lottery_model_versions` row recording score weights, generator modes, backtester strategies/limits — historical results stay connected to the model that produced them.

**`performance()`** — three sections that are **never mixed**: ACTUAL TICKET RESULTS (checked tickets, tier histogram), HISTORICAL BACKTEST RESULTS (recent simulations), DEMO/SANDBOX DATA (provider synthetic flag) — each with a note, plus the separation note and disclaimer.

### 4.13 `LotteryCronService` — 8 scheduled jobs

`JOBS = ['sync','health','statistics','systems','tickets','backtests','intelligence','cleanup']`, run via `php index.php tools lottery-cron [job]`. `runAll()` isolates failures (one failed job never aborts the sweep; each failure audited as `LOTTERY_JOB_FAILED`).

| Job | Behavior |
|---|---|
| `sync` | Execution key `sync:EUROMILLIONS:YYYY-MM-DD` — once per day. Full backfill (5000) on empty DB, else 100-draw delta. |
| `health` | Live provider state; **DEGRADED when last success >8 days old** (draws are twice weekly). |
| `statistics` | Integrity sweep: re-validates **every stored draw** against the rules; violations audited as `LOTTERY_INTEGRITY_VIOLATIONS` (fix path is manual correction, never silent rewriting). |
| `systems` | Processes queued `system` job runs (execution-key idempotent); saves SYSTEM combinations; rejects >200k lines. |
| `tickets` | Auto-checks OPEN tickets whose draw date ≤ latest verified draw (CHECKED tickets never re-checked). |
| `backtests` | One backtest per strategy per day (`backtest:EUROMILLIONS:<strategy>:<date>` key); the random baseline runs every day. |
| `intelligence` | Regenerates the report **only when a new verified draw landed** (`UP_TO_DATE` otherwise); key includes the newest draw date. |
| `cleanup` | Deletes job runs >90 days, health rows >30 days. |

Registered in the platform scheduler (`CronScheduler.php`) as the **"Lottery sweep", every 6 hours (21600s), default enabled**, dispatched through `CronRunner::lottery()`.

---

## 5. Persistence layer

`LotteryRepository` (interface in `application/libraries/AIWorkforce/Persistence/Repositories.php`) defines ~35 methods: lottery/rules registry, provider registry + health, draws (find/list/save + numbers + `drawsForStats`), job runs (start/finish/list/findByKey/deleteOld), combinations, AI decisions, tickets (+lines, user-scoped find), model versions, backtests.

The concrete implementation is an **anonymous class over CodeIgniter's query builder** inside `models/AIWorkforce_model.php` (constructed with the CI DB handle). Interesting details:
- `ensureProvider()` upserts `lottery_data_sources` and heals a renamed display label
- `saveDraw()` insert-or-update; `drawsForStats()` returns decoded `[{drawDate, main[], stars[]}]` ASC — the exact shape every engine consumes
- `startJobRun()` returns null when the execution key already exists — that's the whole idempotency mechanism
- `saveHealth()` always inserts a fresh history row (observed_at now)

To port: implement the same interface over your ORM (Eloquent, Prisma, SQLAlchemy…); the domain layer never touches the framework.

---

## 6. API layer — `Api_lottery` controller

Extends the platform's `Api_controller` (JSON helpers `json()` / `jsonError($msg,$status)` / `jsonBody()`, `requirePermission($perm, $csrf=true)` which enforces RBAC **and** session CSRF on mutations).

**Authorization matrix:**

| Endpoints | Auth |
|---|---|
| `status`, `dashboard`, `lotteries`, `rules`, `draws`, `draws/{id}`, `statistics`, `providers`, `health`, `jobs`, `analyze`, `combinations`, `combinations/{id}`, `system`, `backtests` (list), `intelligence` | **public** (read-only, no secrets, no PII) |
| `generate`, `diversity`, `tickets` (create/list/show/check/delete), `backtest`, `backtest-compare`, `models`, `performance`, `backtests/{id}` | `lottery.view` (+ CSRF on POST) |
| `system-build`, `intelligence/run`, `sync` | `lottery.manage` (+ CSRF) |

**Content negotiation** on `statistics`, `system`, `backtests`: `?format=json` forces JSON; otherwise the `Accept` header decides — browsers (`text/html`) get the server-rendered public page via `lotteryHtml()` (layout header/footer + flash messages), API clients get JSON. This is how `/lottery/statistics`, `/lottery/system`, `/lottery/backtests` are public pages AND JSON endpoints on one route.

**Route map** (`application/config/routes.php`):
```
/lottery, /lottery/tickets                  → Lottery::index           (RBAC-gated dashboard)
/lottery/statistics[/kind]                  → api_lottery/statistics   (public HTML/JSON)
/lottery/system                             → api_lottery/system       (public HTML/JSON)
/lottery/backtests                          → api_lottery/backtests    (public HTML/JSON)
/api/lottery/status|dashboard|lotteries|rules|draws|draws/(:num)|
  statistics|analyze|generate|diversity|combinations|combinations/(:num)|
  system|system-build|backtest|backtest-compare|backtests|backtests/(:num)|
  models|performance|intelligence|intelligence/run|
  tickets|tickets/(:num)|tickets/(:num)/check|tickets/(:num)/delete|
  providers|health|jobs|sync               → Api_lottery::*
/tools/lottery-cron, /tools/lottery-smoke   → CLI tools
```

Notable endpoint semantics:
- `POST /api/lottery/generate` `{mode, count, seed?, locks:{mains,stars}, excludes:{mains,stars}, contextLines?}` → report + persisted ids
- `GET /api/lottery/analyze?mains=1,2,3,4,5&stars=2,5` → full line profile
- `POST /api/lottery/system` `{mains:[pool], stars:[pool], page, limit}` → plan + page of lines; systems above the sync limit answer **HTTP 409** pointing at `system-build`
- `POST /api/lottery/system-build` → inline build (≤10k lines) or idempotent background queue
- `POST /api/lottery/backtest-compare` → **400 if `strategies` lacks `RANDOM_BASELINE`**
- `POST /api/lottery/intelligence/run` `{lines:1-20, seed?}` → sync + analysis + persist
- `POST /api/lottery/sync` `{limit:1-1000}` → idempotent provider sync
- Ticket check with no stored draw → **409** with the actionable message

**`dashboard()`** (used by the Next.js layer) assembles: status + formatted jackpot (`€130.0M` / `€850K`), recent 3 draws, the caller's ticket count, and the full sync/honesty block — with a hard-coded honest default shape on any failure (`status:'NO_DATA'`, `syncMessage:'DATA UNAVAILABLE — the lottery module could not be read.'`).

---

## 7. Web UI layer

### 7.1 The dashboard page (`Lottery::index` + `views/lottery/index.php`)

**Hydration pattern:** the controller gathers status, 20 draws, 20 my-tickets, 10 recent combinations, 10 backtests, the intelligence snapshot, `me` (id/name/canManage) and the endpoint map, then JSON-encodes everything into the page:

```php
'stateJson' => json_encode([...], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
```
```html
<script>window.__AI_LOTTERY_STATE__ = <?= $stateJson ?>;</script>
<script src="/assets/js/lottery.js" defer></script>
```

RBAC first (`lottery.manage`/`lottery_admin` → canManage; `lottery.view`/`lottery_viewer` → canView; otherwise flash error + redirect). Every sub-read is wrapped in try/catch with an honest fallback (e.g. `NO_DATA` status with default rules 5/50+2/12).

The inline IIFE renders (no framework):
- **Lottery Intelligence panel** — latest verified draw (balls), draws analyzed, last sync, data source + note, generated-at/model/mode/seed "(reproducible)", main/star field analysis (most frequent / recent / longest absence), ranked candidate table with score + why-summary, best line with score breakdown and full explanation list, score meaning/weights/disclaimer/honestyNote, admin-only "Run Lottery Intelligence" button (POSTs to `/api/lottery/intelligence/run` with the CSRF token from the page's hidden input, then reloads)
- **Cards:** Next draw (jackpot + jackpot-source provenance + provider + message + actions: Generate 5 AI lines / My tickets / Strategy Lab), Last verified draw, Historical data sync (badge OK/DEGRADED/STALE/FAILED/NEVER_SYNCED, verified count, last success/attempt, dataset span, message), Quick links
- **Tabs:** Recent draws / Recent AI combinations / My tickets / Backtests (server-rendered tables)
- Status badges: green ONLINE, red DATA UNAVAILABLE, amber otherwise
- **Visual language:** yellow balls (`#ffd24a`) for mains, light-blue star chips (`#7dd3fc`) for Lucky Stars, small variants for tables
- A `noscript` fallback explains the JS client; `assets/js/lottery.js` progressively enhances (the "Generate 5 AI lines" button POSTs `{mode:'HISTORICAL', count:5}` and alerts the DATA UNAVAILABLE error instead of silently falling back to random)

### 7.2 Public pages

- `statistics.php` — kind tabs (frequency/gap/distribution with 1y/2y windows), hot/cold chips, the full per-number table (appearances, %, last seen, draws since, avg/max gap), distribution panels (odd/even, low/high ≤25, sum min/max/avg/median, spread, consecutive)
- `system.php` — pool form (GET → same endpoint), plan card (formula, line count, background notice), paginated line table with balls/stars
- `backtests.php` — persisted backtest list (id, strategy, model, draws, period, created) linking to `/api/lottery/backtests/{id}`

### 7.3 Next.js layer (`apps/web`)

- **Routing** (`next.config.ts`): all browser `/api/*` calls are rewritten server-side to the PHP backend — `destination: ${LEAD_API_INTERNAL_URL ?? 'http://127.0.0.1:3001'}/api/:path*`. The browser never learns the backend address; every client fetch is a relative URL.
- `lib/lottery.ts` — **server-side only** bridge: `EMPTY_LOTTERY_DASHBOARD` (the honest empty shape, mirrors `Api_lottery::dashboard` defaults) + `fetchLotteryJson()` (8s AbortController timeout, `cache:'no-store'`, returns null on any failure). Target: `LOTTERY_API_INTERNAL_URL ?? LEAD_API_INTERNAL_URL ?? 'http://127.0.0.1:8080'`.
- `app/api/lottery/dashboard/route.ts` (`dynamic = 'force-dynamic'`) — thin proxy; unreachable backend → the honest NO_DATA shape with **status 200** (never an invented draw).
- `app/api/lottery/statistics/route.ts` — proxies `statistics/hot-cold?window=0` and **converts the backend's associative map** (`{number: count}`) into plain ascending `number[]` lists (`hot`, `cold`) so the widget renders balls without knowing statistics internals; unreachable backend → empty arrays (no invented hot/cold numbers).
- `app/app/dashboard/page.tsx` — the dashboard page fetches both endpoints on mount (`/api/lottery/dashboard` + `/api/lottery/statistics`), feeds `EuroMillionsWidget`, and shows a "🎰 Lottery" QuickStatCard (`{imported} draws`).
- `components/lottery/` — `LotteryWidget` (jackpot, countdown to `nextEstimated`, recent results), `EuroMillionsWidget` (enhanced: 1-second countdown timer, hot/cold chips, client-side quick generator drawing uniform random numbers within the rules — clearly a client toy, not the AI engine), shared `types.ts` (`LiDraw`, `LiDashboard`, `EuroMillionsData` — mirrors of the backend response shapes), barrel `index.ts`, and a component README.

---

## 8. Admin & platform integration

- **API Management** (`ApiProviders.php`): service `lottery` ("Lottery / EuroMillions", group EuroMillions, kind data, drivers `['loteriasapi','official_lottery','custom_http']`). The `loteriasapi` driver form: Base URL, API Key (x-api-key, secret), Game code, Timeout. A **Test** button runs `testLoteriasApi()` → normalizes base/game, calls `/latest`, and returns precise operator messages (connected + latest draw date / invalid key / 404 = wrong base URL / 429 = plan quota / network). Keys stored encrypted; never rendered back.
- **Cron scheduler**: "Lottery sweep" — every 6 hours, default enabled, label group "Lottery Intelligence".
- **RBAC** (`tools/rbac.php`): permissions `lottery.view` (view draws/statistics/tickets/performance) and `lottery.manage` (manage providers, sync, configuration); roles `lottery_admin` → both, `lottery_viewer` → view, `platform_member` includes `lottery.view`.
- **Chat assistant** knows the module: system prompts describe /lottery as historical-observations-only; the matching keywords include euromillions/lucky star/wheel/backtest.
- **SchemaInstaller** creates/migrates the 14 tables; `.env.example` documents every env var (`WINDELS_LOTTERY_OFFICIAL_*`, `WINDELS_LOTTERY_SANDBOX`, and the `WINDELS_LOTTERY_LOTERIASAPI_*` set).
- **CLI tools** (`Tools` controller): `php index.php tools lottery-smoke [--raw]` (provider health + 3 draws + jackpot; exit 0 live / 1 unreachable / 2 unconfigured; `--raw` adds the vendor's unmapped latest row for feed-shape diagnosis) and `php index.php tools lottery-cron [job]`.
- **Admin "Sync Now" button** (`Admin::api_sync` + `views/admin/api/form.php`): shown only for `service === 'lottery'` rows; gated on `admin.api.manage` + session CSRF + a JS confirm dialog; runs `sync(FULL_HISTORY_LIMIT)`; audits `API_PROVIDER_SYNCED` through the admin portal logger (with imported/unchanged/failed/verifiedDraws payload); and the flash uses `syncNotice()` so the operator sees "Sync complete: N imported… First issue: <reason>" — never a bare "1 rejected". Other services answer "Manual synchronization is only available for the lottery service" (honest, not silent). The lottery dashboard's admin link deep-links `/admin/api/create?service=lottery` (preselects the Lottery service).

### 8.1 How "public" endpoints actually work (base-controller mechanics)

`Api_lottery` extends `Api_controller` (in `application/core/MY_Controller.php`), whose constructor enforces a **route allowlist**:

```php
private const PUBLIC_ACTIONS = [
    // ... other services ...
    'api_lottery/status' => true, 'api_lottery/dashboard' => true,
    'api_lottery/lotteries' => true, 'api_lottery/rules' => true,
    'api_lottery/draws' => true, 'api_lottery/statistics' => true,
    'api_lottery/analyze' => true, 'api_lottery/combinations' => true,
    'api_lottery/system' => true, 'api_lottery/backtests' => true,
    'api_lottery/models' => true, 'api_lottery/performance' => true,
    'api_lottery/intelligence' => true, 'api_lottery/providers' => true,
    'api_lottery/health' => true, 'api_lottery/jobs' => true,
];
// constructor: any route NOT in the map + no session →
//   HTML GET request → redirect to /login with return_to (+ query string)
//   otherwise → 401 {"error":"unauthenticated"}
```

So "public" is a deliberate per-route decision, not a missing check. Note `generate`, `diversity`, tickets, `backtest`, `backtest-compare`, `sync`, `system-build`, `intelligence/run` are **not** in the map — they require a session and then RBAC.

**`requirePermission($perm, $csrf = true)`** (base controller): refreshes permissions from the database (never trusts the sign-in snapshot), checks `platform->identity->can()`, and for non-GET/HEAD methods compares the `X-CSRF-Token` request header against the session token with `hash_equals()` (timing-safe) → 403 `invalid CSRF token`. `jsonBody()` reads `php://input` and falls back to CI's POST array; `json()` emits `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

### 8.2 Every other surface the module appears on

| Surface | What's there |
|---|---|
| **Sidebar nav** (`views/layout/header.php`) | "EuroMillions" link → `/lottery` with active-state class and an inline SVG ticket icon |
| **Workspace home** (`Workspace::lotteryWidgetData()` + `views/workspace/index.php`) | A "🎰 EuroMillions Lottery Intelligence" panel: jackpot card (`jackpotFormatted`, verified-draws count), buttons (Open Lottery Intel / Generate Numbers / View All Draws / My Tickets (N)), recent draws mini-list — all from the same `status()` + `listDraws(3)` + `listMyTickets(100)` calls, with the same honest-NO_DATA default shape |
| **AI Command Center** (`views/command_center/index.php`) | "🎰 Lottery Intel" quick-action card → `/lottery` |
| **Marketing site** (`views/site/*`) | home lede ("…lottery research…"), "EuroMillions research module" feature chip, services page ("Lottery research — EuroMillions rules, statistics and ticket tools. Official feeds stay off until a licensed source is configured. API module · lottery.view"), about/faq/how/locations/safety + auth login/register pages mention it |
| **Chat assistant (PHP)** (`ChatAssistant.php`) | Full topic entry: "/lottery — HISTORICAL OBSERVATIONS ONLY, not predictions…" plus a keyword router matching `lottery`, `euromillions`, `euro millions`, `lucky star`, `ticket`, `lottery intel`, `frequency`, `hot cold`, `gap`, `distribution`, `system builder`, `wheel`, `backtest` |
| **Chat assistant (Node)** (`apps/api/src/routes/chat.ts`) | Its own SYSTEM_PROMPT + a `lottery`/`euromillion` intent → "EuroMillions: /lottery. Statistics, systems and backtests are historical or labeled data; official draws require a configured feed." |
| **Feature inventory** (`Api_system::features()`) | Six catalogued modules with statuses: foundation, combination intelligence (analyzer/generator/diversification), analysis & suggestions, system builder, ticket builder, backtesting + model versioning + performance (all IMPLEMENTED); authorized official feeds (PLANNED) |
| **Kill-switch scope** (`docs/KILL_SWITCH_SCOPE.md`) | Lottery is explicitly listed as **never gated** by the trading kill switch (no order surface, no money movement) — non-trading modules keep running when trading halts |
| **Platform module registry** (`Platform.php`) | The `lottery` module is registered with agent-tool grants `lottery.getResults` + `lottery.generateCombinations` (what the Windels AI Agents may call) |
| **diagnose.php** | Verifies `AIWorkforce\Lottery\LotteryIntelligence` is loadable in the deployment doctor |
| **Deployment SQL** (`database/production.sql`, `database/windyohq_myai.sql`) | Ship the 14 lottery tables + seeded rows for production/cPanel installs |
| **Stylesheet** (`assets/css/ai_workforce.css`) | `.lottery-meta` is part of the monospace text group (the rest of the lottery styling lives inline in the view) |

### 8.3 The specification the code cites

Every docblock references a numbered internal spec (§4 rule engine, §6 validation, §8–§14 statistics, §15–§17 generation, §18–§19 systems, §20/§29 tickets, §22 diversity, §23–§25 backtesting, §26 decision reports, §28/§31 ingestion/sync, §30 performance separation, §33 model versioning, §38 user isolation, §40 cron, §41/§42 honesty, §43 API). That spec lives in **`README.md` §"Lottery Intelligence (native WINDELS module — EuroMillions first)"** — the README doubles as the requirements document, and `docs/LOTTERY_EUROMILLIONS_API.md` is the vendor-integration supplement. When rebuilding, keep this pattern: one canonical spec, phase-numbered, cited from every docblock.

---

## 9. Testing — 118 tests across 12 files

Run with `php index.php tools tests` through a zero-dependency micro framework (`tests/framework.php`: `test()`, `assert_true/false/equals/not_equals/close/throws`), executing through the real CI3 stack. Discovery: `glob(tests/cases/*.php)` sorted; an `AI_WORKFORCE_TEST_FILTER` env var (or CLI arg) filters by filename substring (e.g. `AI_WORKFORCE_TEST_FILTER=lottery` runs only these suites). One more suite touches the module: `tests/cases/62-unfinished-module-scaffolds.php` covers `OfficialLotteryProvider` (HTTPS/license fail-closed, source attribution preserved).

| File | # | Covers |
|---|---|---|
| 53-lottery-rules-validation | 3 | counts/ranges/duplicates/error wording |
| 54-lottery-import-idempotency | 7 | re-import no-op, order-insensitive, conflict protection, VERIFIED never overwritten |
| 55-lottery-statistics | 6 | frequency/gap/hot-cold/distribution/group stats math |
| 56-lottery-governance-e2e | 3 | end-to-end governance (audit events, honest states) |
| 57-lottery-generator | 10 | 5 modes, determinism (same seed → same lines), locks/excludes, constraints |
| 58-lottery-diversification | 6 | overlap math, duplicates, penalty formula |
| 59-lottery-system-builder | 6 | C(N,5)×C(S,2) counts, pagination, lazy enumeration, limits |
| 60-lottery-tickets | 5 | create/validate/check/archive, user scoping |
| 61-lottery-backtesting | 8 | no look-ahead, tier assignment, mandatory baseline, same-period compare |
| 109-lottery-loteriasapi-provider | 44 | the full vendor contract: base-URL rewriting, live + legacy + flat payloads, envelope errors, page-size retry, window walking, money parsing, key redaction, end-to-end ingestion |
| 110-lottery-last-verified-draw | 10 | canonical ascending presentation, newest-verified accessor |
| 111-lottery-intelligence-report | 10 | ranked candidates, reproducibility, explanations, insufficient-data honesty, key never in report |

Test fixtures worth copying: `fx_loterias_live_payload()` (camelCase cents payload), `fx_loterias_flat_payload()` (the 7-number flat line), `fx_loterias_live_envelope()`, `fx_loterias_payload()` (legacy snake_case), and fake repositories (in-memory AuditRepository) — the transport callable is stubbed so **no test ever hits the network**.

---

## 10. The governance / honesty contract (the most important part)

These rules are enforced in code and locked by tests — reproduce them faithfully in any rebuild:

1. **Nothing is fabricated.** Missing key / disabled provider / non-JSON / HTTP error / network failure → `UNCONFIGURED` / `DISABLED` / `OFFLINE` / `NO_DATA` / `DATA UNAVAILABLE` and **zero draws**. A configured-but-dead feed with stored draws is `STORED DATA`; with nothing stored it's `DATA UNAVAILABLE`; never "no data exists".
2. **Every draw is validated** before storage (counts, ranges, duplicates, real date, source, timestamp); rejections are audited, never stored as official.
3. **Idempotent ingestion.** Re-import is a no-op; a VERIFIED draw is never silently overwritten — conflicts are audited for manual correction.
4. **Order-insensitive canonicalization.** Groups stored and displayed ascending; "46 27 12 19 11" IS "11 12 19 27 46".
5. **Source attribution everywhere** + `sourceTimestamp` + `retrieved_at`; dataset provenance stamps (`n=…;last=…`) on every report.
6. **Scores are STATISTICAL BALANCE / DIVERSITY scores — never probabilities.** Win-chance wording is banned; `scoreMeaning` says so on every surface.
7. **No "due" numbers.** Absence is reported as absence only.
8. **Backtests are HISTORICAL SIMULATION**, no look-ahead, same-period comparisons, **random baseline mandatory**, no strategy declared better, no fabricated cost/winnings.
9. **Actual ticket results, backtests and sandbox data are three separate sections, never mixed.**
10. **Credentials never leak** — encrypted at rest, redacted from every message/log/error, HTTPS-only upstreams.
11. **Every output carries the DISCLAIMER**; every report carries an honestyNote stating all combinations have identical odds.
12. **History-backed generation refuses to run on an empty dataset** (`DATA UNAVAILABLE`) instead of degrading to random.
13. **Model versions are immutable** — historical results stay connected to the model that produced them.

---

## 11. Rebuild checklist (port to another stack)

1. **Schema** — create the 14 tables (§3); the only unique constraints that matter semantically: draws `(lottery_code, external_id)`, sync runs `execution_key`.
2. **Rules engine** — port the `LotteryRules` interface + `EuroMillionsRules` (§4.1). Keep rules as data.
3. **Provider port** — implement the 4-provider pattern (§4.2–4.4): real adapter, generic authorized feed, labeled sandbox, honest unconfigured default. For loteriasapi: X-API-Key header, `/api/v1` root, envelope unwrapping, flat-combination splitting with the safety table (§4.3), 365-day window walking newest-first, page-size retry at 5, unfinished-draw skipping, cents→euros money parsing, memoized `/latest`, key redaction.
4. **Validator** — the 5-check gate (§4.5).
5. **Statistics engine** — pure functions over `[{drawDate, main[], stars[]}]` ASC (§4.6).
6. **Analyzer + generator + diversification + system builder + backtester** — port the formulas exactly (§4.7–4.11); reuse the xorshift32 PRNG for reproducibility, or any seeded PRNG with the seed recorded in every report.
7. **Facade** — sync/import/status/generate/tickets/backtests/intelligence with the audit-event names (§4.12).
8. **Cron** — 8 jobs with the execution-key idempotency scheme (§4.13), 6-hourly sweep.
9. **API** — ~30 endpoints with the auth matrix and content negotiation (§6); implement the public-route allowlist + timing-safe CSRF mechanics exactly as in §8.1.
10. **UI** — hydrated state + tabbed dashboard + public statistics/system/backtests pages (§7); Next.js proxy layer if applicable (§7.3).
11. **Admin + platform integration** — API Management driver + Test button + Sync Now (audited, flash carries the first rejection reason); register the module in the agent-tool registry; wire nav, workspace widget, command center, chat assistants, feature inventory, diagnostics and deployment SQL (§8–§8.2).
12. **Tests** — 118 assertions' worth of invariants (§9); stub the transport, never hit the network.
13. **Adopt the honesty contract verbatim** (§10) — it's the feature's identity.
14. **Write the spec first** (or extract it as you go) and cite its numbered sections from every docblock — that's how this codebase keeps 13 classes coherent (§8.3).

**Sizing reference:** ~4,600 lines of PHP domain code (13 classes), ~700 lines of views, ~120 lines of dashboard JS, 14 tables, 30 endpoints, 8 cron jobs, 118 tests, plus a ~620-line Next.js widget layer.

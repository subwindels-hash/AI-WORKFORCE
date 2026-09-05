# EuroMillions / Lottery API integration — loteriasapi.com

WINDELS AI WORKFORCE ingests **real EuroMillions draw results** from
[loteriasapi.com](https://loteriasapi.com/en/euromillions-api) (data sourced from
SELAE, Spain's state lottery operator) through the existing provider-neutral
Lottery Intelligence pipeline.

---

## What was added

| Piece | Location |
|---|---|
| Vendor adapter | `application/libraries/AIWorkforce/Lottery/LoteriasApiProvider.php` |
| Provider selection | `application/libraries/AIWorkforce/Platform.php` |
| API Management driver `loteriasapi` + connectivity test | `application/libraries/AIWorkforce/ApiProviders.php` |
| Live CLI smoke test | `php index.php tools lottery-smoke` (`Tools::lottery_smoke`) |
| Dashboard + admin deep-link | `application/views/lottery/index.php` |
| Tests (42) | `tests/cases/109-lottery-loteriasapi-provider.php` |

The adapter implements the same `LotteryProvider` interface every other lottery
source uses, so statistics, combination generation, the system builder,
backtesting, tickets and cron jobs work unchanged.

---

## Vendor contract implemented

Base URL: `https://api.loteriasapi.com/api/v1` · Auth header: `X-API-Key: <key>`

> **The `/api` prefix is required.** The vendor's marketing pages (including
> <https://loteriasapi.com/en/euromillions-api>) advertise
> `https://api.loteriasapi.com/v1`, which answers
> `404 {"error":{"code":"NOT_FOUND","message":"Cannot GET /v1/results/..."}}`
> on **every** route — the API is served from `/api/v1`
> (see <https://loteriasapi.com/docs/getting-started>). A base URL without the
> prefix produced *"Endpoint not found (HTTP 404)"* in Admin → API Management.
> `LoteriasApiProvider::normalizeBaseUrl()` now rewrites a stored `/vN` base to
> `/api/vN`, so existing configurations heal themselves without an operator
> editing anything.

| Purpose | Endpoint | Adapter method |
|---|---|---|
| Latest result | `GET /results/{game}/latest` | `health()`, `jackpotInfo()`, `draws()` fallback |
| Historical range | `GET /results/{game}/range?from=&to=&page=&limit=` | `draws($from, $to, $limit)` (paged via `meta.hasNext`, chunked at 365 days, windows walked newest-first) |
| Draw by date | `GET /results/{game}/date/{yyyy-mm-dd}` | `drawById('2026-04-10')` |
| Draw by vendor id | resolved through a windowed `/range` query | `drawById('2026029')` / `drawById('2026/029')` |

Game code defaults to `euromillones` (the vendor's spelling); `euromillions` is
accepted and normalized. Other vendor slugs work too: `primitiva`, `bonoloto`,
`gordo`, `nacional`, `eurodreams`, `quiniela`, `quinigol`, `lototurf`,
`quintuple`. The marketing host `loteriasapi.com` is auto-rewritten to the API
host, so pasting the docs URL into Base URL still works.

### Payload mapping

Responses are wrapped in an envelope: `{ "success": true, "data": ..., "timestamp": ... }`
(an error is `{ "success": false, "error": { "code", "message", "statusCode" } }` —
a 200 carrying that envelope is treated as a failure, never as a draw).

```
vendor (live API)                            → WINDELS provider-neutral draw
drawId       "2026029"                       → externalId
drawDate     "2026-04-10"                    → drawDate
combination  [7,12,29,33,45]                 → main
resultData.estrellas [3,9]                   → stars
combination  [7,12,29,33,45,3,9]             → main [7,12,29,33,45] + stars [3,9]
  (whole winning line in one flat array)
jackpotFormatted "130.000.000,00 €"          → jackpot "130000000.00"
  (fallback: jackpot "13000000000" = integer cents → euros)
prizes[category 1].winners == 0              → rollover=true, winners="0"
updatedAt / envelope timestamp               → sourceTimestamp
feed identity                                → source ("loteriasapi.com (SELAE)")
status, dayOfWeek, prizes count              → extra.status, extra.dayOfWeek, extra.prizeTiers
```

The legacy snake_case payload (`draw_id`, `draw_date`, `numbers`, `stars`,
`el_millon`, `jackpot_next`, `meta.updated_at`) is still mapped, so both shapes
ingest correctly.

### The flat winning line (`combination` with 7 numbers)

The live feed also publishes the whole line — the 5 numbers followed by the 2
Lucky Stars — as a single `combination` array:

```json
{ "id": 1321502071, "game": { "slug": "euromillones" }, "drawDate": "2026-09-04",
  "status": "COMPLETED", "combination": [7, 12, 29, 33, 45, 3, 9],
  "resultData": { "estrellas": [3, 9] } }
```

Taken literally that is 7 main numbers, and every such draw was rejected as
`line must contain 5 main numbers (got 7)` — the sync reported
`0 imported, 2 rejected; 0 verified draws stored`. `LoteriasApiProvider` now
re-groups the flat list into 5 + 2 when the split is safe, and leaves it alone
when it is not:

| Situation | Applied? | Why |
|---|---|---|
| The row also names the stars (`resultData.estrellas`) and the flat list begins or ends with exactly those values | yes — `extra.numberLayout = flat-combination-split` (`-stars-first` when they lead) | the vendor itself confirms the split |
| No star field, the flat list is exactly 7 long, the first 5 values are unique and inside 1–50, the last 2 are unique and inside 1–12 | yes — `flat-combination-split` | the only layout a 5+2 game can have |
| Anything else (unknown game slug, out-of-range values, a repeated value inside a group, a list of another length) | **no** | the draw is left exactly as sent and rejected by `LotteryResultValidator`, so a genuinely broken line is audited, never reshaped into a valid-looking one |

Every re-grouped draw keeps the vendor's own number list in
`payload.extra.rawCombination` next to the interpretation in
`payload.extra.numberLayout`, so a stored draw can always be traced back to
what the feed actually sent.

To see which shape a feed is sending:

```bash
php index.php tools lottery-smoke --raw   # adds the vendor's unmapped latest row
```

The published jackpot is surfaced through `status().jackpot` on the lottery
dashboard and carries the note *"not used to infer future draw outcomes"*.

---

## Configuration

### Option A — Admin → API Management (recommended)

1. **Admin → API Providers → Add Provider**
2. Service: **Lottery / EuroMillions**, Driver: **LoteriasAPI (loteriasapi.com) — EuroMillions**
3. Fields:
   - **API Key (x-api-key)** — required; register (free tier available) at <https://loteriasapi.com/auth/register>
   - **Base URL** — optional, defaults to `https://api.loteriasapi.com/api/v1`
     (a stored `/v1` value is rewritten automatically)
   - **Game code** — optional, defaults to `euromillones`
   - **Timeout (seconds)** — optional, defaults to 8
4. Set **Enabled** + role **primary**, save, then press **Test** — a healthy
   key returns `Connected to LoteriasAPI (euromillones) — latest draw YYYY-MM-DD`.

Keys are stored encrypted and never appear in views, JS, audit logs or errors.

### Display name on the user dashboard

The upstream vendor is `loteriasapi.com`, but users only ever see the product
name. `LoteriasApiProvider::name()` and every dashboard-facing string
(health message, jackpot-source note, the lottery view's own labels) read
**`Windels API — EuroMillions`** / **`Windels API`**:

```
provider: loteriasapi.com (SELAE) · imported 42 verified draws
Windels API reachable — latest draw 2026-09-04
jackpot source: live Windels API response (observed 2026-09-04T22:00:00Z)
```

Admin → API Management, the CLI (`lottery-smoke`) and this document keep the
vendor name, because an operator configuring the feed needs to know which
upstream it is. The rename is a label only — the **source attribution stored
with every draw stays `loteriasapi.com (SELAE)`**, and the provider id stays
`loteriasapi`; test
`lottery dashboard names the product feed, never the upstream vendor` locks
both in.

### Option B — environment variables

```bash
WINDELS_LOTTERY_LOTERIASAPI_KEY=your_api_key_here      # required (also enables the provider)
WINDELS_LOTTERY_LOTERIASAPI_ENABLED=1                  # optional explicit switch
WINDELS_LOTTERY_LOTERIASAPI_BASE_URL=https://api.loteriasapi.com/api/v1
WINDELS_LOTTERY_LOTERIASAPI_GAME=euromillones
WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT=8
```

### Provider precedence (first configured wins)

```
1. LoteriasAPI            (loteriasapi.com — real results)
2. OfficialLotteryProvider (generic authorized feed)
3. SandboxLotteryProvider  (only with WINDELS_LOTTERY_SANDBOX=1 — clearly labeled simulation)
4. UnavailableLotteryProvider (honest DISABLED_NO_PROVIDER — nothing fabricated)
```

---

## Verifying it works

```bash
# 1. Live feed check (exit 0 = live draws received, 1 = unreachable, 2 = unconfigured)
php index.php tools lottery-smoke
php index.php tools lottery-smoke --raw   # also print the vendor's unmapped row

# 2. Pull draws into the historical database (validated + idempotent)
php index.php tools lottery-cron sync

# 3. Automatic scheduled sync (Lottery sweep, every 6 hours; sync is
#    idempotent once per day per lottery)
php index.php tools scheduler lottery

# 4. Automated tests
php index.php tools tests            # includes tests/cases/109-lottery-loteriasapi-provider.php (42 tests)
```

`lottery-smoke` output on a working key:

```json
{
  "provider": "loteriasapi",
  "health": { "state": "ONLINE", "message": "LoteriasAPI reachable — latest draw 2026-04-10" },
  "draws": [{ "externalId": "2026029", "drawDate": "2026-04-10",
              "main": [7,12,29,33,45], "stars": [3,9],
              "source": "loteriasapi.com (SELAE)" }],
  "jackpot": { "value": "130000000.00", "currency": "EUR" }
}
```

The UI surfaces the same state: **Lottery → status** shows the active provider
id/source, its health message, draws tracked, the latest verified draw and the
published next-draw jackpot. The page's admin link points at
`/admin/api/create?service=lottery`, which preselects the Lottery service so the
LoteriasAPI driver can be chosen directly.

---

## Safety behaviour (unchanged guarantees)

- **Nothing is fabricated.** A missing key, disabled provider, non-JSON body,
  HTTP error or network failure yields `UNCONFIGURED` / `DISABLED` / `OFFLINE`
  and **zero draws** — never invented numbers.
- **Every draw is validated** by `LotteryResultValidator` (counts, ranges,
  duplicates, date, source, source timestamp) before storage; failures are
  audited as `LOTTERY_DRAW_VALIDATION_FAILED` and never stored as official.
- **Imports are idempotent** and a `VERIFIED` draw is never silently
  overwritten — conflicts are audited for manual correction.
- **Credentials never leak.** The key is redacted from every health/error
  message, never persisted with draw payloads, and stored encrypted.
- **HTTPS only.** A plaintext `http://` base URL is refused as unconfigured.

## Error handling reference

| Upstream | Reported state | Operator message |
|---|---|---|
| 401 / 403 (or a 200 `success:false` `UNAUTHORIZED` body) | `OFFLINE` (test: `Invalid API key`) | authentication rejected |
| 404 | `OFFLINE` | base URL must be `https://api.loteriasapi.com/api/v1` (the `/api` prefix is required) |
| 400 | `OFFLINE` | the vendor's own message is quoted (which parameter failed) — game code, plan-capped page size (auto-retried at the plan floor) or date range > 365 days |
| 429 | `OFFLINE` | rate limited — the plan request quota is exhausted |
| no response | `OFFLINE` | network/SSL/firewall — allow outbound HTTPS to `api.loteriasapi.com` |
| non-JSON 200 | `OFFLINE` | invalid JSON payload |

## Rate limits & cost

Per <https://loteriasapi.com/planes> (checked 2026-09-05 — the marketing pages
still advertise "1,000 requests/month free", which the plans page contradicts):

| Plan | Requests | Max results / request | History depth |
|---|---|---|---|
| Free (0 €) | 50 (lifetime) | 5 | 7 days |
| Basic (9,90 €/mo) | 5,000 / month | 10 | 30 days |
| Pro (29,90 €/mo) | 20,000 / month | 50 | 1 year |
| Business (59,90 €/mo) | 50,000 / month | 75 | 50 years |

Two consequences the adapter handles explicitly (verified against the plans
page checked 2026-09-05):

- **Page size is plan-capped — and the vendor enforces it with HTTP 400, not
  silent truncation.** The adapter asks for 100 rows per page; when the plan
  rejects that (`VALIDATION_ERROR` naming `limit`), the page is retried once
  at the documented Free-tier floor (5), and the size the plan actually
  served (`meta.limit`) is adopted for every later page. A small plan still
  fills the history it is allowed to read instead of every history call
  failing and the sync degrading to a single `/latest` draw.
- **History depth is plan-capped.** The backfill walks `/range` windows
  **newest-first** and stops at the first window that adds nothing, so a
  7-day-history key costs two requests — not one request per year of archive
  the plan is not allowed to read. A deep backfill needs a paid plan; the
  adapter reports what it could read, never invented draws.

The daily `sync` cron job needs only a handful of calls per draw day
(EuroMillions draws Tuesday and Friday).


---

## Historical draw synchronization

`sync()` walks three sources in order of richness, so a plan without the
`/range` endpoint still builds a real history instead of a single draw:

```
1. GET /results/{game}/range?from=&to=&page=&limit=   (365-day windows, NEWEST
   first, paged; an oversized `limit` is retried at the plan floor)
2. GET /results/{game}?page=&limit=&sort=&order=      (paged history listing)
3. GET /results/{game}/latest                         (single draw, last resort)
```

Every returned draw is de-duplicated by `externalId|drawDate`, validated by
`LotteryResultValidator` (5 mains + 2 stars in range, valid date, source and
source timestamp present) and only then stored as `VERIFIED`. Re-importing the
same draw is a no-op; a stored VERIFIED draw is never silently overwritten.

A draw the feed has not finished (status `PENDING`/`SCHEDULED`, or a `/latest`
answer without numbers — the feed publishes the next draw as a placeholder on
draw day) is **never offered as history**: it would only produce a validation
rejection, not a stored draw. When a draw *is* rejected, the Admin flash and
`sync()` result carry the first validator reason through
`LotteryIntelligence::syncNotice()` — an operator never has to decode a bare
"1 rejected" through the audit log.

Each stored row records: draw date, the 5 main numbers, the 2 Lucky Stars, the
jackpot, the full prize breakdown (`payload.prizes`: category, label, winners,
amount) and the jackpot winner count where the feed supplies them.

The first cron sync backfills up to 520 draws (~5 years); later runs pull the
100-draw delta.

### One dataset for every consumer

`LotteryIntelligence::historicalDataset()` is the single accessor for the
stored VERIFIED draws. Statistics, the per-line analyzer, the **Strategy Lab**
(backtests + comparison) and the **AI combination generator** all read it, and
each report carries a `dataset` stamp:

```json
{ "source": "VERIFIED_HISTORICAL_DATABASE", "draws": 60,
  "from": "2026-01-11", "to": "2026-09-04", "datasetVersion": "n=60;last=2026-09-04" }
```

**Generate 5 AI lines** posts `{"mode":"HISTORICAL","count":5}`. History-backed
modes (`BALANCED`, `HISTORICAL`, `ANTI-POPULAR`, `DIVERSIFIED`) refuse to run on
an empty dataset with a `DATA UNAVAILABLE` error rather than degrading into
random numbers; `RANDOM` remains available as an explicitly labeled baseline.

### Dashboard sync panel

`status()` (and `/api/lottery/dashboard`) expose:

| Field | Meaning |
|---|---|
| `verifiedDraws` | VERIFIED draws currently in the database |
| `lastSuccessfulSync` | newest `last_success_at` in the provider health history |
| `lastSyncAttempt` | when the last sync ran, successful or not |
| `syncStatus` | `OK` / `DEGRADED` / `STALE` / `FAILED` / `NEVER_SYNCED` |
| `syncMessage` | human-readable explanation |
| `dataAvailable` | whether any verified draw is stored |
| `jackpotSource` | `PROVIDER_FEED` or `STORED_DRAW` — proves the amount is not hardcoded |
| `historicalDataset` | dataset provenance stamp (see above) |

### DATA UNAVAILABLE

If LoteriasAPI is configured but unreachable, the module does **not** pretend
no lottery data exists. The health failure is recorded, audited as
`LOTTERY_SYNC_FAILED`, `sync()` returns `status: "DATA UNAVAILABLE"`, and the
dashboard renders a red **DATA UNAVAILABLE** badge together with the last
successful sync. An unconfigured feed still reads as `NO_DATA` (nothing was
ever connected).

### Troubleshooting: `Sync complete: 0 imported, 0 unchanged, 1 rejected`

This exact report means the sync degraded all the way to the `/latest`
fallback and the single draw it served was not a playable, finished line.
Both halves are fixed in the adapter:

1. **Every history call failed** — the adapter asked for `limit=100` per page
   while the plan caps results per request (Free 5, Basic 10, Pro 50…) and
   the vendor answers an oversized `limit` with HTTP 400
   `VALIDATION_ERROR`. Page sizes are now retried at the plan floor and
   learned from `meta.limit`, so `/range` and the listing work on every plan.
2. **The one surviving draw was unfinished** — on draw day `/latest` can
   answer the *next* draw as a `PENDING` placeholder with no numbers, which
   the validator correctly refused. An unfinished draw is now skipped before
   validation (health names it: *"latest draw 2026-09-08 (pending)"*), and any
   genuine rejection carries its reason into the Admin flash.

If a sync still stores nothing on a Free key, the plan's 7-day history window
is the remaining limit: the newest draws import, deeper history needs a paid
plan (see the table above), and the flash/audit now say exactly that instead
of a bare count.


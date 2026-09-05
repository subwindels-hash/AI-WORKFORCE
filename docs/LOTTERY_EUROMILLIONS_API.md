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
| Tests (17) | `tests/cases/109-lottery-loteriasapi-provider.php` |

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
| Historical range | `GET /results/{game}/range?from=&to=&page=&limit=` | `draws($from, $to, $limit)` (paged via `meta.hasNext`, chunked at 365 days) |
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

# 2. Pull draws into the historical database (validated + idempotent)
php index.php tools lottery-cron sync

# 3. Automatic scheduled sync (Lottery sweep, every 6 hours; sync is
#    idempotent once per day per lottery)
php index.php tools scheduler lottery

# 4. Automated tests
php index.php tools tests            # includes tests/cases/109-lottery-loteriasapi-provider.php (17 tests)
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
| 400 | `OFFLINE` | game code or date range rejected (a `/range` query covers max 365 days) |
| 429 | `OFFLINE` | rate limited — free plan allows 1,000 requests/month |
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

Two consequences the adapter already handles, but operators should know:

- **Page size is plan-capped.** The adapter asks for 100 rows per `/range` page
  and follows `meta.hasNext`, so a plan that returns 5 or 10 rows per request
  still fills the requested history — it just costs more requests.
- **History depth is plan-capped.** On the free tier a `/range` query older than
  ~7 days returns nothing, so a deep backfill needs a paid plan; the adapter
  reports that as "no draws", never as invented ones.

The daily `sync` cron job needs only a handful of calls per draw day
(EuroMillions draws Tuesday and Friday).

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
| Tests (11) | `tests/cases/109-lottery-loteriasapi-provider.php` |

The adapter implements the same `LotteryProvider` interface every other lottery
source uses, so statistics, combination generation, the system builder,
backtesting, tickets and cron jobs work unchanged.

---

## Vendor contract implemented

Base URL: `https://api.loteriasapi.com/v1` · Auth header: `x-api-key: <key>`

| Purpose | Endpoint | Adapter method |
|---|---|---|
| Latest result | `GET /results/{game}/latest` | `health()`, `jackpotInfo()`, `draws()` fallback |
| Historical range | `GET /results/{game}?from=&to=` | `draws($from, $to, $limit)` |
| Single draw | `GET /results/{game}/{yyyy}/{nnn}` | `drawById('2026/029')` |

Game code defaults to `euromillones` (the vendor's spelling); `euromillions` is
accepted and normalized. The marketing host `loteriasapi.com` is auto-rewritten
to the API host, so pasting the docs URL into Base URL still works.

### Payload mapping

```
vendor                        → WINDELS provider-neutral draw
draw_id      "2026/029"       → externalId
draw_date    "2026-04-10"     → drawDate
numbers      [7,12,29,33,44]  → main
stars        [3,11]           → stars
prizes[cat 1].prize           → jackpot        (when no explicit jackpot field)
prizes[cat 1].winners == 0    → rollover=true, winners="0"
meta.updated_at               → sourceTimestamp
meta.source / feed identity   → source ("loteriasapi.com (SELAE)")
el_millon, jackpot_next       → extra.elMillon, extra.jackpotNext
```

`jackpot_next` is surfaced through `status().jackpot` on the lottery dashboard
and carries the note *"not used to infer future draw outcomes"*.

---

## Configuration

### Option A — Admin → API Management (recommended)

1. **Admin → API Providers → Add Provider**
2. Service: **Lottery / EuroMillions**, Driver: **LoteriasAPI (loteriasapi.com) — EuroMillions**
3. Fields:
   - **API Key (x-api-key)** — required; free key (1,000 req/month, no card) at <https://loteriasapi.com/auth/register>
   - **Base URL** — optional, defaults to `https://api.loteriasapi.com/v1`
   - **Game code** — optional, defaults to `euromillones`
   - **Timeout (seconds)** — optional, defaults to 8
4. Set **Enabled** + role **primary**, save, then press **Test** — a healthy
   key returns `Connected to LoteriasAPI (euromillones) — latest draw YYYY-MM-DD`.

Keys are stored encrypted and never appear in views, JS, audit logs or errors.

### Option B — environment variables

```bash
WINDELS_LOTTERY_LOTERIASAPI_KEY=your_api_key_here      # required (also enables the provider)
WINDELS_LOTTERY_LOTERIASAPI_ENABLED=1                  # optional explicit switch
WINDELS_LOTTERY_LOTERIASAPI_BASE_URL=https://api.loteriasapi.com/v1
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

# 3. Automated tests
php index.php tools tests            # includes tests/cases/109-lottery-loteriasapi-provider.php
```

`lottery-smoke` output on a working key:

```json
{
  "provider": "loteriasapi",
  "health": { "state": "ONLINE", "message": "LoteriasAPI reachable — latest draw 2026-04-10" },
  "draws": [{ "externalId": "2026/029", "drawDate": "2026-04-10",
              "main": [7,12,29,33,44], "stars": [3,11],
              "source": "loteriasapi.com (SELAE)" }],
  "jackpot": { "value": "130000000.00", "currency": "EUR" }
}
```

The UI surfaces the same state: **Lottery → status** shows the provider health
message, draws tracked, latest draw and the published next-draw jackpot.

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
| 401 / 403 | `OFFLINE` (test: `Invalid API key`) | authentication rejected |
| 404 | `OFFLINE` | check the game code and base URL |
| 429 | `OFFLINE` | rate limited — free plan allows 1,000 requests/month |
| no response | `OFFLINE` | network/SSL/firewall — allow outbound HTTPS to `api.loteriasapi.com` |
| non-JSON 200 | `OFFLINE` | invalid JSON payload |

## Rate limits & cost

Free plan: 1,000 requests/month, no credit card. Paid from €9/month for 10,000
requests. The `sync` cron job needs only a handful of calls per draw day
(EuroMillions draws Tuesday and Friday), so the free tier is sufficient for
normal operation.

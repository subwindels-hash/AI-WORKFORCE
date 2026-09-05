# Sports Intelligence providers

Sports Intelligence can use API-Football, TheSportsDB, and SportMonks directly. Configure credentials only in the server environment; never put them in browser JavaScript or a committed `.env` file.

## Configuration

```env
WINDELS_SPORTS_ENABLED=1
WINDELS_SPORTS_MODE=PRODUCTION
WINDELS_SPORTS_HTTP_TIMEOUT=10

WINDELS_API_FOOTBALL_KEY=...
WINDELS_API_FOOTBALL_BASE_URL=https://v3.football.api-sports.io

WINDELS_THESPORTSDB_KEY=3
WINDELS_THESPORTSDB_BASE_URL=https://www.thesportsdb.com/api/v1/json

WINDELS_SPORTMONKS_TOKEN=...
WINDELS_SPORTMONKS_BASE_URL=https://api.sportmonks.com/v3/football
```

A provider is registered only when its credential exists. Multiple configured providers are retained in registration order and the provider manager falls back to the next provider after authentication, rate-limit, network, or upstream data failures. Provider status is available through `GET /api_sports/providers` and the Sports Intelligence status endpoint.

## Capabilities

| Provider | Fixtures | Results | Odds | Top players |
|---|---:|---:|---:|---:|
| API-Football | Yes | Yes | Yes, via the odds endpoint | Yes (scorers / assists / yellow cards / red cards) |
| TheSportsDB | Yes | Yes | No bookmaker odds endpoint | No |
| SportMonks | Yes (incl. full-round bulk fetch) | Yes | Yes, per fixture and per round (odds add-on) | No |

All upstream responses are converted to the internal fixture, odds, and result shapes before they reach the normalizers and persistence layer. The provider adapters do not expose credentials to the frontend.

## API-Football notes

API-Football uses the `x-apisports-key` header. Its odds response is flattened into the internal market/selection/decimalOdds shape. The provider's plan and quota determine which leagues and odds markets are available.

**Pagination:** the v3 list endpoints paginate with the `page` parameter and
report `paging: {current, total}` in every response (fixtures: 50/page,
odds: 10/page). `fixtures()`, `odds()` and `leagues()` follow the pages
until `current >= total` (hard cap: 40 pages), so busy days, full-season
queries and fixtures with many bookmaker markets are not silently truncated
to the first page.

`ApiFootballProvider::topPlayers(leagueId, season, type)` wraps the four
top-player endpoints of the Players tag
(`/players/topscorers`, `/players/topassists`, `/players/topyellowcards`,
`/players/topredcards`). `league` and `season` are required upstream; the
response is normalized to ranked entries (`rank`, `playerId`, `name`,
`position`, `nationality`, `team`, `teamId`, `photo`) plus `value` — the
headline number for the ranked metric — and the full per-season `statistics`
profile as a keyed map. Unsupported `type` values throw instead of guessing a
path.

Exposed to the API as
`GET /api/sports/topplayers?league=61&season=2020&type=yellow_cards`
(`sports.view`). `provider` is optional — when omitted, the first configured
provider with top-player support serves the request (health-aware fallback),
and the response reports which provider answered. Results are cached per
(provider, league, season, type) for 15 minutes to protect the provider's
daily request quota (`?cache=0` bypasses).

## TheSportsDB notes

TheSportsDB uses the numeric key as part of the URL path (`/api/v1/json/{key}/...`). The adapter uses the soccer events-by-day endpoint and maps `idEvent`, `strHomeTeam`, `strAwayTeam`, `strLeague`, and `dateEvent`.

`searchTeams(name)` wraps `searchteams.php` (the endpoint from the
official API examples) and returns the internal team shape including the
vendor's `idAPIfootball` cross-reference. All TheSportsDB events also carry
`idAPIfootball`; the mappers surface it as `apiFootballId` on fixtures and
results so the same match/team can be matched across api-football and
TheSportsDB.

**Free tier (key "3") limits, verified live:** list endpoints are capped —
`all_leagues.php` returns 5 leagues, `eventsday.php` a partial day, and
`eventsseason.php` a partial season. The adapter never fabricates the
missing rows; treat free-tier syncs as a sample of the day. v2 (livescore,
full seasons) requires a premium key.

## SportMonks notes

SportMonks uses the token query parameter and the Football API v3 endpoints. Fixtures use participants, scores, and league includes, and are mapped using participant location (`home`/`away`). Ensure the token has access to the leagues and includes used by the deployment.

### Pagination

`SportMonksProvider::fixtures()` follows the v3 cursor pagination of
`GET /fixtures` (the endpoint defaults to 25 fixtures per page): the first
request sets `per_page=50`, subsequent requests pass `next_cursor` until
`has_more` is false, with a hard cap of 40 pages (2000 fixtures) so a
busy multi-league day is never silently truncated to the first page. The
round endpoints do not paginate.

### Round-based bulk fetch

`SportMonksProvider::round($roundExternalId)` retrieves an entire matchday in **one request** via `GET /rounds/{id}` with nested includes (`fixtures`, `fixtures.odds`, `fixtures.odds.market`, `fixtures.odds.bookmaker`, `fixtures.participants`, `fixtures.scores`, `fixtures.venue`, `fixtures.state`, `league`, `league.country`). It returns:

```php
[
    'roundId' => '396698', 'name' => '25', 'leagueId' => '648', 'league' => 'Serie A',
    'season' => '26763', 'startingAt' => '2026-08-29', 'endingAt' => '2026-08-31',
    'finished' => true,
    'fixtures' => [ /* internal fixture shape */ ],
    'odds'     => [ /* internal odds shape, + impliedProbability/updatedAt/winning when the vendor supplies them */ ],
    'results'  => [ /* internal result shape, from the embedded scores */ ],
]
```

- Round ids are resolved with `SportMonksProvider::seasonRounds($seasonId)` (`GET /rounds/seasons/{id}`).
- Round-embedded odds carry `original_label` (`1`/`Draw`/`2`) or a display `label` instead of a `selection` object; the mapper handles both shapes.
- Fixture status is resolved from the v3 `state` include object, then `state_id` (official v3 state table, e.g. `5` = FT), with the legacy v2 numeric `status` codes as a fallback.
- Consumer pattern through the fallback manager (rounds are a SportMonks-only capability, so guard with `method_exists`):

```php
$attempt = $providers->withFallback('round', function ($p) use ($roundId) {
    if (!method_exists($p, 'round')) throw new ProviderException('round endpoint not supported', ProviderException::DATA_ERROR);
    return $p->round($roundId);
});
```

The daily ticket engine uses this path: when the serving provider exposes
`round()` and the fetched fixtures carry a `roundId`, `DailyTicketService`
bulk-fetches each matchday's odds with one `round()` call per round
(`fetchRoundOdds`) and only falls back to the per-fixture `odds()` call for
fixtures the round fetch did not cover (no `roundId`, or a failed round
request). Odds already persisted for a match are never re-fetched.

### Round sync job

`SportsSyncService::syncRound($provider, $roundExternalId, $executionKey)`
bulk-syncs a whole matchday in one provider request — fixtures, odds and
results are persisted from the single `round()` payload (idempotent per
execution key, job type `ROUND`, audited as `SPORTS_ROUND_SYNC_*`). It is
exposed through the sync endpoint:

```
GET /api/sports/sync?provider=sportmonks&type=round&roundId=396698
```

Providers without a `round()` endpoint fail the job with an explicit
"does not support round sync" error instead of silently no-op'ing.

### Cron odds job on the round path

`sports_matches` stores `round_id` (nullable, indexed on `provider_id,
round_id` — new column added via the idempotent schema upgrade), populated
from the provider fixture payload at sync time. The cron `odds` job groups
the day's active matches by `(provider, round_id)`: every round on a
round-capable provider is synced once via `syncRound(..., ['results' => false])`
(one provider request per matchday, results skipped so verified results are
never re-written), and matches without a `round_id` — or on providers
without the round endpoint — keep the legacy per-fixture `syncOdds` call.

## Live smoke test

`php index.php tools sports-live [provider]` proves the configured providers
actually work against the real APIs, layer by layer: health/auth (+ quota
usage) → today's fixtures → odds for the first fixture → top players where
supported. Read-only; costs a handful of requests per provider. Exit codes:
0 all pass, 1 one or more failed, 2 no providers configured. When a provider
"doesn't work", this tells you exactly which layer fails (bad key,
unreachable host, plan restriction) instead of guessing.

## Safe operation

Provider data is untrusted input. The existing normalizers, data-quality gates, confidence checks, and ticket governance remain in the pipeline. Missing odds from providers without an odds feed must not be treated as fabricated odds; those predictions should be rejected or supplied by a separately licensed odds source.

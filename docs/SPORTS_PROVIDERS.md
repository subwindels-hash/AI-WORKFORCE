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

| Provider | Fixtures | Results | Odds |
|---|---:|---:|---:|
| API-Football | Yes | Yes | Yes, via the odds endpoint |
| TheSportsDB | Yes | Yes | No bookmaker odds endpoint |
| SportMonks | Yes | Yes | Requires the SportMonks odds add-on and a separate adapter |

All upstream responses are converted to the internal fixture, odds, and result shapes before they reach the normalizers and persistence layer. The provider adapters do not expose credentials to the frontend.

## API-Football notes

API-Football uses the `x-apisports-key` header. Its odds response is flattened into the internal market/selection/decimalOdds shape. The provider's plan and quota determine which leagues and odds markets are available.

## TheSportsDB notes

TheSportsDB uses the numeric key as part of the URL path (`/api/v1/json/{key}/...`). The adapter uses the soccer events-by-day endpoint and maps `idEvent`, `strHomeTeam`, `strAwayTeam`, `strLeague`, and `dateEvent`.

## SportMonks notes

SportMonks uses the token query parameter and the Football API v3 endpoints. Fixtures use participants, scores, and league includes, and are mapped using participant location (`home`/`away`). Ensure the token has access to the leagues and includes used by the deployment.

## Safe operation

Provider data is untrusted input. The existing normalizers, data-quality gates, confidence checks, and ticket governance remain in the pipeline. Missing odds from providers without an odds feed must not be treated as fabricated odds; those predictions should be rejected or supplied by a separately licensed odds source.

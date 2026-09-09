# WINDELS Football Intelligence

Football forecasting: provider fixtures and statistics in, versioned features and
a scoreline model in the middle, predictions and their *later* settlements out to
the console, the JSON API and the scheduled jobs.

It is a domain module inside the CodeIgniter 3 application (`AIWorkforce\Football`),
not a separate service. It reuses the platform's provider transport, RBAC, CSRF,
audit log and persistence conventions.

The rule the whole module is built around: **a number is either traceable to a
stored row or it is not shown.** An empty history, a missing provider or a
half-filled fixture is reported as such — never converted into a zero, a
placeholder percentage or a simulated match.

## What lives where

| Screen / surface | Owns |
| --- | --- |
| `/football` | today's prediction board, live matches, the data-feed panel, the refresh schedule, and the single 30-day performance panel |
| `/football/match/:id` | one fixture: features, data-quality components, prediction, raw vs calibrated confidence, live estimates, settlement row |
| `/football/models` | model lifecycle, stored metrics, calibration versions, per-version 30-day numbers, approve/activate forms |
| `/sports`, `/sports/tickets` | the odds prediction ticket engine only. They link to `/football` for football figures and never render them |

`views/sports/index.php` keeps the ticket panels it has always had; the football
performance panels were removed from it and from `views/workspace/index.php` so
each panel exists exactly once in the product.

## Module tour

`application/libraries/AIWorkforce/Football/`

| File | Responsibility |
| --- | --- |
| `FootballIntelligence.php` | facade and lazy service graph; the only object controllers and cron touch |
| `FootballConfiguration.php` | every knob, read from the environment (overridable per instance); exposes `describe()` for diagnostics |
| `RequestParams.php` | query-parameter reading for the read models and the console: absent ⇒ documented default, unusable ⇒ default **plus a note in the response**, out of range ⇒ clamped **and stated**; a mutation with an unreadable `date` refuses instead of refreshing another day |
| `DataState.php` | `DataState` (`DATA_UNAVAILABLE` / `LIMITED_DATA` / `AVAILABLE`) and `QualityBand` (`QUALIFIED` ≥ 70, `LIMITED` 50–69, `REJECTED` < 50) |
| `ProviderGateway.php` | provider selection, per-sweep request budget, daily quota, request spacing, persisted backoff |
| `ProviderSelector.php` | Auto / Smart scoring, named-provider plans, Multi-Provider class assignment (with the load-spread penalty) |
| `MatchIntelligenceService.php` | the Match Intelligence Engine: retrieve → normalize → deduplicate → canonical identity; also the provider catalogue and per-provider health |
| `CanonicalMatch.php` | canonical identity (`HOME_AWAY_YYYY-MM-DD`), team normalization and deterministic cross-provider matching |
| `FootballMatch.php` | the one normalized match model every layer downstream of a provider reads |
| `RegenerationPolicy.php` | when a stored prediction may be replaced, and the reason |
| `FixtureSyncService.php` | fixture/live/result sweeps, normalization, idempotent upserts, per-run sync log |
| `StatisticsCollector.php` | team statistics, league tables, head-to-head snapshots, in-match statistics |
| `FeatureBuilder.php` | normalized feature vector per fixture + `dataQualityScore` with weighted components and provenance |
| `ExpectedGoalsResolver.php` | home/away goal rates from venue splits, league baseline or team baseline; `NO_RATE` when none exist |
| `ScoreProbabilityModel.php` | Poisson / Dixon–Coles scoreline grid, outcome marginals, clean-sheet and failed-to-score rates, grid coverage |
| `OutcomePredictor.php` | probabilities, most likely scoreline, alternatives, confidence ceiling, evidence rows, reasons |
| `CalibrationService.php` | temperature scaling fitted from settled predictions only; `CALIBRATION_PENDING` otherwise |
| `ModelRegistry.php` | lifecycle states, transition guards, registration from the deployed fingerprint |
| `PredictionService.php` | prediction storage, the §output contract, the post-kickoff freeze; `predictMissing()` generates only matches that have no prediction, `predictDay()` sweeps a date in 50-match batches and reuses what exists |
| `MatchFeed.php` | the paginated feed: 50 matches per page, at most 50 NEW predictions per generation request, `match_id` de-duplication, competition and premium-league selection |
| `PredictionMarkets.php` | the odds-prediction markets: the catalogue, the evaluation of one market from the stored score distribution, and the odds a feed actually quoted |
| `PredictionBoard.php` | the daily board for one page: date-wide summary counts, confidence categories, match cards, pager |
| `LiveMatchService.php` | in-play board and `LIVE` estimate rows, never rewriting the pre-match row |
| `SettlementService.php` | grading on `FINISHED`, voiding on postponement, idempotent sweeps |
| `PerformanceService.php` | 30-day metrics from stored settlements, snapshots, per-model evaluation |
| `FootballDiagnostics.php` | the admin state block (`NOT_CONFIGURED`, `UNAVAILABLE`, `WAITING_FOR_DATA`, …) |
| `RefreshPolicy.php` | per-job cadence: interval, backoff, deferral, work-exists and budget gates |
| `FootballCronService.php` | the nine scheduled jobs, each under an idempotent execution key |

Persistence: `Persistence/FootballRepository.php` (interface),
`Persistence/FootballRepositoryDatabase.php` (CodeIgniter query builder), plus an
in-memory `FootballRepositoryStub` in `tests/framework.php`.

Schema: `application/database/football_intelligence.{mysql,sqlite}.sql`, mirrored in
`database/production.sql` — `football_providers`, `football_competitions`,
`football_teams`, `football_fixtures`, `football_team_statistics`,
`football_fixture_statistics`, `football_head_to_head`, `football_model_versions`,
`football_calibration_versions`, `football_match_predictions`,
`football_score_probabilities`, `football_prediction_settlements`,
`football_model_performance`, `football_provider_sync_logs`. A database created
before these tables existed gets them from `SchemaInstaller::ensure()` at boot; new
columns arrive through its idempotent ALTER list.

## Data flow

```
three providers (api-football / thesportsdb / sportmonks via SportsProviderManager)
  → ProviderSelector        Auto / Smart, a named feed, or Multi-Provider
  → MatchIntelligenceService  retrieve → normalize → deduplicate → canonical identity
  → FixtureSyncService      fixtures for today, the upcoming window, live, results
  → StatisticsCollector      team stats, league table, head-to-head, match stats
  → FeatureBuilder           features + dataQualityScore (0–100) + provenance
  → ExpectedGoalsResolver    goal rates (LEAGUE_BASELINE / TEAM_BASELINE / NO_RATE)
  → ScoreProbabilityModel    scoreline grid → outcome marginals
  → OutcomePredictor         result, predicted score, alternatives, confidence
  → CalibrationService       raw → calibrated (only from settled history)
  → PredictionService        stored row + §contract payload; frozen after kickoff
  → LiveMatchService         separate LIVE estimate rows
  → SettlementService        grade once, on FINISHED, from the provider's score
  → PerformanceService       30-day window over settled predictions
  → PredictionBoard / views  console, match page, models page, JSON API
```

Every arrow is a function call on stored rows. Nothing downstream invents what
upstream did not deliver.

## Honesty contract

| Situation | What is reported |
| --- | --- |
| no provider registered | sync `SKIPPED` / `FOOTBALL_PROVIDER_NOT_CONFIGURED`; diagnostics `Provider: NOT_CONFIGURED`, `Fixtures: UNAVAILABLE`, `Statistics: UNAVAILABLE`, `Prediction Engine: WAITING_FOR_DATA`; message: “Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.” |
| no fixtures stored for the date | board state `NO_FIXTURES_STORED` with the data-availability explanation |
| fixtures but no prediction rows yet | board state `NO_PREDICTIONS_STORED`, pointing at the analysis job |
| nothing clears the thresholds | board state `NONE_QUALIFIED`: “No fixtures currently satisfy the required prediction and data-quality thresholds.” Categories stay empty; confidence is never raised to fill them |
| a required field never arrived | `DATA_UNAVAILABLE` in that cell; a partially covered fixture is `LIMITED_DATA` |
| no settled predictions | “No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.” with Accuracy / Brier / ECE printed as `—` |
| calibration not supported by history | `CALIBRATION_PENDING`, confidence labelled `RAW`, and the raw share capped by the data-quality ceiling |
| model not yet ACTIVE | `MODEL_DRAFT` / `MODEL_APPROVED`-style label from `ModelRegistry::usable()`; a high-confidence badge requires an ACTIVE version |
| kickoff passed | `NO_PREDICTION` + `KICKOFF_PASSED`; the stored pre-match row (if any) is returned next to it, unmodified |

`MODEL_NOT_CALIBRATED` remains the odds prediction ticket engine's decision code
(`AIWorkforce\Sports`); the football module uses `CALIBRATION_PENDING` so the two
surfaces never disagree about what "uncalibrated" means.

Empty settlement history is **not** a prediction gate. Forecasting depends on the
provider being connected, statistics and form being stored, a model being loaded
and features clearing the data-quality bar. The performance panel is a report over
settled rows and says so, and `PerformanceService::report()` returns
`gatesPredictions => false` so the console can prove it.

## Gates and bands

* `dataQualityScore` = weighted sum of fixture completeness, recent-match coverage,
  team-stat coverage, league-stat coverage, head-to-head coverage, freshness and
  provider reliability. Weights are visible in `FeatureBuilder` and rendered per
  component on the match page.
* ≥ 70 `QUALIFIED` (may be published with confidence labels), 50–69 `LIMITED_DATA`
  (published, capped confidence, labelled), < 50 `REJECTED` (no prediction row).
* Head-to-head carries a low weight and shrinks further when the sample is small
  or old — it never dominates.
* Displayed confidence is the calibrated value when a calibration exists, and the
  raw share capped by `50 + 45 × (dq/100)` when it does not. It is never 100%.
* Board categories: `Highest Confidence` (≥ 80), `Strong Predictions` (75–79.99),
  `Standard Predictions` (70–74.99), `Limited Data` (below 70 — both data that did
  not clear the qualified threshold and qualified cards whose confidence sits
  under the lowest cut line). Every card sits in exactly one category: paging
  never makes an analyzed match disappear from the board.

## Model lifecycle

`DRAFT → TRAINED → VALIDATED → CALIBRATED → APPROVED → ACTIVE → RETIRED`

* A version is registered from the deployed fingerprint (algorithm, grid width, ρ,
  feature set, blend weight). Changing the scoring configuration registers a new
  version; it always enters as `DRAFT`.
* `TRAINED`/`VALIDATED` require a recorded validation sample, which only comes from
  measured settlements. `CALIBRATED` requires a stored calibration version.
  `APPROVED` requires measured accuracy, log loss, Brier and ECE **and** an actor:
  the approval is an operator action, recorded with `approved_by` / `approved_at`.
* `ACTIVE` is reachable only from `APPROVED`. Activating retires the previous
  ACTIVE version in the same action, with the reason stored.
* Nothing is ever hard-coded as approved: `ModelRegistry` refuses transitions whose
  evidence is missing, and the console shows the refusal reason.

Stored per version: `model_id`, `model_name`, `model_version`, `algorithm`,
`feature_version`, `training_dataset_version`, `created_at`, `trained_at`,
`validated_at`, `calibrated_at`, `validation_sample_size`, `accuracy`, `log_loss`,
`brier_score`, `ece`, `calibration_version_id`, `approved_by`, `approved_at`,
`activated_by`, `activated_at`, `status`, `lifecycle_history`.

## Predictions, live state and settlement

* A prediction row stores the outcome probabilities, the raw probabilities, the
  predicted score, expected total goals, the whole scoreline grid
  (`football_score_probabilities`), confidence with its basis, the data-quality
  score and band, model and calibration version ids, the feature snapshot, the
  evidence rows, the reason, `generated_at` and `status_at_prediction`.
* Predictions are frozen at kickoff: `PredictionService::frozenReason()` refuses a
  pre-match write once the match has started, and `savePrediction` refuses to
  overwrite a settled row. Postponed or cancelled fixtures are voided, not graded.
* Live football is stored as separate `prediction_kind = 'LIVE'` rows
  (`supersedes_prediction_id` points at the pre-match row for display only). The
  pre-match row is never rewritten by a live tick.
* Settlement writes a `football_prediction_settlements` row next to the prediction —
  actual score, actual result, the predicted values copied from the prediction,
  `correct_result`, `correct_exact_score`, Brier, log loss, absolute goal error,
  `result_source` and `settled_at` — and only then flips `settlement_state` to
  `SETTLED`. A prediction with no usable probabilities gets `NULL` grades rather
  than a guessed "wrong".
* The 30-day panel is `SELECT`-aggregated over settled rows: evaluated count,
  correct results, result accuracy, exact-score accuracy, average confidence,
  Brier, ECE, log loss, average data quality, average goal error, plus the
  per-model breakdown over the same window.

## Paging: 50 matches at a time

The module never asks for thousands of matches, and it never regenerates a match
because someone changed page.

```text
← Previous       Page 1 of 20       Next →
                 50 Matches
```

**Reading** pages through the *persisted* matches. `GET /api/football/matches`
slices the stored fixtures for a date (`ORDER BY kickoff_at, id`, so match 51 is
the same row on every call) and attaches the predictions that already exist. A
page read runs no model, makes no provider request and writes nothing.

**Generating** is a separate, bounded stage. The two stages, in order:

```text
1. fixtures stored for a date
       ↓  check every match_id against the database (one query per page)
       ↓  new matches only
       ↓  at most 50 sent for prediction generation
2. prediction engine
       ↓  features → quality gate → score model → calibration
       ↓  save prediction + model version + timestamp
       ↓  display the page from those stored rows
```

The rules, and where each one is enforced:

| Rule | Enforced by |
| --- | --- |
| 50 matches per page; `limit > 50` is clamped | `MatchFeed::MAX_PAGE_SIZE`, `MatchFeed::resolve()`, `RequestParams::int()` in the endpoints |
| at most 50 **new** predictions per generation request | `PredictionService::predictMissing()` — a `$limit` of 9,999 still yields 50, and the rest are reported `DEFERRED` |
| a match that already has a prediction is never regenerated | `PredictionService::existing()` → the stored row is returned; `predictDay()` counts it as `skipped` |
| moving between pages costs nothing | the read path touches only `listFixtures`/`listPredictionsForFixtures`/`countFixtures` |
| one prediction per match | `UNIQUE(fixture_id, prediction_kind, model_version_id)` in every schema, plus `UNIQUE(provider_id, external_id)` on fixtures |

**`match_id`.** A match is identified by `providerCode:externalId`
(`MatchFeed::matchId()`), which the provider guarantees unique and
`football_fixtures` enforces. A prediction is distinguished from a later refresh
of the same match by three things together — the match, the prediction kind
(pre-match or live), and `model_version_id` — plus `generated_at` as
`predictionDate`. Every match row in the feed carries `matchId`,
`predictionSource` (`STORED` / `GENERATED` / `DEFERRED` / `REFUSED` / `FAILED`)
and, when there is no prediction, a `predictionRefusal` naming the reason.

**Worked example** (120 matches on a date, 3 pages):

```text
Page 1 → matches 1–50    generate these 50 → save      (50 stored, 70 outstanding)
Page 2 → matches 51–100  generate these 50 → save      (100 stored, 20 outstanding)
Page 3 → matches 101–120 generate these 20 → save      (120 stored, 0 outstanding)
Back to page 1 → the 50 rows are read back. Nothing is regenerated.
```

The scheduled `predict` job fills a partly-predicted date the same way: it
sweeps in 50-match batches (up to its `analysisLimit` budget) and skips every
match that already has a row, so re-running it costs nothing for work already
done.

## Selecting a competition, a premium league and a market

The console and the API are driven by one flow:

```text
Football Intelligence
        ↓
Select Competition            (the leagues the provider actually sent)
        ↓
Select Premium League         (the featured competition — default: English Premier League)
        ↓
Select Odds Prediction        (the market Football Intelligence answers in)
        ↓
Select date
        ↓
Generate predictions          (at most 50 NEW matches, inside the selected competition)
        ↓
Page 1 → Next → Page 2        (stored rows; nothing is regenerated)
```

**Competitions are data, not a constant.** `GET /api/football/competitions` lists
the competitions stored for a date — `football_competitions` rows the sync wrote
from the provider payload — each with how many matches it has on that date. A
league with no stored match is never offered, and a provider that cannot list
leagues is never filled in with a guessed catalogue.

**The premium league is the featured competition**, configured with
`WINDELS_FOOTBALL_PREMIUM_COMPETITION` (name, or
`WINDELS_FOOTBALL_PREMIUM_COMPETITION_ID` for an exact provider id). It defaults
to the **English Premier League**. Selecting it narrows generation to that
league; the day's other competitions are left untouched. If the configured
premium league has no match on the date, the competition with the most matches is
featured instead and the payload says so (`source: MOST_MATCHES_ON_DATE`) rather
than silently featuring nothing.

A competition that is named but not stored is **reported, not widened**: the page
is narrowed to it (and is therefore empty) and the note names the competitions
that are available. Widening it to "every league" would answer a different
question than the one that was asked.

**A market is a view, not a prediction.** `PredictionMarkets` evaluates the
selected market from what is already stored:

| Market | Answered from |
| --- | --- |
| Match Winner (1X2), Double Chance, Draw No Bet | the 1X2 row stored with the prediction |
| Over/Under 0.5–3.5, BTTS, BTTS + Over 2.5, Correct Score, Asian Handicap | sums over the score distribution |
| First Half Winner, First Half Over/Under | the same score model on `WINDELS_FOOTBALL_FIRST_HALF_SHARE` of the goal expectancy |
| Half Time / Full Time, Corners, Cards | nothing — `DATA_UNAVAILABLE` unless the odds provider priced them |

The displayed grid is deliberately truncated (scorelines below 1% are not
persisted), and a truncated grid is unfit for summing: draws, handicaps and
both-teams-to-score are spread across many small cells, so the tail would be
under-reported. A market that needs sums is therefore evaluated over the
**complete** distribution recomputed from the expected goals stored with the
prediction — the same model on the same inputs, with the cut removed — and the
`basis` of every market block says which one was used
(`SCORE_GRID`, `SCORE_MODEL_FROM_STORED_EXPECTED_GOALS`, `FIRST_HALF_SHARE_0.45`).

**Odds are quoted, never derived.** A price appears only when the connected odds
feed has a row for that match, market, selection and line — matched on the one
identity both modules share, the provider's own match id. When it has one, the
block carries `odds`, `impliedProbability` (`1/odds`) and `edge` (model minus
implied). When it does not, the field is `DATA_UNAVAILABLE` with a reason; the
model's own probability is still shown, because that one is computed from stored
data. An "Over 3.5" price is never used as the price of "Over 2.5".

**Changing the market cannot regenerate a match.** Every market reads the same
stored prediction and the same score grid, so switching from *Match Winner* to
*Over 2.5 Goals* and back writes nothing and costs no provider call. The
50-match ceiling is likewise unaffected by a selection: `limit=500` with a
competition chosen is still 50 new predictions, inside that competition.

The market list is exposed by `GET /api/football/markets`, and the page payload
carries the same `market.available` array, with `state` and `oddsAvailable` per
market so the console can mark the ones that have a real price behind them.

## Multi-provider: three feeds, one canonical match

Three providers can be connected at once, and any of them — or all of them — can
answer a request. The rule the module is built on:

> Three providers, one normalized football intelligence system, one canonical
> match record, one prediction record, maximum 50 new predictions per
> generation, and zero unnecessary regeneration.

### The layers

```
provider adapters (ApiFootball · TheSportsDB · SportMonks)
   → ProviderSelector          which feed answers which part of the request
   → SportsDataNormalizer      every row into the one FootballMatch model
   → MatchIntelligenceService  deduplicate + resolve canonical identity
   → prediction engine         (never knows which provider answered)
   → database / cache
   → 50 matches per page
```

The prediction engine never sees a provider-specific shape. Every adapter's
output is normalized into `FootballMatch` — id, provider(s), competition,
season, home/away team (id, name, logo), kickoff, status, venue, odds,
statistics, injuries, lineups, form, H2H — before anything downstream reads it.

### Selecting a provider

`provider` accepts `AUTO` (default), `api-football`, `thesportsdb`,
`sportmonks`, or `MULTI`:

| Mode | Behaviour |
| --- | --- |
| `AUTO` | One provider serves everything, chosen by a score over what is observable: health, circuit-breaker and backoff state, quota head-room, reliability, response time, competition coverage, odds availability and statistics availability. The score and its components are returned in `selection.scores`, so the choice can be argued with. |
| named | That provider serves everything it can; a data class it has no data for falls back to another feed rather than coming back empty. An unknown name is reported and Auto is used — never silently substituted. |
| `MULTI` | One provider per data class: fixtures from the feed that covers the competition, statistics and odds from the feeds that have them, metadata from whichever supplies it. A feed already holding a class is penalised, so the work spreads across feeds instead of spending one provider's whole quota. |

No mode calls a provider that is offline, in backoff or out of quota. `MULTI`
is only offered when more than one feed is connected — with one feed there is
nothing to combine.

### Canonical identity (duplicate prevention)

Every match gets one internal identity built from the teams and the kickoff
date, whichever feed it arrived from:

```
MANCHESTER_UNITED_ARSENAL_2026-09-12
```

A provider row is resolved to that identity in this order, and the rule that
fired is recorded:

1. the provider's own match id, when it is already known (`PROVIDER_ID`);
2. normalized home team + normalized away team + kickoff date (`TEAMS_AND_KICKOFF`);
3. fuzzy team names on the same date (`FUZZY_TEAM_NAMES` — "Man Utd" ↔
   "Manchester United", threshold 0.7, deterministic);
4. otherwise it is a new fixture (`NEW_FIXTURE`).

A different date is a different fixture, however similar the names: the same
two clubs meet again in the reverse fixture. Two tables back this:

- `football_provider_matches` — one row per (provider, provider match id),
  pointing at the internal match, with `matched_by` and `confidence`;
- `football_competition_mapping` — the internal competition a provider's league
  id stands for, with the deployment's own `tier`, `premium` and `active`
  classification. **Premium is an application-level label, not a provider league
  id.** The mapping is written as fixtures are read: each provider row's league
  id and name are recorded against the internal competition, so the competition
  dropdown is populated from the provider that was selected. A row the provider
  gave no competition id for is not mapped — a league guessed from a name alone
  would merge two competitions.

The same match from three feeds is one match, one prediction and — in
`MULTI` — one fixture request per contributing feed, never one per match.

### Premium leagues

`WINDELS_FOOTBALL_PREMIUM_COMPETITIONS` is a comma-separated list of leagues
this deployment classifies as premium — names or provider competition ids:

```
WINDELS_FOOTBALL_PREMIUM_COMPETITIONS=Premier League, UEFA Champions League, La Liga, Serie A, Bundesliga, Ligue 1
```

A league is premium because it was classified, and names match loosely, so one
feed's "English Premier League" and another's "Premier League" are the same
premium competition — and the same internal competition id. The Premium League
selector offers every premium league that has a match on the date, with the
featured one marked; a league that was not classified is never offered as
premium, and a configured premium league with no match on the date is
substituted by the featured competition with that stated, never silently.

### API calls: database first

The order is cache → database → dedupe → engine, in that order:

1. A window that is already **stored and still fresh** — the newest fixture row
   is inside `WINDELS_FOOTBALL_MAX_AGE_FIXTURES` (default 24h) — is served from
   those rows. No provider is called, the canonical identities are the same, and
   the response says `calls.source: STORED_FIXTURES`.
2. Stored rows older than that window are stale, not a cache: the feed is read.
   `refresh=1` reads the feed whatever the stored rows say.
3. What is read is cached in `football_fixtures` as it arrived, so the next
   request for the same window is answered from the database.

Per-match data is opt-in, because it costs one call per match rather than one
per page: `?with=lineups` collects confirmed lineups from whichever feed has
them (with the same fallback as odds), bounded by the request budget, and each
match records whether it got one — a match with no confirmed lineup says so
rather than showing eleven names nobody announced.

### Odds fallback

When the selected provider has no price for a competition or market, another
provider is tried, and the result names the source: `source: sportmonks`,
`attempted: [api-football, sportmonks]`. If no provider quoted the market the
odds are `DATA_UNAVAILABLE` — a price is never derived from a probability.

### Provider health

`GET /api/football/providers/health` reports per provider: status, response
time, last successful request, last error, rate-limit state (`OK` /
`EXHAUSTED` / `BACKOFF` / `UNKNOWN`), reliability, capabilities, whether odds
and statistics are available, how many competitions are mapped, and — in
`missingData` — what the provider cannot supply at all. Automatic fallback uses
the same information: a provider that is offline or in backoff is skipped, not
retried.

### The prediction result

Every prediction result carries what is needed to judge it:

```jsonc
{
  "matchId": "api-football:1201",
  "selection": "OVER", "selectionLabel": "Over 2.5",
  "probability": 0.531, "odds": 1.95, "impliedProbability": 0.513, "edge": 0.018,
  "riskLevel": "LOW",            // LOW | MEDIUM | HIGH, with riskFactors
  "dataSources": [               // every provider behind the match
    { "provider": "api-football", "providerMatchId": "1201", "matchedBy": "PROVIDER_ID" },
    { "provider": "sportmonks",   "providerMatchId": "88012", "matchedBy": "FUZZY_TEAM_NAMES" }
  ],
  "modelVersion": 7,
  "generatedAt": "2026-09-09T11:04:00+00:00",
  "expiresAt": "2026-09-12T14:00:00+00:00",   // frozen at kickoff
  "expiryBasis": "FROZEN_AT_KICKOFF"
}
```

`riskLevel` is a reading of the inputs, not a mood: it weighs the data-quality
band, how much of the score distribution the market could see, the selection's
probability, the price, whether the model sees any edge, and whether the market
rests on a stated assumption. `riskFactors` names whichever of those counted.

### Regeneration

A prediction is reused unless a stated reason justifies replacing it, and the
reason is reported with the match:

| Reason | Meaning |
| --- | --- |
| `MODEL_VERSION_CHANGED` | the stored prediction came from another model version |
| `PREDICTION_EXPIRED` | older than `WINDELS_FOOTBALL_PREDICTION_TTL_SECONDS` (default 6h) |
| `SIGNIFICANT_ODDS_MOVEMENT` | the price moved ≥ `WINDELS_FOOTBALL_ODDS_MOVEMENT_THRESHOLD` (default 0.05) in implied probability |
| `CONFIRMED_LINEUP_CHANGE` | a confirmed lineup change was recorded |
| `MAJOR_INJURY_OR_NEWS` | a major injury or team-news update was recorded |
| `MATCH_STATUS_CHANGE` | the fixture is postponed, cancelled, suspended or live |
| `NEW_STATISTICS_AVAILABLE` | the provider sent new data for the fixture after the prediction |

Changing page, reloading, changing market or re-sweeping a date is **not** a
reason: the default is reuse, and an unknown signal is never treated as
permission. Once a match has kicked off the prediction is frozen
(`FROZEN_AT_KICKOFF`) — no signal reopens it; it is settled instead.

## Output contract

`GET /api/football/matches/:id/prediction` and the console share this shape:

```json
{
  "fixtureId": 1234,
  "homeTeam": "Manchester City",
  "awayTeam": "Everton",
  "status": "SCHEDULED",
  "prediction": {
    "result": "HOME",
    "predictedScore": { "home": 2, "away": 0 },
    "probabilities": { "home": 0.71, "draw": 0.19, "away": 0.10 },
    "confidence": 71.4
  },
  "dataQuality": { "score": 94, "status": "QUALIFIED" },
  "model": { "version": "v1+9f3c2a71", "calibrationVersion": "CALIBRATION_PENDING" },
  "reason": "Manchester City Win — 71% (raw 0.77, softened by …)",
  "generatedAt": "2026-09-05T18:04:11+00:00"
}
```

Values come from the stored row, so what an endpoint returns always matches what
settlement will later be judged against. `model.calibrationVersion` is the literal
`CALIBRATION_PENDING` until an operator-approved calibration exists.

## Endpoints

Read (`sports.view`; the two health endpoints are public and contain no
credentials). Every parameterised read answers with a `request` block — the filter,
limit or window it actually applied, plus `notes` explaining any parameter it could
not use — so a typo'd query string is never mistaken for a successful narrow request:

```
GET /api/football/fixtures            ?date= | ?from=&to=&limit=&status=&competition=&team=
GET /api/football/fixtures/today
GET /api/football/fixtures/tomorrow
GET /api/football/fixtures/live
GET /api/football/matches                 ?date=&page=1&limit=50&competition=&market=&line=   the paginated feed (50 per page)
GET /api/football/competitions          ?date=&providerId=   competitions stored for the date, premium marked
GET /api/football/markets               ?date=   the odds-prediction markets and which can be answered
GET /api/football/matches/:id            fixture + statistics + H2H as stored
GET /api/football/matches/:id/analysis
GET /api/football/matches/:id/prediction
GET /api/football/predictions            ?date=&page=1&limit=50&competition=&market=&line=   the paged prediction feed
GET /api/football/predictions/:matchId   one prediction by match id (`provider:externalId`)
GET /api/football/predictions/today      ?date=&refresh=1
GET /api/football/predictions/history  ?limit=&modelVersionId=
GET /api/football/performance            ?days=30&modelVersionId=
GET /api/football/models                  lifecycle summary + every stored version
GET /api/football/models/active
GET /api/football/calibrations           ?modelVersionId= (defaults to the model in use)
GET /api/football/provider/status
GET /api/football/status
GET /api/football/dashboard            ?date=&refresh=
GET /api/football/providers            the provider catalogue behind the Data Provider selector
GET /api/football/providers/health     per-provider health: status, response time, rate limit, coverage, odds, what is missing
GET /api/football/matches/fetch        ?provider=AUTO&competition=&date=&dateFrom=&dateTo=&limit=50&with=lineups&refresh=1
```

Mutations require the native session plus the CSRF token (header or body field),
then the capability named. They take a JSON body:

```
POST /api/football/matches/generate  sports.manage   {"date":"2026-09-05","page":2,"competition":"39","market":"OVER_2_5"}  (max 50 NEW predictions)
POST /api/football/sync            sports.manage   {"date":"2026-09-05","provider":"apifootball"}
POST /api/football/sync/live       sports.manage   {}
POST /api/football/settle          sports.settle   {"fixtureId":1234}  (omit to sweep)
POST /api/football/calibrate       sports.manage   {"modelVersionId":7}  (omit for the ACTIVE version)
POST /api/football/jobs/:job/run   sports.manage   bypasses cadence; :job is one of the nine below
POST /api/admin/football/models/:id/approve   sports.approve
POST /api/admin/football/models/:id/activate  sports.approve
```

Console forms post to `/football/sync`, `/football/predict`, `/football/settle`,
`/football/calibrate` and `/football/models/:id/decide` (with a hidden
`activate=0|1`); each carries the session CSRF token, and each is rendered only
when the signed-in identity holds the capability — otherwise the page shows a
disabled control naming the permission to ask for.

Football reuses the existing `sports.*` capabilities instead of inventing a new
permission set: `sports.view` reads, `sports.manage` refreshes and configures,
`sports.approve` approves or activates a model version, `sports.settle` settles. The
seeded **Sports administrator** role (`sports_admin`, `tools/rbac.php`) already carries
all four, so a fresh install needs no new grants — and no screen or message may name a
role or permission the seed does not define, which `tests/cases/105` checks by reading
that catalogue.

### The paged feed, page by page

```jsonc
// GET /api/football/matches?date=2026-09-05&page=2&limit=50
{
  "state": "AVAILABLE",
  "date": "2026-09-05",
  "matches": [
    {
      "matchId": "apifootball:1204831",   // the unique match identity
      "fixtureId": 318,                   // database id
      "homeTeam": "Manchester City", "awayTeam": "Everton",
      "kickoff": "2026-09-05T14:00:00+00:00", "status": "SCHEDULED",
      "analysisState": "ANALYZED",
      "predictionSource": "STORED",       // STORED | GENERATED | DEFERRED | REFUSED | FAILED
      "prediction": {
        "predictionId": "fpx-9f3c2a71…", "result": "HOME",
        "predictedScore": { "home": 2, "away": 0, "label": "2–0" },
        "probabilities": { "home": 0.71, "draw": 0.19, "away": 0.10 },
        "confidence": 71.4, "band": "QUALIFIED",
        "modelVersionId": 7, "predictionDate": "2026-09-05",
        "generatedAt": "2026-09-05T09:14:02+00:00"
      },
      "predictionRefusal": null            // { "code": "NOT_GENERATED", "reason": "…" } when there is none
    }
    // … 49 more
  ],
  "pagination": {
    "page": 2, "limit": 50, "maxLimit": 50,
    "totalMatches": 137, "totalPages": 3, "returned": 50,
    "from": 51, "to": 100,
    "hasPrevious": true, "hasNext": true, "previousPage": 1, "nextPage": 3
  },
  "generation": {
    "requested": false, "batchLimit": 50,
    "generated": 0,            // new predictions written by THIS request
    "reused": 50,              // served from storage
    "remainingOnDate": 87,     // matches on the date with no prediction yet
    "predictionModelVersion": "v1+9f3c2a71", "predictionDate": "2026-09-05"
  }
}
```

`?generate=1` on the read endpoint (or `POST /api/football/matches/generate`)
fills in the page's missing predictions first; `generation.requested` and
`generation.generated` then report what the request actually produced. A clamp
(`limit=1000` → 50) or a fallback (`page=abc` → 1) is always listed in
`request.notes`, so a caller can see that the response is not the request.

## Refresh model

There is no fixed five-minute loop. `RefreshPolicy::evaluate($job)` gates every
job on all of:

1. a provider is configured (`PROVIDER_NOT_CONFIGURED` otherwise),
2. the module is enabled (`MODULE_DISABLED`) — housekeeping excepted,
3. the provider is not in backoff (`PROVIDER_BACKOFF`),
4. the last run did not ask to be deferred (`PROVIDER_DEFERRED`),
5. the job's own interval has elapsed (`CADENCE`),
6. there is work for it (`NO_WORK`): a live match or a fixture starting within the
   hour, a finished fixture without a result, an open prediction on a finished
   fixture, a date without a prediction yet, settled rows to measure,
7. the job still has request budget (`REQUEST_BUDGET_EXHAUSTED`).

| Job | Bucket | Default interval | Request budget |
| --- | --- | --- | --- |
| `football-fixtures` | fixtures | 6 h | 4 |
| `football-upcoming` | upcoming | 1 h | 8 |
| `football-live` | live | 90 s | 6 |
| `football-results` | results | 15 m | 12 |
| `football-statistics` | statistics | 12 h | 20 |
| `football-predict` | predict | 30 m | 0 (database only) |
| `football-settle` | settle | 15 m | 0 |
| `football-performance` | performance | 1 h | 0 |
| `football-cleanup` | cleanup | 24 h | 0 |

`RefreshPolicy::schedule()` returns every job with its verdict, the due list and
`nextWakeAt`, so a background runner can idle until something is actually
eligible. `forFixture()` is the per-match view of the same rule: `SCHEDULED`,
`PRE_KICKOFF` (inside the hour), `LIVE`, `KICKOFF_PASSED`, `PENDING_SETTLEMENT`,
`SETTLED`, `INACTIVE`.

Quota protection is layered: a per-sweep request budget, a minimum spacing between
requests (`WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS`), the provider's own reported
daily limit (`limitDaily` / `requestsToday`, stored on `football_providers`), and
`WINDELS_FOOTBALL_DAILY_REQUEST_CEILING` as the fallback for feeds that do not
report one. The counter is stamped with the day it was written
(`requests_used_date`), so yesterday's usage never spends today's quota. Failures
grow a persisted backoff exponentially up to fifteen minutes, and an explicit
`retryAfterSeconds` from the provider is obeyed exactly.

Jobs run through `FootballCronService`, which gives each one an execution key
(`FIXTURES:2026-09-05T18`, `SETTLE:2026-09-05T18…`) that the sync-log table accepts
only once — an overlapping or repeated tick cannot duplicate fixtures,
predictions, settlements or snapshots. Data writes are idempotent as well:
fixtures upsert by provider + external id, a finished fixture never reverts to a
partial row, predictions have deterministic ids, settlements insert once.

```
php index.php tools football-cron              # every job that is due
php index.php tools football-cron live --force # one job, operator-triggered
```

The platform scheduler calls the same entry point from the `football` sweep group,
ticked every minute. The tick is *not* a polling rate — it is only how often the
module is asked "is anything due?". Setting it at or below the fastest bucket
(`live`, 90 s by default) is what makes that cadence reachable at all; anything
slower would silently floor every job's interval to the sweep's own. An idle tick
costs a handful of indexed reads and no provider request, because each job is
gated by the seven checks above before it touches the feed. A sweep in which every
job reported "nothing due" emits no audit event at all (`FOOTBALL_CRON_RUN` is
written only when a job actually ran); a job that ran records its own row in
`football_provider_sync_logs`, and a failure emits `FOOTBALL_JOB_FAILED`.

A failure's text is displayed — in the sync log's error list, in the operator's
flash message and in `football_providers.last_error` — and an HTTP client typically
quotes the URL it called, which on these feeds carries the key as a query parameter.
`ProviderGateway::redactSecrets()` therefore runs over provider messages before they
are stored or rendered: the status, the endpoint and the retry advice survive, the
credential does not. Credentials are never written to a column in the first place —
`football_providers` has no field that could hold one.

## Configuration

```
WINDELS_FOOTBALL_ENABLED=true                module switch; false = no provider calls, stored data still readable
WINDELS_FOOTBALL_REFRESH_<BUCKET>=seconds    per-bucket cadence (fixtures, upcoming, live, results, statistics, predict, settle, performance, cleanup)
WINDELS_FOOTBALL_BUDGET_<JOB>=n              requests one sweep may spend; 0 = database-only, -1 = unbounded (operator sync)
WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS=250  spacing between provider requests
WINDELS_FOOTBALL_DAILY_REQUEST_CEILING=0     fallback daily ceiling when a feed reports none
WINDELS_FOOTBALL_ANALYSIS_LIMIT=120          fixtures one analysis pass may evaluate (1..500)
WINDELS_FOOTBALL_MATCH_PAGE_SIZE=50          matches per page and per generation request (1..50, hard-capped in code)
WINDELS_FOOTBALL_PREMIUM_COMPETITION=English Premier League   the featured ("Premium") league the console offers first
WINDELS_FOOTBALL_PREMIUM_COMPETITION_ID=39   optional: pin it to a provider competition id instead of matching the name
WINDELS_FOOTBALL_PREMIUM_COMPETITIONS=English Premier League  comma-separated list of leagues classified premium (names or
                                             provider competition ids); matches loosely, so "English Premier League"
                                             and "Premier League" are one premium competition
WINDELS_FOOTBALL_DEFAULT_MARKET=MATCH_WINNER the market a request is answered in when it names none
WINDELS_FOOTBALL_FIRST_HALF_SHARE=0.45       goal expectancy attributed to the first half (0.20..0.80); named in the market basis
WINDELS_FOOTBALL_MAX_GOALS=8                 scoreline grid width per team (4..12)
WINDELS_FOOTBALL_DC_RHO=-0.06                Dixon–Coles low-score adjustment (±0.25, 0 = plain Poisson)
WINDELS_FOOTBALL_MARKET_BLEND=0.35           weight for market-implied probabilities (0 disables)
WINDELS_FOOTBALL_SCORE_ROW_MIN=0.01          smallest scoreline row worth storing (0.001..0.05)
WINDELS_FOOTBALL_H2H_MAX_WEIGHT=0.12         largest contribution head-to-head may ever make (0..0.25)
WINDELS_FOOTBALL_MAX_AGE_<BUCKET>=seconds    freshness window, one per bucket the module reads:
                                             fixtures / results / live feed the data-quality
                                             freshness component; h2h (1095 d) is the age after
                                             which a head-to-head sample's weight is halved
WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES=50  settled rows required to fit a calibration (floor: 10)
WINDELS_FOOTBALL_PREDICTION_TTL_SECONDS=21600     how long a prediction stays valid before fresh data may replace it (0 = never expires)
WINDELS_FOOTBALL_ODDS_MOVEMENT_THRESHOLD=0.05     implied-probability move that justifies regenerating a prediction (0..1)
DEMO_MODE=false                              platform demo switch; also readable as WINDELS_FOOTBALL_DEMO_MODE
```

Provider credentials stay environment-only (`WINDELS_SPORTMONKS_TOKEN`, the
api-football / thesportsdb keys the sports module already uses). Production defaults
to `DEMO_MODE=false`, and the flag is a **permission, not a data source**: with the
flag on and no connected feed the module still refuses to produce a fixture, and
diagnostics list `DEMO_MODE_ENABLED` as a warning rather than quietly substituting
simulated rows.

## Testing

```
php index.php tools tests            # includes tests/cases/101…107, 124- and 125-football-*.php
```

The football cases run on `tests/football_support.php`: an in-memory repository, a
fake provider serving deterministic fixture/statistics rows, and the real module
classes. They cover the honest empty states, the feature → data-quality →
scoreline → confidence → contract pipeline, settlement, performance windows,
calibration refusal and the model lifecycle, refresh cadence with backoff, budgets
and cron idempotency, and the wiring of routes, permissions, form↔CSRF parity and
panel ownership. No case seeds a prediction, settlement, model metric or demo
fixture by hand — every figure they assert on comes out of the pipeline being
tested.

Four of them read artefacts instead of trusting prose, because those are places
where a module can quietly become a lie: `105` parses `tools/rbac.php` and rejects
any permission code or role label the football screens tell an operator to ask for
that the seed does not actually define, and it parses all three schema sources
(`football_intelligence.mysql.sql`, `football_intelligence.sqlite.sql`,
`database/production.sql`) to require the same fourteen tables with the same columns
in the same order — a column added for one dialect only would break exactly one
install, the production one. `102` likewise proves that the documented freshness
windows are the ones the model consults, by moving the head-to-head decay with
`WINDELS_FOOTBALL_MAX_AGE_H2H` and reading it back out of `describe()`. `106`
encodes every payload the console and the JSON API return with
`JSON_THROW_ON_ERROR` and walks it for non-finite floats, so a Brier of NaN fails a
build instead of blanking a dashboard; it also asserts that an unsettled metric is
`null` rather than `0`, that the two unauthenticated status endpoints carry nothing
credential-shaped, and that a provider error quoting its own API key is stored and
shown with the key redacted.

`107` holds the parameter layer to the same standard: an unusable `?limit=` or
`?date=` must fall back *and say so in the response*, a window bound the database
cannot read must be dropped with a note rather than returned as an empty feed, and a
sync or board rebuild given an unreadable date refuses instead of quietly doing
something to a different day — which, for a refresh, means billing quota for a day
nobody asked for.

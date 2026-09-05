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
| `/sports`, `/sports/tickets` | the ticket engine only. They link to `/football` for football figures and never render them |

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
| `FixtureSyncService.php` | fixture/live/result sweeps, normalization, idempotent upserts, per-run sync log |
| `StatisticsCollector.php` | team statistics, league tables, head-to-head snapshots, in-match statistics |
| `FeatureBuilder.php` | normalized feature vector per fixture + `dataQualityScore` with weighted components and provenance |
| `ExpectedGoalsResolver.php` | home/away goal rates from venue splits, league baseline or team baseline; `NO_RATE` when none exist |
| `ScoreProbabilityModel.php` | Poisson / Dixon–Coles scoreline grid, outcome marginals, clean-sheet and failed-to-score rates, grid coverage |
| `OutcomePredictor.php` | probabilities, most likely scoreline, alternatives, confidence ceiling, evidence rows, reasons |
| `CalibrationService.php` | temperature scaling fitted from settled predictions only; `CALIBRATION_PENDING` otherwise |
| `ModelRegistry.php` | lifecycle states, transition guards, registration from the deployed fingerprint |
| `PredictionService.php` | prediction storage, the §output contract, the post-kickoff freeze |
| `PredictionBoard.php` | the daily board: summary counts, confidence categories, match cards |
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
provider (api-football / thesportsdb / sportmonks via SportsProviderManager)
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

`MODEL_NOT_CALIBRATED` remains the ticket engine's decision code
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
  `Standard Predictions` (70–74.99), `Limited Data` (below threshold).

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
GET /api/football/matches/:id            fixture + statistics + H2H as stored
GET /api/football/matches/:id/analysis
GET /api/football/matches/:id/prediction
GET /api/football/predictions/today      ?date=&refresh=1
GET /api/football/predictions/history  ?limit=&modelVersionId=
GET /api/football/performance            ?days=30&modelVersionId=
GET /api/football/models                  lifecycle summary + every stored version
GET /api/football/models/active
GET /api/football/calibrations           ?modelVersionId= (defaults to the model in use)
GET /api/football/provider/status
GET /api/football/status
GET /api/football/dashboard            ?date=&refresh=
```

Mutations require the native session plus the CSRF token (header or body field),
then the capability named. They take a JSON body:

```
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
php index.php tools tests            # includes tests/cases/101…107-football-*.php
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

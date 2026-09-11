# WindelsAI Football Pipeline — Audit & Upgrade Record (2026-09-11)

Scope: audit of the **existing** football intelligence system, the root causes of
`FOOTBALL_FIXTURES_UNAVAILABLE` + 0 prediction rows, and the upgrade that was
applied. **Nothing was rebuilt** — every change below upgrades code that was
already in the repository.

## 1. What the existing system is

| Layer | Implementation |
|---|---|
| Frontend | CodeIgniter 3 views (PHP), jQuery-based consoles (`Football.php`, `Sports.php`, `Admin.php`) |
| Backend | CodeIgniter 3 controllers: `Football`, `Api_football` (football console), `Sports`, `Api_sports` (Sports Intelligence + ticket engine), `Cron`, `Tools`, `Admin` |
| Database | MySQL/MariaDB in production (`mysqli`), PostgreSQL and pdo_sqlite supported. Schema in `application/database/*.mysql.sql`, installed by `tools/install.php` / `SchemaInstaller` |
| ORM | None — CI3 Query Builder through `AIWorkforce\Persistence\FootballRepositoryDatabase` and `AIWorkforce_model` |
| Provider abstraction | `AIWorkforce\Sports\Providers\SportsDataProvider` with native adapters `ApiFootballProvider`, `TheSportsDbProvider`, `SportMonksProvider`, `SandboxSportsProvider`, `HttpSportsProvider`, behind `ProviderGateway` + `ProviderSelector` (auto/named/multi) + circuit breaker + persisted quota/backoff |
| Auth | CI3 sessions, RBAC (roles/permissions, `sports.view` / `sports.manage`), admin portal + impersonation |
| Jobs | `CronScheduler` (registry: ops, sports, sports-live, football, lottery, protection) run by `Cron::run` (web, secret-keyed) / `php index.php tools scheduler`; each football job additionally self-gates via `RefreshPolicy` |

Football tables (`football_intelligence.mysql.sql`): providers, competitions,
teams, fixtures, team/fixture statistics, head-to-head, model versions,
calibration versions, match predictions, score probabilities, settlements,
model performance, provider sync logs, provider matches, competition mapping,
prediction revisions.

Sports tables (`sports*.mysql.sql`): data sources, provider health, matches,
odds, data-quality assessments, sync runs, configurations, calibrations, job
runs, backtests, model metrics, daily tickets, performance snapshots, model
versions, predictions, tickets, ticket selections, results.

Both subsystems share one provider registry (`SportsIntelligence->providers`,
consumed by `FootballIntelligence` via `Platform`).

## 2. Why the dashboard said FOOTBALL_FIXTURES_UNAVAILABLE and 0 predictions

Traced (not guessed) — reproduced each failure with the pipeline running against
the real stack in the sandbox:

1. **API key environment name mismatch (primary root cause).**
   The provider registry only ever read `WINDELS_API_FOOTBALL_KEY`
   (`SportsIntelligence::registerProviders()`). An environment that sets
   `API_FOOTBALL_KEY` — the documented variable — is invisible to the app, so
   `ProviderGateway::configured()` is false and `/api/football/status` answers
   `NOT_CONFIGURED` → fixtures `DATA_UNAVAILABLE`. Verified: with only
   `API_FOOTBALL_KEY` set, the football cron reports
   `PROVIDER_NOT_CONFIGURED` for every job.
2. **No fixtures stored ⇒ 0 predictions, by design.**
   `PredictionService::predictDay()` reads stored fixtures only
   (`No fixture for <date> is stored …`). With ingestion dead (cause 1), the
   predict job has nothing to analyze — so 0 prediction rows is the *correct
   downstream symptom* of the ingestion failure, not a prediction-engine bug.
3. **One transient provider failure froze the whole schedule (pipeline bug).**
   `RefreshPolicy::evaluate()` returned `PROVIDER_BACKOFF` for *every* job once
   any provider was in backoff — including the database-only jobs `predict`,
   `settle`, `performance`, `cleanup` (default provider budget 0). Reproduced:
   a single failed health check put all nine jobs in `SKIPPED/PROVIDER_BACKOFF`
   for the backoff window even when fixtures were already stored.
4. **`.env` vs `env` file name.**
   `index.php` loads only `<repo>/.env`. The repository ships a reference file
   named `env` (no dot); a deployment that keeps that name loads no variables,
   which re-creates cause 1 even with the right variable name inside.
5. Jobs only execute when something calls the scheduler (hosting cron →
   `/cron/run?key=…`, `php index.php tools scheduler`, or the admin
   dashboard trigger). If production never scheduled it, sweeps never ran.

What was **already working** and needs no upgrade: the API-Football adapter
(`/status` quota detection, pagination, per-day fixture queries, odds, results),
retry/backoff/circuit-breaker, fixture upserts with coverage, prediction engine
with quality bands and confidence tiers, markets, ticket engine with
correlation rules and `NO_QUALIFYING_TICKET`, settlement, calibration gating,
model lifecycle governance (DRAFT→…→ACTIVE via admin approval), quota
accounting, RBAC, demo-data lockout, and the 1199-case offline test suite.

## 3. Changes applied (upgrade, not rebuild)

1. `AIWorkforce\ApiProviders::footballCredential()` — single server-side
   resolver for the API-Football key and base URL. Reads `API_FOOTBALL_KEY`
   first (documented primary), falls back to the legacy
   `WINDELS_API_FOOTBALL_KEY`/`WINDELS_API_FOOTBALL_BASE_URL`. No other code
   reads the key directly.
2. `SportsIntelligence::registerProviders()` uses the resolver, so both the
   Sports and Football subsystems connect with either variable name.
3. `index.php` env loading falls back to `env` when `.env` is absent, with an
   explicit opt-out (`AI_WORKFORCE_SKIP_ENV_FILE=1`) for hermetic contexts.
4. `RefreshPolicy::evaluate()` — the provider-backoff and provider-configured
   gates now apply **only to jobs that call the provider** (fixtures, upcoming,
   live, results, statistics). Database-only jobs (predict, settle,
   performance, cleanup) keep their own cadence/work gates and run during a
   provider outage. Verified live: with every feed offline, `cleanup`
   completes and `predict`/`settle`/`performance` stay available instead of
   the whole schedule freezing behind `PROVIDER_BACKOFF`.
5. `FootballDiagnostics` — the provider check names the real env variables and
   the headline distinguishes "not configured" from "configured but
   unreachable" / "no fixtures stored yet" instead of showing one generic
   message for three different situations.
6. `.env.example` documents `API_FOOTBALL_KEY` as the primary variable;
   `docs/SPORTS_PROVIDERS.md` and `docs/SPORTS_PROVIDER_INTEGRATION.md` updated.
7. **Security**: the dotless `env` deployment file in the web root was
   downloadable (`.htaccess` only blocked `.env*`). `.htaccess` now denies
   `env`, `config.php`, `database.php` and the `application/data`, `application/logs`
   and `application/cache` directories. The credential-bearing `env` file is
   untracked from git (kept on the server, `/env` gitignored). **The key it
   contained remains in git history and must be rotated.**
8. Hermetic test runner: `runtime/run-tests.mjs` strips ambient config and
   pins `AI_WORKFORCE_SKIP_ENV_FILE`/both DB-driver variables so a deployment
   env file can never decide test outcomes (this bit the suite the moment the
   env fallback shipped).
9. New tests: `tests/cases/142-football-env-alias-and-outage.php` (4 cases) +
   updated `tests/cases/104-football-refresh-cron.php` to the corrected outage
   semantics. Full suite: **1203 passed, 0 failed**.

No football table needed a migration for this upgrade: the schema already
carries every column the pipeline requires (provider fixture ids, coverage,
data_state, model versions, revisions, settlements, quota columns).


## Phase 16 — mock-driven end-to-end verification (2026-09-11)

The whole API-Football path was exercised through the real HTTP stack against
a deterministic mock provider (`tests/mock-api-football/server.mjs`, offline
only, selected via the sandbox `.env`; never shipped): fixtures → statistics
(league table, per-team fallback, head-to-head) → prediction → odds →
fair-value intelligence → settlement → performance → the odds prediction
ticket engine. Verified live behaviours:

- All five schedulable matchday fixtures ingest team statistics (12 rows /
  11 AVAILABLE) **and** head-to-head snapshots (3–7 meetings per pair, weights
  0.045–0.09) — the long-standing "h2h = 0 rows" symptom was a pipeline bug,
  not a provider gap (fix 2 below).
- Priced intelligence is honest in both directions: with market quotes the
  overround is stripped (PROPORTIONAL_OVERROUND), the edge is reported as
  computed (a negative edge renders **AVOID**, never a manufactured value), and
  without quotes the report stays `UNPRICED` / "No price to compare" with the
  model's fair odds only.
- Settlement: results pulled from the provider once kickoffs pass, settlement
  rows insert once (re-runs skip, never duplicate), per-prediction snapshots
  preserved, Brier / log-loss / absolute goal error graded per row.
- Performance: 30-day scorecard computed from settlements (accuracy, Brier,
  ECE, reliability bins); calibration honestly stays PENDING below the 50-sample
  minimum instead of fitting noise.
- Ticket engine: NO_QUALIFIED_TICKET with an evidence-based rejection funnel
  (LOW_CONFIDENCE on thin synthetic form; zero missing-data or infra errors).

### Fixes shipped in this phase (each with a regression test)

1. **Statistics request budget** (`FootballIntelligence::collectStatisticsForDay`):
   the job never called `beginSweep()`, so it scavenged the fixtures job's
   leftover request budget in the same process — and when run first or alone
   (forced from the console) that leftover was 0 and every provider call died
   with `REQUEST_BUDGET_EXHAUSTED` while the provider was healthy. The job now
   opens its sweep with `WINDELS_FOOTBALL_BUDGET_STATISTICS` (default 20).
   Test: `tests/cases/104-football-refresh-cron.php`.

2. **Settlement flip** (`FootballRepositoryDatabase::savePrediction`):
   settlement re-saves a prediction read back through `decode()` — JSON columns
   as raw arrays — and the UPDATE failed silently, leaving predictions OPEN
   forever even though the settlement row and fixture stamp were written (the
   settle scanner keys off the fixture stamp, so nothing ever revisited them).
   The repository now re-encodes JSON columns symmetrically with `decode()`.
   Test: `tests/cases/132-football-repository-refs.php`.

3. **Test determinism**: the sports cron round test used today's 14:00 UTC
   kickoffs, which the near-kickoff odds guard (kickoff ≤ now + 2h) excludes
   any time the suite runs after noon UTC; the case now uses tomorrow's
   matchday. `tests/framework.php`'s in-memory repository honours a caller
   backdated `startedAt` on sync runs so cadence math is testable.

Suite after this phase: **1208 passed, 0 failed**
(5 new regression tests). Deployment archive rebuilt:
`application-deployment.zip`, 710 release files,
SHA-256 `9b06aabb8576486d5d50b6c3444ebbe547573bec1c25a37e98d8125b37bb108f`.

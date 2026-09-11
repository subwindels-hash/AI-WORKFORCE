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


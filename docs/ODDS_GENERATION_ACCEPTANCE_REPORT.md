# Odds Prediction Ticket — Acceptance Report

**Date:** 2026-09-13 · **Branch:** `arena/01a09b4d-ai-workforce` · **Commit:** `005f1cd`
**Automated suite:** 1408 passed / 0 failed
**Execution environment:** the running CodeIgniter app (WASM PHP 8.2 + SQLite dev driver) on
`127.0.0.1:8080`, driven over real HTTP with an authenticated session.

## Live provider status

> **Implementation verified with deterministic test provider; live provider test could not be
> completed.**

No commercial sports-data API key is configured in this sandbox, and the hosted console at
`windelsai.com` is authenticated-only. All end-to-end runs below therefore used the repository's
own `SandboxSportsProvider` — a deterministic, explicitly opt-in feed (`WINDELS_SPORTS_SANDBOX=1`)
that labels every record `simulated: true` and refuses to run outside SANDBOX mode. With no
provider configured, the workflow correctly reports `NO_PROVIDER` rather than inventing data
(test 4 below), which is itself part of the acceptance criteria.

## Real generated tickets

| Route | Ticket ID | Date | Combined odds | Legs |
|---|---|---|---|---|
| `POST /sports/generate-ticket` | `02dc8bf9-937a-56af-aa90-d3ace3d690cb` | 2026-09-20 | **7.093** | 2 |
| `POST /api/sports/ticket-engine/run` | `6f825b1f-facf-514a-ab1a-69f0aa67c3ec` | 2026-09-14 | **6.8162** | 2 |

Legs of `02dc8bf9…` as persisted (nothing hard-coded — each value was computed by the pipeline):

| Market | Selection | Odds | Confidence | Data quality | Expected value |
|---|---|---|---|---|---|
| MATCH_RESULT | DRAW | 4.10 | 78.04 | 96.0 | 0.145438 |
| DRAW_NO_BET | HOME | 1.73 | 73.90 | 96.0 | 0.329628 |

## Acceptance tests

| # | Scenario | Result | Evidence |
|---|---|---|---|
| 1 | Generate with valid data produces a ticket | **PASS** | `02dc8bf9…` / `6f825b1f…`, `generation_status=GENERATED` |
| 2 | Both routes use one service and one contract | **PASS** | Both projected via `GenerationResult::fromRunDaily()`; API response carried all 24 fields |
| 3 | All 22 required contract fields present | **PASS** | Field-by-field check of the live API body — `MISSING: none` |
| 4 | No provider → `NO_PROVIDER`, not "no qualifying games" | **PASS** (defect found and fixed) | Stored `status=NO_PROVIDER`; previously `NO_QUALIFIED_TICKET` |
| 5 | All providers failing → `DATA_UNAVAILABLE` | **PASS** | Distinct status + per-provider codes, no credentials exposed |
| 6 | Healthy provider, zero fixtures → `NO_FIXTURES` | **PASS** | Suite `148` (distinct from both outage states) |
| 7 | Assessed but unqualified → `NO_QUALIFIED_TICKET` + breakdown | **PASS** | Live run showed `rejections=85`, `gateFailures` labelled |
| 8 | Disabled engine → `DISABLED` | **PASS** (defect found and fixed) | Was `NO_QUALIFIED_TICKET`; now a declared configuration state |
| 9 | HTTP semantics (503 / 200 / 422 / 500) | **PASS** | `NO_PROVIDER`→503; ticket→200; bad date→422 |
| 10 | Confidence gate: 29.99 fails, 30.00 passes | **PASS** | `ConfidencePolicy::evaluate()`, epsilon-compared |
| 11 | Confidence configurable only within 30–100 | **PASS** | 29.99 and 100.01 refused by `ConfigurationService` |
| 12 | Data-quality gate: 74 fails, 75 passes | **PASS** | Rejection text names the 75 minimum |
| 13 | Gates independent, never conflated | **PASS** | Each yields only its own rejection reason |
| 14 | Optimizer never weakens a gate | **PASS** | Sub-gate candidates give `poolSize=0` → `NO_QUALIFIED_TICKET` |
| 15 | Combined odds inside 5.00–8.00 | **PASS** | 7.093 and 6.8162 |
| 16 | Qualified candidates ≠ selected picks | **PASS** | 107 qualified → 2 selected (live run) |
| 17 | 14 real stages, no faked progress | **PASS** (defect found and fixed) | Panel was painting all stages Complete; now driven by the persisted ledger |
| 18 | Failed run never shows downstream stages complete | **PASS** | Provider `FAILED` → all 13 later stages `SKIPPED` |
| 19 | Stage detail is real | **PASS** | e.g. "12 fresh / 0 stale", "192 generated", "107 qualified candidates" |
| 20 | Duplicate generation → `DUPLICATE_SKIPPED` | **PASS** | Second call returned the same ticket; no extra row or leg |
| 21 | Duplicate reports SKIPPED stages | **PASS** (defect found and fixed) | A duplicate had no ledger and fell back to "all complete" |
| 22 | Selected date honoured, stored, and in the redirect | **PASS** | Redirect `…?date=2026-09-20`; daily row on the requested date |
| 23 | Invalid date refused, never falls back to today | **PASS** (defect found and fixed) | Previously generated a ticket for today; now 422 / refusal |
| 24 | Impossible date (2026-02-31) refused | **PASS** | `checkdate()` guard on both routes |
| 25 | Early exits still return the full contract | **PASS** (defect found and fixed) | Returned 6 keys; now the full envelope with honest zeroes |
| 26 | RBAC: generation requires `sports.manage` | **PASS** | `sports.view` user: API 403, browser redirect, no ticket |
| 27 | Normal users may view published tickets | **PASS** | Viewer loaded `/sports/odds-prediction-ticket` (HTTP 200) and saw the ticket |
| 28 | Viewer UI offers no usable generate control | **PASS** | Disabled button + 0 submitable forms; admin sees 2 |
| 29 | Unauthenticated / CSRF-less requests refused | **PASS** | 401 and 403 respectively |
| 30 | GET on the generate route does not generate | **PASS** | 302 to `/sports`, nothing written |
| 31 | Run records the acting admin, not "system" | **PASS** | Audit rows carry `actor=1` |
| 32 | Audit can reconstruct a run | **PASS** | runId, counts, versions, timings and all 14 stages persisted |
| 33 | Nothing fabricated when data is absent | **PASS** | Zero fixtures, predictions and tickets written on a no-provider day |
| 34 | Stale odds rejected and counted | **PASS** | Suite `148`; freshness stage reports fresh/stale split |
| 35 | Ticket awaits approval, no execution | **PASS** | `approval_status=PENDING_USER_APPROVAL`, no broker path |
| 36 | Dark enterprise console styling retained | **PASS** | Existing markup/classes reused; no sportsbook affordances added |

## Defects found by executing the routes

Five issues were invisible to the pre-existing test suite and surfaced only by driving the real
application. Each is fixed and covered by a regression test in
`tests/cases/148-odds-generation-workflow.php`:

1. **Missing provider reported as `NO_QUALIFIED_TICKET`** — the `NO_PROVIDER` branch set
   `dataState` but left `$status` at its default, so a day nothing had looked at was reported as
   "no qualifying games". The three `DISABLED` branches had the same defect.
2. **Invalid date silently generated for today** — a malformed date fell through to
   `$today` and produced a real ticket for the wrong day.
3. **Early returns dropped the contract** — `RETRY_SCHEDULED`, `GENERATION_IN_PROGRESS` and
   `RESET_FAILED` returned six keys, so the API answered with most fields missing.
4. **The stage panel fabricated progress** — client-side JS marked every stage Complete whenever
   a run had been recorded, regardless of what actually executed.
5. **`SandboxSportsProvider` was not deterministic** — `sourceTimestamp` and the form timestamp
   read the wall clock, so two instances built either side of a second tick disagreed. This made
   the provider's own determinism test fail intermittently under a full-suite run.

## Notes and limitations

- Three pre-existing tests in `tests/cases/43-sports-daily-ticket-e2e.php` asserted the old
  conflated statuses (`NO_QUALIFIED_TICKET` for a missing provider and for `VIEW_ONLY`). They were
  updated to the corrected states, with the reason recorded inline — the underlying behaviour was
  verified first, not the assertion relaxed to suit the code.
- `min_confidence` remains **30** and `min_data_quality` **75**, per the standing decision to keep
  the 30% default. `docs/SPORTS_PROVIDERS.md` had documented stale bounds (`25–100`, default
  `80/100`) and has been corrected.
- The dev bridge gained an explicit, opt-in header (`WINDELS_SPORTS_SANDBOX=1`) so the WASM runtime
  can pass the sandbox flag through. It is off by default, so the standard preview still shows the
  honest "no provider configured" state.

# Sports Intelligence — Production Review (Integration Plan Step 6)

**Status:** code-level review **complete** — both findings remediated and pinned by
tests (`tests/cases/51-sports-production-review.php`). Process-level cutover items
remain (checklist at the bottom).

## Scope

The mutation surface, reviewed against the plan's security constraints
(authentication, RBAC, CSRF, rate limits, audit attribution, environment-only
credentials, no automated external execution):

| Surface | Endpoints |
|---|---|
| Console (MVC) | `POST /sports/(:id)/decide`, `POST /sports/(:id)/settle` |
| JSON API | `POST api/sports/tickets/(:id)/decide`, `POST api/sports/tickets/(:id)/settle`, calibration approve/reject, provider toggle |
| Cron | `php index.php tools sports-cron [fixtures\|odds\|results\|quality\|ticket\|settlement\|performance\|monitoring\|cleanup]` |

## Verified — no change needed

- **RBAC matrix** — `sports.view/manage/approve/settle` enforced on both console and API paths with the same permission checks; console uses the PRG pattern and refuses loudly. Pinned by case 49.
- **Audit attribution** — ticket recorded / approved / rejected / settled events carry the acting identity and reason; `decide()` and `settlePending()` always emit through the `AuditRepository`. Pinned by case 43.
- **Environment-only credentials** — provider credentials live in env only; provider payloads are untrusted and pass normalizers; the sandbox provider is online only when `WINDELS_SPORTS_MODE=SANDBOX` **and** `WINDELS_SPORTS_SANDBOX=1`.
- **Honest disable** — with no provider the module boots `DISABLED_NO_PROVIDER` and fabricates no fixtures, odds, predictions or tickets; demo data is always bannered (`DEMO / SANDBOX DATA`). Pinned by cases 47/50.
- **Kill switch boot default** — platform state defaults to `killSwitch.active = true` ("orders blocked until explicitly released") — fail closed on fresh installs.
- **No external execution** — approval never places a bet; there is no execution connector in this deployment (stated in every UI surface and every audit event).
- **Calibration gate** — a calibration is only usable after administrator approval; until then ticket-grade decisions report `MODEL_NOT_CALIBRATED` (case 46).

## Findings and remediations

### 1. Console mutation forms had no CSRF protection (FIXED)

Platform-wide `csrf_protection` is **off** (`application/config/config.php`); the
JSON API self-guards by verifying the session token (issued at sign-in by
`Api_auth`) as the `X-CSRF-Token` header. The step-6 console mutation endpoints
only checked RBAC, so a forged cross-site form POST could approve/reject/settle
tickets for a signed-in operator.

**Fix** — `Sports::requireSportsPermission()` now verifies the posted
`csrf_token` field against the session token with `hash_equals()` (same token as
the API path), and `base()` passes `csrfToken` to the views. All six console
mutation forms (dashboard approve/reject/settle, tickets-console inline
approve/reject/settle) submit the hidden field.

### 2. Approval could proceed while the kill switch was ACTIVE (FIXED)

The paper engine blocks order placement while the kill switch is active
(`PaperTradingEngine::submitOrder`), but the sports mutation paths ignored the
switch — an operator could open new ticket exposure after tripping the kill
switch.

**Fix** — console `decide()` and API `decide_ticket()` now refuse (flash /
HTTP 409) while the switch is active, reading the live persisted state.
**Settlement is deliberately not gated** — it is the unwind/finalize path (it
records results on already-approved tickets), mirroring
`PaperTradingEngine::closePosition()`, which is also not kill-switch-gated.

Both fixes are pinned by case 51 (per-method source assertions so a refactor
that drops the guard fails the suite) plus a behavioral round trip of the kill
switch through the live platform state.

### 3. The console handed out controls the signed-in identity could not use (FIXED)

Reported symptom: `Refused: signed-in identity lacks 'sports.manage' — the sync
action was not performed.` Two defects combined:

1. **The views rendered mutation controls unconditionally.** Any identity with
   `sports.view` saw a working-looking *Sync now* button (and approve/reject/
   settle forms) that could only be refused on submit.
2. **The permission decision was taken against the session snapshot.** The
   session identity is a copy captured at sign-in, so a role granted afterwards
   (admin console, RBAC re-seed, support escalation) stayed invisible until the
   next sign-in — and, symmetrically, a revoked permission kept working until
   logout.

**Fix** —

- `Sports::sportsCaps()` resolves `sync` / `approve` / `settle` for the signed-in
  identity and `base()` passes `caps` into both console views. Controls render
  only when the identity holds the permission; otherwise a disabled control
  names the missing permission and how to obtain it (no dead-end POST target).
- `MY_Controller::refreshIdentityPermissions()` re-reads permissions from the
  database before every permission decision — the console guard, the JSON API's
  `requirePermission()` and the admin page gate — once per request, and
  rewrites the session snapshot when it changed. A grant applies on the very
  next action; a revocation stops working at once. A database error keeps the
  snapshot the session was issued with, so an outage never silently grants or
  revokes access.
- The refusal message now names the role to ask for and states that no
  sign-out is needed.

Pinned by case 100 (behavioral: granted / revoked / once-per-request /
database-failure paths against a stub session and identity repository) and case
50 (rendered output: a read-only identity gets no mutation POST targets, and
the missing permission is named).

## Process items for cutover (not code — owner: operator)

1. **Data backfill before first real ticket** — enough historical odds +
   verified results to fit a calibration; the model stays
   `MODEL_NOT_CALIBRATED` until an administrator approves a calibration.
2. **Scheduler** — wire `php index.php tools sports-cron` (all jobs) on a
   schedule: fixtures/odds before kickoff windows, results + settlement after,
   `monitoring` + `cleanup` daily.
3. **Monitoring/alerts** — provider health (`api/sports/providers`), sync-run
   failures, calibration ECE/Brier drift (`api/sports/models/performance`),
   and settlement anomalies; alert on repeated `SPORTS_...` audit errors.
4. **Rollback** — unset the provider env credentials (module drops back to
   `DISABLED_NO_PROVIDER`) or re-engage the kill switch; no external state to
   unwind because no external execution exists.

## Go / no-go checklist

- [ ] Migrations applied (`tools install`); sports tables verified in target DB
- [ ] Provider credentials set in environment only; provider payload sample passed through normalizer
- [ ] RBAC users provisioned (`bootstrap_admin`); `sports.approve` / `sports.settle` granted only to named operators — grants apply on the next action, no sign-out needed
- [ ] Kill switch deliberately released for the trading window (it boots ACTIVE)
- [ ] Mode set deliberately: `WINDELS_SPORTS_MODE` = `PAPER` (or `PRODUCTION`) — not left on `SANDBOX` in production
- [ ] Backfill + approved calibration in place (no `MODEL_NOT_CALIBRATED` on a live day)
- [ ] `sports-cron` scheduled; `monitoring` job observed healthy for ≥ 1 full match day
- [ ] Full test suite green (`tools tests`) on the deployed revision

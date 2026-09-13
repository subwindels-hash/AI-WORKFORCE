# Sports & Football console audit — 2026-09-13

**Scope:** the authenticated `/sports` and `/football` pages (plus their sub-pages
`/sports/odds-prediction-ticket` and `/football/models`), the shared sidebar,
mobile navigation, SPA shell, routes, API behaviour and responsive CSS.

**Method:** the real application, signed in — not the public login shell. The
CodeIgniter app was run in the php-wasm dev runtime (`runtime/server.mjs`,
pdo_sqlite), the pages were rendered with an authenticated admin session, the
HTML was inspected programmatically (duplicate ids, active states, PHP
warnings), all inline scripts and `assets/js/app-shell.js` were syntax-checked
with Node, and the project's own suite (1370 cases) was run before and after.

> Note on the original instruction: the request assumed a `pnpm` / React
> project (`pnpm lint / typecheck / test / build`). This repository is a
> CodeIgniter 3 (PHP 8.2) application with server-rendered views; the
> equivalent verification here is the php-wasm test suite
> (`cd runtime && npm test` → `php index.php tools tests` on a native host),
> PHP parse checks and `node --check` for the shell script. All were run and
> pass. Several items on the checklist were **already implemented and
> test-pinned** (pagination that only reads stored rows, honest empty/error
> states, UNPRICED odds handling, live-status filtering, provider-crest-only
> team logos); those were verified rather than rebuilt.

---

## 1. Problems found (and their state)

### Fixed in this change

| # | Problem | Where |
|---|---------|-------|
| 1 | **Duplicate DOM id** `live-scores-empty`: the server-rendered empty row and the JS repaint template both carried the id, so after the first live-poll repaint the document contained two nodes with one id (invalid HTML, breaks `getElementById` consumers). | `application/views/sports/index.php` |
| 2 | **SPA shell did not know `/football` or `/messages`**: sidebar clicks to Football Intelligence caused a full page reload (dropping the mounted shell) while every other sidebar item swapped in place; on return navigation the active highlight was recomputed incorrectly. | `assets/js/app-shell.js` `AUTHENTICATED_PREFIXES` |
| 3 | **SPA active-state updater could leave zero or multiple sidebar items highlighted** after client-side navigation (fallback pass used a bare `startsWith` so `/sports` also matched `/sports/odds-prediction-ticket`), and never set `aria-current`. | `assets/js/app-shell.js` `updateActiveLinks()` |
| 4 | **No visible keyboard focus state** on sidebar links or buttons (only the mobile toggle had `:focus-visible`). | `assets/css/ai_workforce.css` |
| 5 | **Sticky topbar covered anchored sections**: deep links like `/football#football-live-panel` scrolled the heading underneath the fixed topbar. | CSS `scroll-margin-top` for console sections |
| 6 | **No team search on the football board** (a checklist requirement). Added a client-side finder over the rows already rendered — no request, no regeneration, hidden until JS enables it, result count announced via `aria-live`. | `application/views/football/index.php` + CSS |
| 7 | **Football day navigation dropped the active filters**: Previous day / Today / Next day discarded the competition/market/provider selection the pager preserved. | `application/views/football/index.php` |
| 8 | **Filter panel hidden from non-admin viewers** even though competition / premium-league / market / date are honoured server-side for every signed-in viewer. Now every viewer gets those filters; only the Data-provider pin (which the backend only honours for admins in MANUAL mode) stays admin-gated. | `application/views/football/index.php`, `application/controllers/Football.php` (comment corrected) |
| 9 | **No "last updated" stamp or refresh control on `/sports`**: added an honest `generatedAt` (when the stored payload was read) to `SportsIntelligence::dashboard()`, surfaced as "Board read HH:MM UTC" with a no-provider **Reload** link beside the RBAC-gated **Sync now**. | `application/libraries/AIWorkforce/Sports/SportsIntelligence.php`, `application/views/sports/index.php` |
| 10 | **`aria-current="page"` missing** on the active sidebar item (server render and SPA swaps). | `assets/js/app-shell.js` |

### Verified healthy (no change needed)

- **Routes** — every sidebar destination resolves (200 after login): `/sports`,
  `/sports/odds-prediction-ticket` (+ `/sports/tickets` legacy alias),
  `/football`, `/football/models`, `/football/match/{id}`; legacy
  `/football/live` redirects to `/football#football-live-panel` without
  spending a provider request (test-pinned).
- **Auth flow** — logged-out visitors get the page shell + in-page sign-in gate
  with a `return_to` that lands them back on the sports console; mutations are
  RBAC + CSRF checked per request.
- **Live vs finished matches** — the live board only admits canonical
  in-progress statuses (`LIVE`, `HALFTIME`, `EXTRA_TIME`, `PENALTIES`)
  confirmed within the staleness threshold; the football live panel re-filters
  the payload client-side and removes cards that leave the live set.
- **Pagination** — server-clamped `page` (invalid values are reported, not
  silently reinterpreted), 50 rows/page, Previous/Next only read stored rows;
  the pager carries the filter selection (test-pinned in
  `124-football-match-pagination.php`, `128-football-board-fifty-rows.php`).
- **No fabricated data** — UNPRICED / DATA_UNAVAILABLE labels for missing
  quotes; crests render only from stored provider URLs (`crest()` helper
  returns nothing otherwise); missing kickoff prints `—`, never epoch or 00:00;
  no-provider, blocked-engine, rate-limit and quota states each render their
  own explanatory notice.
- **Duplicated API calls** — live polling is single-flight (`inFlight` guard),
  backs off exponentially on failure, pauses on hidden tabs, and stops
  permanently on 401/403.
- **Responsive layout** — off-canvas sidebar drawer ≤820px with scrim,
  Escape-close, outside-click close and close-on-navigate; single-column
  console ≤1180px; stat grids collapse at 760/560/480/430px; tables scroll in
  `.table-scroll` wrappers; `html`/`body` clip horizontal overflow.

## 2. Files changed

| File | Change |
|------|--------|
| `assets/js/app-shell.js` | `/football`, `/messages` added to SPA prefixes; `updateActiveLinks()` rewritten (exactly one active, longest-ancestor fallback, `aria-current` mirrored); `initUI()` syncs `aria-current` with the server-rendered active link |
| `assets/css/ai_workforce.css` | `:focus-visible` for sidebar links and buttons; `scroll-margin-top` for console sections; football search styles; `.visually-hidden`; board-read stamp style |
| `application/views/sports/index.php` | Duplicate `live-scores-empty` id removed from JS template; action bar gains "Board read HH:MM UTC" + Reload |
| `application/views/football/index.php` | Filter panel un-gated for viewers (provider pin still admin-only); day nav carries filters; on-page team search (markup + script) |
| `application/controllers/Football.php` | Comment corrected to match the new gating |
| `application/libraries/AIWorkforce/Sports/SportsIntelligence.php` | `dashboard()` publishes `generatedAt` |
| `tests/cases/156-sports-football-console-audit.php` | 7 new regression tests pinning all of the above |

## 3. Tests run and results

- Full suite before changes: **1363 passed, 0 failed** (baseline).
- Full suite after changes: **1370 passed, 0 failed** (7 new cases).
- `node --check assets/js/app-shell.js` and the extracted inline search
  script: no syntax errors.
- PHP `token_get_all(..., TOKEN_PARSE)` on every touched PHP file: no parse
  errors.
- Authenticated smoke over the running app: `/sports`, `/football`,
  `/sports/odds-prediction-ticket`, `/football/models`, `/football?page=2`,
  filtered day-nav URLs and `/sports?date=…` → all HTTP 200, zero PHP
  warnings in output, zero duplicate DOM ids, day-nav links verified to carry
  `competition`/`market` through.

## 4. Remaining blockers / limitations

1. **No real sports provider is configured in this sandbox** (and no network
   for one), so both pages render their honest no-provider states
   ("No sports data provider connected…", engine `DISABLED_NO_PROVIDER`).
   Populated-board rendering is covered by the suite's seeded-repository
   render tests (`131-football-view-render.php`, `50-sports-dashboard-ui.php`,
   `123-sports-viewing-date.php`) rather than by live data.
2. **Headless-browser click-through was not possible** in this sandbox (the
   Playwright browser CDN is unreachable), so console-error and pixel-level
   responsive verification was done by static analysis + rendered-HTML
   inspection at the markup level. The recommended follow-up on a networked
   CI host: `npx playwright install chromium` and a click-through of the two
   pages at 320/375/768/1024/1440px.
3. The production site (`windelsai.com`) must be redeployed with these files
   for the fixes to be visible publicly; `/sports` and `/football` will still
   show the sign-in gate to logged-out visitors **by design**.

# Live chart UI preview harness

**Not part of the application.** This exists only so the live market-data chart
can be *seen* in an environment that cannot run the real one.

```bash
node tools/preview/live-chart-server.js     # http://0.0.0.0:8123
PORT=9000 node tools/preview/live-chart-server.js
```

## Why it exists

The production app is CodeIgniter 3 / PHP 8, and the live chart needs real
egress to `api.binance.com`, `api.frankfurter.dev` and Yahoo Finance. A sandbox
with no PHP binary and no outbound network can run neither. Rather than claim
the chart works unverified, this harness serves the **unmodified shipping
assets** against a simulated feed:

| Served from disk, unmodified | Purpose |
|---|---|
| `assets/js/market-chart.js` | the real chart client — badges, polling, switcher, backoff |
| `assets/css/ai_workforce.css` | the real stylesheet, including the `.livechart-*` / `.livemarket-*` rules |

Only the `/api/market-data/live` and `/api/market-data/refresh` **responses** are
simulated, and they are shaped exactly like `Api_marketdata` produces them
(`candles`, `provenance`, `quote`, `live`, `refreshSeconds`), so the client code
path — provenance → live verdict → badge → whether to auto-refresh — runs
unchanged.

## What is honest here and what is not

- **Every price is generated locally.** The page carries a permanent banner
  saying so. Nothing is fetched from any real market-data provider.
- The verdict logic *is* genuinely exercised: switch the feed mode to see
  `LIVE`, `DELAYED` (Yahoo equities), `STALE` and `SYNTHETIC`, and switch symbol
  to `XAUUSD` to see a symbol no real provider serve fall back to the labelled
  simulation with auto-refresh withheld.
- The connection chips cycle the three states `ApiProviders::serviceState()`
  reports — **LIVE**, **CONNECTED · NOT ENABLED**, **NOT CONNECTED** — which is
  the dark state that used to be invisible.

## To verify against real data

Run the actual application on a host with PHP 8.1–8.3 and network egress:

```bash
php index.php tools marketdata              # report configured vs live
php index.php tools marketdata --activate   # promote a saved keyless public feed
php index.php tools marketdata --probe      # fetch real bars, print LIVE per class
php index.php tools tests                   # full suite (incl. 78-market-data-live.php)
```

Then load `/analysis` in the browser — the chart renders on load, no analysis
run required.

Delete this directory to remove the harness; nothing in `application/`,
`assets/` or `tests/` references it.

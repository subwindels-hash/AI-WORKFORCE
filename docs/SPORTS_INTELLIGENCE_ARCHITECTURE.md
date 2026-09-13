# WINDELS Sports Intelligence — Integration Plan

## Host adaptation

The target checkout is the AI_WORKFORCE CodeIgniter 3 application. It has reusable MVC routing, environment configuration, repository-style persistence, audit logging, a domain-service container, and a custom test runner. It does **not** have authentication, RBAC, admin users, notifications, or scheduled-job infrastructure. Those capabilities must be established before any privileged Sports mutation, provider configuration, approval, or settlement endpoint is exposed.

## First implementation boundary

`AIWorkforce\Sports` is a domain module, not a separate application. Its provider interface is deliberately provider-neutral and all inputs are normalized before use. At boot there are no providers and the module reports `DISABLED_NO_PROVIDER`; it never creates demo fixtures, odds, predictions, results, or tickets.

## Delivery order

1. Foundation, provider contract, normalization and data quality — implemented.
2. Auth/RBAC/CSRF and sports persistence migrations — implemented (provider, health, canonical fixture, odds, quality assessment, and idempotent sync-run tables).
3. Fixture and odds synchronization, odds freshness, and conservative match-intelligence gates — implemented; result synchronization follows as a separate provider-enabled increment.
4. Match intelligence, versioned features, prediction/calibration/value/risk/correlation, no-predict gates, and decision/ticket persistence schema — implemented.
5. Persisted decision writes, ticket approval, result verification, settlement and analytics — next.
6. Backtesting, model monitoring, dashboards, responsive UI, and production review.

## Security constraints

Provider credentials remain environment-only. Provider payloads are untrusted and must pass normalizers. No mutation endpoints are introduced before authentication, authorization, CSRF, rate limits, and audit attribution exist. Automated external execution remains disabled.

## Canonical market model

`AIWorkforce\Sports\SportsMarketRegistry` is the single provider-neutral market
vocabulary. Every feed names the same market differently — "Match Winner",
"Full Time Result", "1X2" — so a provider's spelling is resolved once, at
ingestion, and never reaches the prediction engine or the frontend:

```
Provider Market → Market Normalizer → Canonical Market → Prediction Engine
                                                       → Odds Prediction Ticket
```

Each canonical market declares:

| field | meaning |
| --- | --- |
| `scope` | `MATCH`, `TEAM` or `PLAYER` — what the market resolves for |
| `unit` | what it measures (goals, cards, corners, shots, saves…) |
| `support` | `MODELLED` or `PROVIDER_ONLY` |
| `outcomes` | the canonical outcome set |
| `lined` | whether it carries a goal/handicap line |

`support` is the honesty boundary. `MODELLED` means the engine derives its own
probability. **`PROVIDER_ONLY` means the structure is understood and a verified
provider quote is stored, displayed and fully traceable, but no probability is
generated** — this covers the player and match-event markets (goalscorer,
player goals/shots/assists/tackles/fouls/offsides/cards, goalkeeper saves,
corners, cards, offsides, score-in-both-halves). The platform does not forecast
them, and it says so rather than implying a prediction it cannot make.

Three rules the registry enforces:

- **An unrecognised market is never guessed into a near neighbour.** It is
  stored with its provider name, its real price and `marketRecognized = false`.
  Bending an unknown name onto a known key would attach one market's price to
  another market's probability.
- **Nothing is fabricated.** A market a provider did not supply is absent, not
  invented; a player entity is recorded only when the feed actually sent it.
- **Provenance is preserved in full.** Every ingested quote keeps
  `providerMarket`/`providerSelection` (as the feed wrote them) alongside
  `canonicalMarket`/`canonicalSelection`/`line`, so an administrator can audit
  exactly how a price was mapped.

Adding a market is one row in `MARKETS` plus its provider spellings in
`ALIASES`; consumers ask the registry what a market is instead of hard-coding
their own list, so no engine needs redesigning.

The canonical model is published at `GET /api/football/markets` as
`canonicalMarkets`, alongside the existing football catalogue, which is
unchanged.

### Confidence floor

The lowest confidence an operator may configure is
`ConfigurationService::MIN_CONFIDENCE_FLOOR` (25%). This widens what an
administrator *may* set; it changes no default — the shipped configuration is
still 75% — and it never inflates a value. The adaptive ladder in
`ConfidencePolicy` continues to derive the per-tier requirement from data
quality, and a fixture below the data-quality floor is still rejected outright
whatever its confidence happens to be.

# WINDELS AI WORKFORCE — Lead Discovery & Sales Intelligence (formerly Scout)

> **User-facing brand:** WINDELS AI WORKFORCE. The internal code name "Scout" and the internal system name "AI_WORKFORCE" are retained for class names, env vars, and DB identifiers for backward compatibility.

`apps/api` and `apps/web` are the Lead Discovery module inside WINDELS AI WORKFORCE. They do not
use the CodeIgniter/AIWorkforce trading services directly. The only shared code is the typed
contract package at `packages/shared/src/leadDiscovery.ts`.

## Vertical slice

```text
Google Places / Apollo People Search → validate/normalize → PostgreSQL leads
       → source-key deduplication → secondary duplicate review
       → collections → pipeline/status/owner/notes/activity
       → coverage/history → formula-safe JSON/CSV export
```

### Runtime

- **Web:** Next.js, React, TypeScript and Tailwind at `apps/web`. Pages include `/app/leads`, `/app/lead-pipeline`, `/collections`, `/intelligence`, `/account`, `/login`, `/admin/login` and the administrator control center at `/admin`.
- **API:** Fastify/TypeScript at `apps/api`. The API includes organization-scoped admin user management at `/api/v1/admin/users`.
- **Permanent data:** PostgreSQL (`DATABASE_URL`).
- **Operational data:** Redis (`REDIS_URL`) for cache, rate limits, locks and
  queued search jobs. Redis never stores the only copy of a lead.

## Local setup

```bash
npm install
cp .env.example .env
# Set DATABASE_URL, REDIS_URL, LEAD_JWT_SECRET and CORS_ORIGINS.
# Set GOOGLE_PLACES_API_KEY for business listings and/or APOLLO_IO_API_KEY
# for buyer/contact searches, then export the values:
set -a; source .env; set +a
docker compose -f docker-compose.lead-discovery.yml up -d
cd apps/api && npm run migrate
BOOTSTRAP_EMAIL=owner@example.com BOOTSTRAP_PASSWORD='use-a-long-unique-password' npm run bootstrap
cd ../.. && npm run typecheck
npm run test:contracts
npm run typecheck --workspace @lead-discovery/web
npm run build --workspace @lead-discovery/web
```

Run the API and web in separate terminals:

```bash
cd apps/api && npm run dev
cd apps/web && LEAD_API_INTERNAL_URL=http://127.0.0.1:3001 npm run dev
```

The Next rewrite proxies `/api/*` to Fastify. Browser code uses relative API
URLs, so it never calls `localhost` directly from a user's browser. In
production set `LEAD_API_INTERNAL_URL` to the private API URL and configure
`CORS_ORIGINS` for any direct API clients.

## Provider contract

`LeadDiscoveryProvider` is defined in
`apps/api/src/providers/leadDiscoveryProvider.ts`. Providers expose:

- `name`
- synchronous configuration `health()`
- `searchBusinesses(input)` returning normalized businesses with stable
  `sourceId` values

`GooglePlacesProvider` supplies business listings and `ApolloProvider` supplies
B2B people/contact records when `APOLLO_IO_API_KEY` is configured. Provider-
specific payloads are normalized before persistence; missing values remain
`null`, never invented. Apollo contact reveal is opt-in via
`APOLLO_IO_REVEAL_CONTACTS=1`, capped by `APOLLO_IO_REVEAL_LIMIT`, and buyer
mode persists only explicit `email_status=verified` work emails. Search results
are cached and identical in-flight searches are coalesced with a Redis lock.

## API

Base URL: `/api/v1/lead-discovery`

- `POST /api/v1/chat/respond` (public grounded website assistant)
- `GET /providers`
- `POST /search`
- `GET /leads`, `GET /leads/:id`
- `GET /collections`, `POST /collections`, `PATCH /collections/:id`,
  `DELETE /collections/:id`
- `GET/POST /collections/:id/leads`, `DELETE /collections/:id/leads/:leadId`
- `GET /summary`, `GET /pipeline`
- `PATCH /leads/:id/status`, `PATCH /leads/:id/owner`
- `GET/POST /leads/:id/notes`, `GET /leads/:id/activity`
- `GET /coverage`, `GET /history`
- `GET /duplicates`, `POST /duplicates/resolve`
- `POST /export`, `POST /export/preview`, `POST /export/csv`

All lead queries include the JWT organization ID. Writes require
`lead.write`; reads require `lead.read`. The primary uniqueness rule is
`organization_id + source + source_id`. Website, phone, and name/address
matches only create review candidates and never merge automatically.

## SEO and website assistant

The Next.js site reads `NEXT_PUBLIC_SITE_NAME`, `NEXT_PUBLIC_SITE_TITLE`,
`NEXT_PUBLIC_SITE_DESCRIPTION`, `NEXT_PUBLIC_SITE_URL`, `NEXT_PUBLIC_SITE_KEYWORDS`,
`NEXT_PUBLIC_OG_IMAGE` and `NEXT_PUBLIC_ROBOTS` for metadata, canonical URLs,
Open Graph/Twitter cards, `sitemap.xml`, `robots.txt` and the installable
manifest. The PHP/cPanel site reads the corresponding `VP_SITE_*`, `VP_OG_IMAGE`
and `VP_ROBOTS` variables and exposes `/sitemap.xml` and `/robots.txt`.

The assistant is available without login on both websites. It uses the
publicly documented local guide by default and can call an OpenAI-compatible
provider only when server-side `AI_CHAT_ENABLED=1`, `AI_CHAT_API_URL`,
`AI_CHAT_API_KEY` and `AI_CHAT_MODEL` are configured. It never receives private
lead/account records.

## Honest status

Only configured providers are marked usable. A missing Google API key is
`DISABLED`; a provider error is returned rather than converted into fake
businesses. CSV export prefixes formula-leading values (`=`, `+`, `-`, `@`)
and every export, status change, owner change, note, collection action, and
duplicate decision is recorded in `lead_activities` or `export_history`.

## Discovery Modes (PHP/cPanel build)

The `/leads` view (`application/views/leads/index.php`) ships with three modes:

1. **Business Mode** — keyword + country + city targeting. Example inputs:
   - Keywords: `Banking, Commercial Real Estate, Architecture`
   - Country: `Nigeria`, City: `Lagos`
   Works with both Windels G (business listings) and Windels A (B2B contacts with emails/phones).

2. **Person Mode** — first-name list + country + city. Results are **server-side
   filtered** to people whose email resolves to a free/personal webmail domain
   (`icloud.com`, `gmail.com`, `yahoo.com`, `outlook.com`, plus
   `hotmail.com`, `aol.com`, `proton.me`, `live.com`, `me.com`, `mail.com`,
   `gmx.com`, `yandex.com`). Requires Windels A (`APOLLO_IO_API_KEY`) because
   only Windels A returns people with personal emails. Name matching is a
   startswith prefix on the normalized contact name.

3. **Verified Buyer Email Mode** — a strict B2B contact search for crude-oil
   buyers and procurement decision-makers, with Australia as the default
   country. It requires Windels A and contact reveal. A row is persisted only
   when the provider returns a syntactically valid email **and** an explicit
   `email_status=verified` signal; buyer mode defaults to work/company domains.
   The platform never derives an address from a name, guesses a domain, or
   stores Apollo's masked/locked placeholders. If contact reveal is disabled,
   the search returns no buyer email rows and explains what the administrator
   must enable.

New API endpoints:
- `GET /modes` — returns the list of supported modes with descriptions.
- `POST /search` accepts `mode` (`business`|`person`|`buyer`), `keywords[]`,
  `country`, `city`, `names[]`, `titles[]`, `seniorities[]`, `provider`, and
  the strict-email options `verifiedEmailOnly`, `workEmailOnly` and
  `emailPolicy`. Persisted leads carry `lead_kind` (`business`|`person`),
  per-lead `email`, `job_title`, `company_name`, `linkedin_url`, and a
  truthful `verification_status` in metadata:
  - `verified` — Windels.ai-reported verified email/direct phone.
  - `partial_verified` — phone present but email not fully verified.
  - `provider_enriched` — data present but no provider-level verification signal.
  - `business_listing` — Windels G business listing (no person verification).

  We never claim "100% verified" globally; verification is per-lead and shown as a coloured pill.

## Cold Outreach (in-platform)

- `POST /leads/:id/outreach` accepts `channel` (`email`|`linkedin`|`note`|`call`), `subject`, `body`.
  - Inserts a row into `lead_outreach`.
  - Flips the lead's status to `contacted`.
  - Writes an `OUTREACH_SENT` activity.
  - For `email`, if a transport is configured (Resend via `RESEND_API_KEY`,
    Postmark via `POSTMARK_SERVER_TOKEN`, or SMTP via `SMTP_HOST`/`SMTP_PORT`/
    `SMTP_USER`/`SMTP_PASS`) the message is delivered immediately; otherwise
    it's stored as a `draft` for manual follow-up. Sender identity is taken
    from `OUTREACH_FROM_EMAIL`/`OUTREACH_FROM_NAME` (falling back to
    `MAIL_FROM_*`).
- `GET /leads/:id/outreach` lists past outreach on a lead.

The UI adds an **Outreach** button to each lead row and pipeline card, which prompts for channel/subject/body and posts to the endpoint.

## Honest status

Only configured providers are marked usable. A missing API key is
`DISABLED`; a provider error is returned rather than converted into fake
businesses. CSV export prefixes formula-leading values (`=`, `+`, `-`, `@`)
and every export, status change, owner change, note, collection action,
outreach send, and duplicate decision is recorded in `lead_activities`,
`lead_outreach`, or `export_history`.

AI qualification, enrichment, website analysis, and ICP matching are future phases.
No AI inference is currently presented as a fact. Verification is derived
exclusively from explicit provider signals (Apollo `email_status.verified`,
direct phone presence, Google Places listing) — never fabricated.

## Apollo.io provider (auth, endpoints, troubleshooting)

Docs: https://docs.apollo.io/reference/apollo-api — base URL
`https://api.apollo.io/api/v1`. Adapter:
`application/libraries/LeadDiscovery/ApolloProvider.php`; connection test:
`ApiProviders::testApollo()`.

### Authentication

- The API key is sent in the **`x-api-key` request header** on every call
  (https://docs.apollo.io/reference/authentication). The legacy
  `api_key`-in-JSON-body mechanism was retired in September 2024 and now fails.
- **Apollo keys are scoped per endpoint.** When a key is created you tick the
  endpoints it may call; any other endpoint answers **403**
  (https://docs.apollo.io/docs/create-api-key). Lead Discovery needs
  `mixed_people/api_search`, plus `people/bulk_match` when contact reveal is on.
  A **master key** ("Set as master key") covers every endpoint.
- Keys are read from the provider row (Admin → API → Lead Discovery → Apollo.io)
  or from `APOLLO_IO_API_KEY` / `APOLLO_API_KEY`.

### Connection test (Admin → API → *Test Connection*)

Two credit-free probes, run in order on **every** test:

1. `GET /api/v1/auth/health` — the documented key check
   (https://docs.apollo.io/docs/test-api-key); `200
   {"healthy":true,"is_logged_in":true}` confirms the key itself is valid.
   `401` here is final (the key is invalid/wrong). This alone is **not** a pass.
2. `POST /api/v1/mixed_people/api_search?per_page=1&q_keywords=apollo` — the
   endpoint Lead Discovery actually calls. Only this confirms the key/plan is
   permitted to use People Search. Apollo keys are **scoped per endpoint**
   (https://docs.apollo.io/docs/create-api-key), so a key can authenticate on
   `auth/health` yet answer **HTTP 403 API_INACCESSIBLE** here because the
   account/plan does not grant `mixed_people/api_search`. That is reported as
   **Failure** with a clear "enable/upgrade" message — never as **Connected**.

`429` and `5xx` are reported as rate limit / Apollo outage. A transport failure
reports *which* transport problem it was (TLS CA bundle, DNS, blocked egress)
instead of a bare "Connection failed". The key is never echoed back in a message
or a log.

`auth/health` must answer like Apollo: `is_logged_in=false`, `healthy=false` or
a non-JSON body (an intercepting proxy, a wrong Base URL) all report their own
reason rather than a false **Connected**. `422` on probe 2 counts as
authenticated — Apollo checked the key and only objected to the probe's
parameters, so the endpoint is accessible. Every message stays inside the 255
characters the provider row stores and is repeated under the badge on the API
dashboard, so the reason is visible without opening the provider.

### Endpoints used at runtime

| Purpose | Endpoint | Credits |
| --- | --- | --- |
| People search | `POST /api/v1/mixed_people/api_search` | 0 |
| Fallback for grandfathered keys only | `POST /api/v1/mixed_people/search` (deprecated, enforced off from 15 Dec 2025) | 0 |
| Contact reveal (opt-in) | `POST /api/v1/people/bulk_match` (≤ 10 ids per call) | 1 per record |

Filters are sent as **query parameters** in Apollo's bracket-array form
(`person_titles[]=…`, `person_seniorities[]=…`, `person_locations[]=…`,
`q_keywords`, `q_person_name`, `page`, `per_page`). A `401`/`403`/`400`/`422`
from the documented search is **not** retried against the deprecated one — the
deprecated route is only tried when Apollo says the documented route does not
exist for this key (`404/405/410`).

### Contact reveal (why Person Mode can return nothing)

`mixed_people/api_search` **never returns email addresses or phone numbers**
(https://docs.apollo.io/reference/people-api-search). Its rows are privacy-safe:
obfuscated surname (`last_name_obfuscated`), `has_email` / `has_direct_phone`
flags, and locked placeholders such as `email_not_unlocked@domain.com`, which the
adapter discards rather than storing as if they were real contacts
(`ApolloProvider::isUsableEmail()`).

Person Mode filters on free-webmail domains (gmail.com, outlook.com, …), i.e. on
**personal** emails, so it needs the documented enrichment endpoint:

- Off by default. Enable per provider in Admin → API (`reveal_contacts = 1`) or
  with `APOLLO_IO_REVEAL_CONTACTS=1`.
- `reveal_personal_emails` defaults to the reveal setting (personal addresses are
  what Person Mode filters on); `reveal_phone_number` defaults to **off** because
  phone reveals cost more credits.
- `reveal_limit` caps records enriched **per search** (default 25, max 100) and
  only rows Apollo flags with `has_email` / `has_direct_phone` are sent, so
  credits are not spent on records that hold nothing.
- A refusal (no credits, missing `people_bulk_match` scope, `429`) degrades to
  the privacy-safe rows and is recorded in the provider notes — the search never
  fails because enrichment was refused.
- Enriched rows are marked `metadata.enriched = true`, `privacy_safe = false`,
  and carry the revealed `email`, `email_status`, `phone`, `linkedin_url` and
  full name.

The `/leads/search` response reports the facts as `providerInfo`
(`results`, `revealEnabled`, `revealRequested`, `revealed`) and, when search rows
hold no contact data, a `notice` that says exactly which setting to change.
Internal notes stay in the CI error log; members never see connection internals.

### Confirming a verdict against the live API

To check what Apollo really answers for a key — instead of trusting the verdict
shown in Admin → API Management — run the probe CLI on the server:

```
APOLLO_IO_API_KEY=… php tools/apollo_probe.php          # add --reveal to also probe people/bulk_match
php tools/apollo_probe.php --key=… --base=https://api.apollo.io
```

It calls the same two credit-free endpoints the connection test uses
(`auth/health`, then `mixed_people/api_search`), prints each HTTP status and raw
body, and ends with a verdict that distinguishes an Apollo scope/plan refusal
from a proxy/WAF 403. The key is read from `--key`, the environment, or `.env`,
and is never printed.

### `✕ Connection failed` — what it means now

| Message on the provider page | Cause | Fix |
| --- | --- | --- |
| `An Apollo API key is required…` | no key saved | paste a key and save |
| `That is the masked placeholder…` | the masked value was saved back | retype the full key |
| `Invalid Apollo API key (HTTP 401…)` | key deleted/regenerated/expired | regenerate in Apollo → Settings → Integrations → API Keys |
| `HTTP 403 API_INACCESSIBLE: this Apollo key/plan cannot use the People Search endpoint…` | the key is valid (auth/health passed) but the key/plan is not permitted to call `mixed_people/api_search` — a scoped key without the `mixed_people_api_search` scope, a plan without API access, or a free account registered with a personal (gmail/outlook) email | grant the scope or toggle "Set as master key"; if the plan lacks API access, upgrade it (free accounts need a work-email signup). The test now runs the People Search probe even when auth/health passes, so this is reported as **Failure**, never **Connected**. |
| `HTTP 403 with a non-JSON body …` | the 403 carried an HTML/proxy page instead of Apollo's `{"error_code":"API_INACCESSIBLE"}` envelope, so the refusal came from a proxy/WAF on the egress path — not from Apollo | fix egress / the Base URL first; the key's scopes are probably fine |
| `Apollo says this key is not signed in…` | `auth/health` answered `is_logged_in=false` | regenerate the key; check the account's API plan |
| `Apollo reported this key as unhealthy…` | `auth/health` answered `healthy=false` | regenerate the key; check the account's API plan |
| `auth/health answered HTTP 200 with a non-JSON body…` | a proxy/firewall page intercepted the request, or the Base URL is not an Apollo API origin | clear the Base URL (or set it to `https://api.apollo.io`) and allow egress |
| `Apollo.io’s TLS certificate could not be verified…` | missing/outdated CA bundle on the host | point `curl.cainfo` / `openssl.cafile` at a current `cacert.pem` |
| `api.apollo.io does not resolve…` | DNS failure on the host | fix the server's resolver |
| `Outbound HTTPS to … is blocked by a firewall or timed out…` | egress to port 443 blocked or Apollo unreachable | allow egress to `api.apollo.io:443` |
| `Apollo rate limit reached (HTTP 429)…` | rate limit | retry in a minute (https://docs.apollo.io/reference/rate-limits) |
| `Apollo.io server error … (HTTP 5xx)` | Apollo outage | retry shortly (https://status.apollo.io) |
| *Lead Discovery:* `Apollo.io is configured but switched off…` | the provider row is disabled, so the runtime never sees the key | enable it in Admin → API Management |
| *Lead Discovery:* `this API key may not call that endpoint (HTTP 403…)` during a search | key lacks `mixed_people_api_search` (or `people_bulk_match` when reveal is on) | grant the scope, or toggle "Set as master key" |

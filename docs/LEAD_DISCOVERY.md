# WINDELS AI WORKFORCE — Lead Discovery & Sales Intelligence (formerly Scout)

> **User-facing brand:** WINDELS AI WORKFORCE. The internal code name "Scout" and the internal system name "AI_WORKFORCE" are retained for class names, env vars, and DB identifiers for backward compatibility.

`apps/api` and `apps/web` are the Lead Discovery module inside WINDELS AI WORKFORCE. They do not
use the CodeIgniter/AIWorkforce trading services directly. The only shared code is the typed
contract package at `packages/shared/src/leadDiscovery.ts`.

## Vertical slice

```text
Google Places → validate/normalize → PostgreSQL leads
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
# Set GOOGLE_PLACES_API_KEY for live discovery, then export the values:
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

`GooglePlacesProvider` is the first implementation. Provider-specific payloads
are normalized before persistence; missing values remain `null`, never
invented. Search results are cached and identical in-flight searches are
coalesced with a Redis lock.

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

The `/leads` view (`application/views/leads/index.php`) ships with two modes:

1. **Business Mode** — keyword + country + city targeting. Example inputs:
   - Keywords: `Banking, Commercial Real Estate, Architecture`
   - Country: `Nigeria`, City: `Lagos`
   Works with both Google Places (business listings) and Apollo.io (B2B contacts with emails/phones).

2. **Person Mode** — first-name list + country + city. Results are **server-side
   filtered** to people whose email resolves to a free/personal webmail domain
   (`icloud.com`, `gmail.com`, `yahoo.com`, `outlook.com`, plus
   `hotmail.com`, `aol.com`, `proton.me`, `live.com`, `me.com`, `mail.com`,
   `gmx.com`, `yandex.com`). Requires Apollo.io (`APOLLO_IO_API_KEY`) because
   only Apollo returns people with personal emails. Name matching is a
   startswith prefix on the normalized contact name.

New API endpoints:
- `GET /modes` — returns the list of supported modes with descriptions.
- `POST /search` accepts `mode` (`business`|`person`), `keywords[]`, `country`,
  `city`, `names[]`, `seniorities[]`, `provider`. Persisted leads carry
  `lead_kind` (`business`|`person`), per-lead `email`, `job_title`,
  `company_name`, `linkedin_url`, and a truthful `verification_status` in
  metadata:
  - `verified` — Apollo-reported verified email/direct phone.
  - `partial_verified` — phone present but email not fully verified.
  - `provider_enriched` — data present but no provider-level verification signal.
  - `business_listing` — Google Places business listing (no person verification).

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

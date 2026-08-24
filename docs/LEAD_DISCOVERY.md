# Scout — Standalone AI Lead Discovery & Sales Intelligence

`apps/api` and `apps/web` are an independent Lead Discovery product. They do not
use the CodeIgniter/Aegis trading services. The only shared code is the typed
contract package at `packages/shared/src/leadDiscovery.ts`.

## Vertical slice

```text
Google Places → validate/normalize → PostgreSQL leads
       → source-key deduplication → secondary duplicate review
       → collections → pipeline/status/owner/notes/activity
       → coverage/history → formula-safe JSON/CSV export
```

### Runtime

- **Web:** Next.js, React, TypeScript and Tailwind at `apps/web`.
- **API:** Fastify/TypeScript at `apps/api`.
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

## Honest status

Only configured providers are marked usable. A missing Google API key is
`DISABLED`; a provider error is returned rather than converted into fake
businesses. CSV export prefixes formula-leading values (`=`, `+`, `-`, `@`)
and every export, status change, owner change, note, collection action, and
duplicate decision is recorded in `lead_activities` or `export_history`.

AI qualification, enrichment, website analysis, ICP matching and outreach are
future phases. No AI inference is currently presented as a fact.

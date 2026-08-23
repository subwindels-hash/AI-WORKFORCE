import { createHash } from "node:crypto";
import type { FastifyInstance } from "fastify";
import { BusinessSearchInputSchema } from "../../../../packages/shared/src/leadDiscovery.js";
import { requireLeadAccess } from "../auth.js";
import { LeadRepository } from "../leadRepository.js";
import { GooglePlacesProvider } from "../providers/googlePlaces.js";

export async function discoveryRoutes(app: FastifyInstance): Promise<void> {
  app.post("/search", async (request) => {
    const principal = await requireLeadAccess(request); if (!principal.permissions.includes("lead.write")) throw Object.assign(new Error("forbidden"), { statusCode: 403 });
    const input = BusinessSearchInputSchema.parse(request.body); if (input.provider !== "google_places") throw Object.assign(new Error("provider is not implemented"), { statusCode: 422 });
    const provider = new GooglePlacesProvider(); const health = provider.health(); const started = performance.now(); const cacheKey = createHash("sha256").update(`${input.provider}:${input.query}:${input.limit}`).digest("hex");
    const lock = await app.operational.acquireLock(`search:${principal.organizationId}:${cacheKey}`, 30_000); if (!lock) throw Object.assign(new Error("an identical search is already in progress"), { statusCode: 409 });
    try {
      let businesses = await app.operational.getCached<Awaited<ReturnType<GooglePlacesProvider["search"]>>>(cacheKey);
      if (!businesses) { businesses = await provider.search(input.query, input.limit); await app.operational.cache(cacheKey, businesses, 300_000); }
      const repository = new LeadRepository(app.db); const saved = await Promise.all(businesses.map(business => repository.upsertDiscovery(principal.organizationId, provider.name, business))); const created = saved.filter(item => item.created).length; const candidates = (await Promise.all(saved.map(item => repository.detectSecondaryDuplicates(item.lead)))).reduce((sum, count) => sum + count, 0);
      await repository.recordSearch({ organizationId: principal.organizationId, userId: principal.sub, query: input.query, provider: provider.name, limit: input.limit, resultsReturned: businesses.length, newLeadsCreated: created, duplicatesDetected: businesses.length - created + candidates, durationMs: Math.round(performance.now() - started) });
      return { provider: provider.name, providerStatus: health.status, results: saved.map(item => item.lead), newLeadsCreated: created, duplicatesDetected: businesses.length - created + candidates, duplicateCandidatesCreated: candidates };
    } catch (error) {
      const message = error instanceof Error ? error.message : "discovery failed"; await new LeadRepository(app.db).recordSearch({ organizationId: principal.organizationId, userId: principal.sub, query: input.query, provider: provider.name, limit: input.limit, resultsReturned: 0, newLeadsCreated: 0, duplicatesDetected: 0, errors: message, durationMs: Math.round(performance.now() - started) }); throw error;
    } finally { await lock.release(); }
  });
}

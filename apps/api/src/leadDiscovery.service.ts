import { createHash } from "node:crypto";
import { BusinessSearchInputSchema, type ParsedBusinessSearchInput } from "../../../packages/shared/src/leadDiscovery.js";
import { LeadRepository } from "./leadRepository.js";
import type { LeadPrincipal } from "./auth.js";
import type { LeadOperationalStore } from "./redis.js";
import type { DiscoveredBusiness } from "./providers/leadDiscoveryProvider.js";
import { LeadDiscoveryProviderRegistry } from "./providers/providerRegistry.js";

const freeEmailDomains = new Set(["gmail.com", "yahoo.com", "outlook.com", "icloud.com", "hotmail.com", "aol.com", "proton.me", "live.com", "me.com", "mail.com", "gmx.com", "yandex.com"]);
const usableEmail = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const email = value.trim().toLowerCase();
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && !email.includes("*") && !email.includes("not_unlocked") && !email.endsWith(".invalid") ? email : null;
};
const verifiedEmailStatus = (value: unknown): boolean => {
  if (typeof value === "string") return value.trim().toLowerCase() === "verified";
  if (!value || typeof value !== "object" || Array.isArray(value)) return false;
  const object = value as Record<string, unknown>;
  if (object.verified === true) return true;
  return ["status", "email_status", "value"].some(key => typeof object[key] === "string" && object[key]!.trim().toLowerCase() === "verified")
    || Object.values(object).some(child => verifiedEmailStatus(child));
};
const workEmail = (email: string): boolean => {
  const domain = email.split("@").pop()?.toLowerCase() ?? "";
  return !freeEmailDomains.has(domain);
};

/** End-to-end discovery orchestration: validate, provider, normalize, persist, dedupe and ledger. */
export class LeadDiscoveryService {
  constructor(
    private readonly providers: LeadDiscoveryProviderRegistry,
    private readonly leads: LeadRepository,
    private readonly operational: LeadOperationalStore,
  ) {}

  async search(principal: LeadPrincipal, rawInput: unknown) {
    const input = BusinessSearchInputSchema.parse(rawInput);
    const provider = this.providers.get(input.provider);
    if (!provider) throw Object.assign(new Error(`provider ${input.provider} is not available`), { statusCode: 422 });
    const buyerMode = input.mode === "buyer";
    if (buyerMode && provider.name !== "apollo_io") throw Object.assign(new Error("verified buyer email mode requires the Apollo provider; business listings do not supply provider-verified emails"), { statusCode: 422 });
    const strictEmail = buyerMode || input.verifiedEmailOnly || input.emailPolicy === "verified" || input.emailPolicy === "verified_email" || input.emailPolicy === "verified_work_email";
    const requireWorkEmail = buyerMode ? input.workEmailOnly !== false : input.workEmailOnly === true;
    const started = performance.now();
    const cacheKey = this.cacheKey(input);
    const lock = await this.operational.acquireLock(`search:${principal.organizationId}:${cacheKey}`, 30_000);
    if (!lock) throw Object.assign(new Error("an identical search is already in progress"), { statusCode: 409 });
    try {
      const cached = await this.operational.getCached<DiscoveredBusiness[]>(cacheKey);
      const providerRows = cached ?? await provider.searchBusinesses(input);
      if (!cached) await this.operational.cache(cacheKey, providerRows, 300_000);
      const businesses = providerRows
        .map(row => {
          const email = usableEmail(row.metadata.email);
          const emailVerified = provider.name === "apollo_io" && verifiedEmailStatus(row.metadata.email_status);
          const metadata: Record<string, unknown> = { ...row.metadata, mode: input.mode, ...(input.country ? { country: input.country } : {}), ...(input.city ? { city: input.city } : {}) };
          if (email) metadata.email = email;
          if (strictEmail) {
            metadata.email_policy = requireWorkEmail ? "verified_work_email" : "verified_email";
            metadata.email_verified = emailVerified;
            metadata.email_source = provider.name;
          }
          return { ...row, metadata };
        })
        .filter(row => {
          if (!strictEmail) return true;
          const email = usableEmail(row.metadata.email);
          return email !== null && row.metadata.email_verified === true && (!requireWorkEmail || workEmail(email));
        });
      const verifiedEmailCount = strictEmail ? businesses.length : 0;
      const notice = buyerMode && providerRows.length > 0 && businesses.length === 0
        ? "No provider-verified Australian buyer work emails were returned. Contact reveal must be enabled for Apollo; no guessed or placeholder emails are accepted."
        : null;
      const unique = new Map<string, DiscoveredBusiness>();
      for (const business of businesses) if (!unique.has(`${provider.name}:${business.sourceId}`)) unique.set(`${provider.name}:${business.sourceId}`, business);
      const providerDuplicates = businesses.length - unique.size;
      const saved = await Promise.all([...unique.values()].map(business => this.leads.upsertDiscovery(principal.organizationId, provider.name, business)));
      const created = saved.filter(item => item.created).length;
      await Promise.all(saved.flatMap(item => [
        this.leads.recordActivity(principal.organizationId, principal.sub, item.lead.id, "LEAD_DISCOVERED", { provider: provider.name, sourceId: item.lead.sourceId, mode: input.mode }),
        ...(item.created ? [this.leads.recordActivity(principal.organizationId, principal.sub, item.lead.id, "LEAD_CREATED", { provider: provider.name, mode: input.mode })] : []),
      ]));
      const candidateCounts = await Promise.all(saved.map(async item => {
        const count = await this.leads.detectSecondaryDuplicates(item.lead);
        if (count > 0) await this.leads.recordActivity(principal.organizationId, principal.sub, item.lead.id, "DUPLICATE_DETECTED", { candidates: count });
        return count;
      }));
      const candidateCount = candidateCounts.reduce((sum, count) => sum + count, 0);
      const duplicatesDetected = businesses.length - created + candidateCount;
      const filters = { mode: input.mode, country: input.country ?? null, city: input.city ?? null, category: input.category ?? null, keywords: input.keywords ?? null, titles: input.titles ?? null, seniorities: input.seniorities ?? null, emailPolicy: strictEmail ? (requireWorkEmail ? "verified_work_email" : "verified_email") : null, limit: input.limit };
      await this.leads.recordSearch({
        organizationId: principal.organizationId, userId: principal.sub, query: input.query, provider: provider.name,
        filters, resultsReturned: businesses.length, newLeadsCreated: created, duplicatesDetected, durationMs: Math.round(performance.now() - started),
      });
      return {
        provider: provider.name, providerStatus: provider.health().status, results: saved.map(item => item.lead),
        newLeadsCreated: created, duplicatesDetected, duplicateCandidatesCreated: candidateCount,
        providerDuplicateRows: providerDuplicates, verifiedEmailCount, emailPolicy: strictEmail ? (requireWorkEmail ? "verified_work_email" : "verified_email") : undefined, notice,
      };
    } catch (error) {
      const message = error instanceof Error ? error.message : "discovery failed";
      try {
        await this.leads.recordSearch({
          organizationId: principal.organizationId, userId: principal.sub, query: input.query, provider: provider.name,
          filters: { mode: input.mode, country: input.country ?? null, city: input.city ?? null, category: input.category ?? null, limit: input.limit },
          resultsReturned: 0, newLeadsCreated: 0, duplicatesDetected: 0, errors: message,
          durationMs: Math.round(performance.now() - started),
        });
      } catch { /* preserve the provider/database error; the ledger failure is observable in application logs */ }
      throw error;
    } finally { await lock.release(); }
  }

  private cacheKey(input: ParsedBusinessSearchInput): string {
    return createHash("sha256").update(JSON.stringify({
      provider: input.provider, mode: input.mode, query: input.query, limit: input.limit, country: input.country ?? null, city: input.city ?? null,
      category: input.category ?? null, keywords: input.keywords ?? null, names: input.names ?? null, titles: input.titles ?? null, seniorities: input.seniorities ?? null,
      verifiedEmailOnly: input.verifiedEmailOnly, workEmailOnly: input.workEmailOnly, emailPolicy: input.emailPolicy ?? null,
    })).digest("hex");
  }
}

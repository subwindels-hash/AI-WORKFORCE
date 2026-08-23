import type { FastifyInstance } from "fastify";
import { z } from "zod";
import { requireLeadAccess } from "../auth.js";

const LeadListQuery = z.object({ status: z.enum(["new", "contacted", "qualified", "disqualified", "converted"]).optional(), limit: z.coerce.number().int().min(1).max(250).default(100) });
const CoverageQuery = z.object({ missing: z.enum(["name", "address", "category", "phone", "website"]).optional() });
const columns = "id, organization_id, source, source_id, name, category, address, city, region, country, phone, website, latitude, longitude, status, owner_id, metadata, created_at, updated_at";
const lead = (row: Record<string, unknown>) => ({ id: row.id, organizationId: row.organization_id, source: row.source, sourceId: row.source_id, name: row.name, category: row.category, address: row.address, city: row.city, region: row.region, country: row.country, phone: row.phone, website: row.website, latitude: row.latitude, longitude: row.longitude, status: row.status, ownerId: row.owner_id, metadata: row.metadata, createdAt: row.created_at, updatedAt: row.updated_at });

export async function leadRoutes(app: FastifyInstance): Promise<void> {
  app.get("/leads", async (request) => {
    const principal = await requireLeadAccess(request); const query = LeadListQuery.parse(request.query);
    const result = await app.db.query(`SELECT ${columns} FROM leads WHERE organization_id = $1 ${query.status ? "AND status = $2" : ""} ORDER BY updated_at DESC LIMIT $${query.status ? 3 : 2}`, query.status ? [principal.organizationId, query.status, query.limit] : [principal.organizationId, query.limit]);
    return { leads: result.rows.map(lead) };
  });
  app.get("/coverage", async (request) => {
    const principal = await requireLeadAccess(request); const query = CoverageQuery.parse(request.query);
    const fields = ["name", "address", "category", "phone", "website"] as const;
    const aggregate = await app.db.query(`SELECT COUNT(*)::int AS total, ${fields.map(f => `COUNT(NULLIF(${f}, ''))::int AS ${f}_filled`).join(", ")} FROM leads WHERE organization_id=$1`, [principal.organizationId]);
    const total = Number(aggregate.rows[0]?.total ?? 0);
    const labels: Record<(typeof fields)[number], string> = { name: "Business Name", address: "Address", category: "Category", phone: "Phone", website: "Website" };
    const response = { leadCount: total, fields: fields.map(key => { const filled = Number(aggregate.rows[0]?.[`${key}_filled`] ?? 0); return { key, field: labels[key], coverage: total ? Math.round((filled / total) * 1000) / 10 : 0, missing: total - filled }; }), missingField: query.missing ?? null, missingLeads: [] as unknown[] };
    if (query.missing) { const missing = await app.db.query(`SELECT ${columns} FROM leads WHERE organization_id=$1 AND NULLIF(${query.missing}, '') IS NULL ORDER BY updated_at DESC LIMIT 250`, [principal.organizationId]); response.missingLeads = missing.rows.map(lead); }
    return response;
  });
}

declare module "fastify" { interface FastifyInstance { db: import("../db.js").Database } }

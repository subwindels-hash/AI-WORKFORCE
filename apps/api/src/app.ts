import Fastify from "fastify";
import cors from "@fastify/cors";
import jwt from "@fastify/jwt";
import type { Database } from "./db.js";
import { LeadOperationalStore, type RedisClient } from "./redis.js";
import { leadRoutes } from "./routes/leads.js";
import { discoveryRoutes } from "./routes/discovery.js";
import { pipelineRoutes } from "./routes/pipeline.js";
import { intelligenceRoutes } from "./routes/intelligence.js";
import { authRoutes } from "./routes/auth.js";
import { parseAllowedOrigins } from "./config.js";

export async function buildApp(options: { db: Database; jwtSecret: string; redis: RedisClient; corsOrigins?: string[] }) {
  const app = Fastify({ logger: true });
  const origins = options.corsOrigins ?? parseAllowedOrigins();
  await app.register(cors, { origin: (origin, callback) => callback(null, !origin || origins.includes(origin.replace(/\/$/, ""))), credentials: true, methods: ["GET", "POST", "PATCH", "DELETE", "OPTIONS"] });
  app.decorate("db", options.db); app.decorate("operational", new LeadOperationalStore(options.redis));
  app.addHook("onRequest", async (request) => {
    const rate = await app.operational.consumeRateLimit(`ip:${request.ip}`, 120, 60_000);
    if (!rate.allowed) { const error = Object.assign(new Error("rate limit exceeded"), { statusCode: 429 }); throw error; }
  });
  await app.register(jwt, { secret: options.jwtSecret });
  app.get("/health", async () => ({ ok: true, service: "lead-discovery-api" }));
  app.get("/ready", async (_request, reply) => { try { await app.db.query("SELECT 1"); return { ok: true, database: "ready" }; } catch { return reply.code(503).send({ ok: false, database: "unavailable" }); } });
  app.setErrorHandler((error, _request, reply) => {
    const known = error as { statusCode?: unknown; message?: unknown };
    const status = typeof known.statusCode === "number" ? known.statusCode : 500;
    reply.code(status).send({ error: status >= 500 ? "internal server error" : typeof known.message === "string" ? known.message : "request failed" });
  });
  await app.register(authRoutes, { prefix: "/api/v1/auth" });
  await app.register(leadRoutes, { prefix: "/api/v1/lead-discovery" });
  await app.register(discoveryRoutes, { prefix: "/api/v1/lead-discovery" });
  await app.register(pipelineRoutes, { prefix: "/api/v1/lead-discovery" });
  await app.register(intelligenceRoutes, { prefix: "/api/v1/lead-discovery" });
  return app;
}
declare module "fastify" { interface FastifyInstance { operational: LeadOperationalStore } }

import Fastify from "fastify";
import jwt from "@fastify/jwt";
import type { Database } from "./db.js";
import { leadRoutes } from "./routes/leads.js";

export async function buildApp(options: { db: Database; jwtSecret: string }) {
  const app = Fastify({ logger: true });
  app.decorate("db", options.db);
  await app.register(jwt, { secret: options.jwtSecret });
  app.setErrorHandler((error, _request, reply) => {
    const known = error as { statusCode?: unknown; message?: unknown };
    const status = typeof known.statusCode === "number" ? known.statusCode : 500;
    reply.code(status).send({ error: status >= 500 ? "internal server error" : typeof known.message === "string" ? known.message : "request failed" });
  });
  await app.register(leadRoutes, { prefix: "/api/v1/lead-discovery" });
  return app;
}

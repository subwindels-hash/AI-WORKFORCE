import bcrypt from "bcryptjs";
import type { FastifyInstance } from "fastify";
import { z } from "zod";
const LoginSchema = z.object({ email: z.string().trim().email().max(190), password: z.string().min(1).max(512), organizationId: z.string().uuid().optional() });
const permissionsFor = (role: string) => role === "owner" || role === "admin" ? ["lead.read", "lead.write"] : ["lead.read"];
export async function authRoutes(app: FastifyInstance): Promise<void> {
  app.post("/login", async request => {
    const input = LoginSchema.parse(request.body); const user = await app.db.query("SELECT id,email,password_hash,display_name,active FROM users WHERE lower(email)=lower($1) LIMIT 1", [input.email]); const account = user.rows[0];
    if (!account || account.active !== true || typeof account.password_hash !== "string" || !(await bcrypt.compare(input.password, account.password_hash))) throw Object.assign(new Error("invalid email or password"), { statusCode: 401 });
    const memberships = await app.db.query("SELECT organization_id,role FROM organization_members WHERE user_id=$1", [account.id]); const membership = input.organizationId ? memberships.rows.find(row => row.organization_id === input.organizationId) : memberships.rows[0];
    if (!membership) throw Object.assign(new Error("no organization membership available"), { statusCode: 403 });
    const permissions = permissionsFor(String(membership.role)); const token = app.jwt.sign({ sub: String(account.id), organizationId: String(membership.organization_id), permissions }, { expiresIn: "8h" });
    return { token, user: { id: account.id, email: account.email, displayName: account.display_name }, organizationId: membership.organization_id, permissions };
  });
  app.get("/me", async request => { await request.jwtVerify(); return { user: request.user }; });
}

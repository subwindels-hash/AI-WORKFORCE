# Cloudflare Agents architecture

This project keeps the existing PHP application as the business/control plane:
users, authentication, RBAC, billing, application data, broker authorization,
approvals and audit logs remain authoritative here. Cloudflare Agents is an
optional intelligence/runtime plane for durable agent sessions, WebSockets,
scheduling, Workflows, MCP, Browser, Sandbox, Workers AI and AI Gateway.

## Current boundary

`AIWorkforce\\Agents\\AgentOrchestrator` is the server-side boundary. It:

- dispatches work to named specialist agents;
- assigns an execution ID;
- records completion/failure through the existing audit callback;
- allowlists external tools;
- blocks broker order submission and lottery purchase behind approval;
- supports permission-scoped tool access.

This prevents a model or remote agent from bypassing the existing execution
supervisor, risk engine, kill switch, RBAC, or lottery compliance boundary.

## Planned specialist registrations

- `market` — crypto and forex adapters
- `sports` — fixtures, results, statistics and match intelligence
- `lead_discovery` — provider/MCP/browser-backed lead discovery
- `lottery` — results and statistical intelligence only
- `language` — tutor, STT, TTS and pronunciation services
- `trading` — intelligence and risk analysis only
- `video` — asynchronous generation jobs
- `general` — user-facing grounded assistant

Agents should call standardized MCP/tool contracts rather than vendor-specific
code. Long-running work belongs in Cloudflare Workflows; untrusted code belongs
in Cloudflare Sandbox, never in the PHP request process.

## Security rules

Sensitive tools such as `broker.submitTrade` and `lottery.purchaseTicket` always
return an approval-required response. The existing backend must approve and
execute those actions. Agent state must contain session/task state only, not the
application database or plaintext provider secrets.

Cloudflare credentials are configured through the existing Super Admin → API
provider dashboard. Cloudflare Workers AI can be selected for LLM, language AI,
and translation, with encrypted tokens and primary/fallback roles.

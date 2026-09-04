# Cloudflare AI provider

Cloudflare Workers AI is available as an AI provider for the `llm` and `language_ai` services in Admin → API. It supports server-side chat generation through Workers AI models and optional AI Gateway routing.

Configure a provider with:

- Account ID
- Restricted API token with Workers AI permission
- Model, for example `@cf/meta/llama-3.1-8b-instruct`
- Optional AI Gateway name

If no base URL is supplied, WINDELS calls the Workers AI REST endpoint. If a gateway is supplied, requests use the Cloudflare AI Gateway Workers AI route. The token is encrypted at rest and masked in the Super Admin dashboard.

Cloudflare is used for AI inference and observability only. It does not receive broker credentials, execute trades, approve tickets, or bypass the execution supervisor. Trading remains behind the existing risk engine, kill switch, and approval controls.

The provider is visible in the Super Admin API dashboard with Connected / Connection failed status and can be assigned as primary or fallback for supported AI services.

import test from "node:test";
import assert from "node:assert/strict";
import { buildApp } from "../src/app.js";
import type { RedisClient } from "../src/redis.js";

class R implements RedisClient { private values = new Map<string, string>(); async get(k: string) { return this.values.get(k) ?? null; } async set(k: string, v: string, ...args: (string | number)[]) { if (args.includes("NX") && this.values.has(k)) return null; this.values.set(k, v); return "OK"; } async incr(k: string) { const n = Number(this.values.get(k) ?? 0) + 1; this.values.set(k, String(n)); return n; } async pexpire() { return 1; } async pttl() { return 1000; } async del(k: string) { return this.values.delete(k) ? 1 : 0; } async eval(_script: string, _keys: number, ...args: string[]) { return this.values.get(args[0] ?? "") === args[1] ? this.del(args[0] ?? "") : 0; } async lpush() { return 1; } async quit() { return "OK"; } }

test("public website chat responds without authentication using grounded fallback guidance", async () => {
  const db = { query: async () => ({ rows: [] }), end: async () => undefined };
  const app = await buildApp({ db, redis: new R(), jwtSecret: "a secure test secret that is longer than thirty two characters" });
  const response = await app.inject({ method: "POST", url: "/api/v1/chat/respond", payload: { message: "How do I export leads?" } });
  assert.equal(response.statusCode, 200);
  assert.equal(response.json().provider, "local-guide");
  assert.match(response.json().message, /export/i);
  const invalid = await app.inject({ method: "POST", url: "/api/v1/chat/respond", payload: { message: "" } });
  assert.equal(invalid.statusCode, 400);
  await app.close();
});

test("chat responds via configured AI provider when environment variables are set", async () => {
  const originalFetch = globalThis.fetch;
  const prevUrl = process.env.AI_CHAT_API_URL;
  const prevKey = process.env.AI_CHAT_API_KEY;
  const prevModel = process.env.AI_CHAT_MODEL;
  const prevEnabled = process.env.AI_CHAT_ENABLED;

  try {
    process.env.AI_CHAT_ENABLED = "1";
    process.env.AI_CHAT_API_URL = "https://api.x.ai/v1/chat/completions";
    process.env.AI_CHAT_API_KEY = "xai-test-key";
    process.env.AI_CHAT_MODEL = "grok-2-latest";

    globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      assert.equal(url, "https://api.x.ai/v1/chat/completions");
      const headers = init?.headers as Record<string, string>;
      assert.equal(headers.authorization, "Bearer xai-test-key");
      const body = JSON.parse(String(init?.body));
      assert.equal(body.model, "grok-2-latest");
      assert.equal(body.messages[body.messages.length - 1].content, "Where is the trading module?");
      return new Response(JSON.stringify({
        choices: [{ message: { content: "Trading is at /app/trading with risk controls." } }],
      }), { status: 200, headers: { "content-type": "application/json" } });
    };

    const db = { query: async () => ({ rows: [] }), end: async () => undefined };
    const app = await buildApp({ db, redis: new R(), jwtSecret: "a secure test secret that is longer than thirty two characters" });
    const res = await app.inject({ method: "POST", url: "/api/v1/chat/respond", payload: { message: "Where is the trading module?" } });
    assert.equal(res.statusCode, 200);
    const json = res.json();
    assert.equal(json.provider, "configured-ai");
    assert.equal(json.message, "Trading is at /app/trading with risk controls.");
    assert.equal(json.grounded, true);
    await app.close();
  } finally {
    globalThis.fetch = originalFetch;
    if (prevUrl !== undefined) process.env.AI_CHAT_API_URL = prevUrl; else delete process.env.AI_CHAT_API_URL;
    if (prevKey !== undefined) process.env.AI_CHAT_API_KEY = prevKey; else delete process.env.AI_CHAT_API_KEY;
    if (prevModel !== undefined) process.env.AI_CHAT_MODEL = prevModel; else delete process.env.AI_CHAT_MODEL;
    if (prevEnabled !== undefined) process.env.AI_CHAT_ENABLED = prevEnabled; else delete process.env.AI_CHAT_ENABLED;
  }
});

import test from "node:test";
import assert from "node:assert/strict";
import { ApolloProvider } from "../src/providers/apollo.js";

const input = {
  query: "crude oil buyer Australia",
  mode: "buyer" as const,
  provider: "apollo_io",
  limit: 20,
  verifiedEmailOnly: true,
  workEmailOnly: true,
};

test("Apollo provider never stores masked or locked email placeholders", async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => new Response(JSON.stringify({ people: [
    { id: "locked", first_name: "A", last_name_obfuscated: "B***", email: "email_not_unlocked@apollo.io", has_email: true, organization: { name: "Locked Co" } },
    { id: "masked", first_name: "C", last_name_obfuscated: "D***", email: "c***@example.com", has_email: true, organization: { name: "Masked Co" } },
  ] }), { status: 200 });
  try {
    const rows = await new ApolloProvider("test-key", "https://api.apollo.io").searchBusinesses(input);
    assert.equal(rows.length, 2);
    assert.equal(rows.every(row => row.metadata.email == null), true);
    assert.equal(rows.every(row => row.metadata.privacy_safe === true), true);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test("Apollo contact reveal merges only provider-returned contact data", async () => {
  const originalFetch = globalThis.fetch;
  const originalReveal = process.env.APOLLO_IO_REVEAL_CONTACTS;
  process.env.APOLLO_IO_REVEAL_CONTACTS = "1";
  let calls = 0;
  globalThis.fetch = async (_url, init) => {
    calls += 1;
    if (calls === 1) return new Response(JSON.stringify({ people: [{ id: "person-1", first_name: "Jane", last_name_obfuscated: "D***", title: "Crude Oil Buyer", has_email: true, organization: { name: "Refinery Co" } }] }), { status: 200 });
    const body = JSON.parse(String(init?.body ?? "{}")) as { details?: Array<{ id: string }> };
    assert.deepEqual(body.details, [{ id: "person-1" }]);
    return new Response(JSON.stringify({ matches: [{ id: "person-1", name: "Jane Doe", email: "buyer@refinery.example", email_status: "verified", organization: { name: "Refinery Co" } }] }), { status: 200 });
  };
  try {
    const rows = await new ApolloProvider("test-key", "https://api.apollo.io").searchBusinesses(input);
    assert.equal(calls, 2);
    assert.equal(rows[0]?.metadata.email, "buyer@refinery.example");
    assert.equal(rows[0]?.metadata.email_status, "verified");
    assert.equal(rows[0]?.metadata.enriched, true);
  } finally {
    globalThis.fetch = originalFetch;
    if (originalReveal === undefined) delete process.env.APOLLO_IO_REVEAL_CONTACTS;
    else process.env.APOLLO_IO_REVEAL_CONTACTS = originalReveal;
  }
});

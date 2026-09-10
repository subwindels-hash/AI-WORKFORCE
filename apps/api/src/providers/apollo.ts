import type { ParsedBusinessSearchInput, ProviderHealth } from "../../../../packages/shared/src/leadDiscovery.js";
import type { DiscoveredBusiness, LeadDiscoveryProvider } from "./leadDiscoveryProvider.js";

/**
 * Apollo people search adapter for the standalone Lead Discovery API.
 *
 * Search rows intentionally contain no contact data on Apollo's public
 * endpoint. Contact reveal is opt-in, credit-capped and only stores values
 * returned by Apollo; names and domains are never used to invent an address.
 */
export class ApolloProvider implements LeadDiscoveryProvider {
  readonly name = "apollo_io";
  private readonly baseUrl: string;
  private readonly revealContacts: boolean;
  private readonly revealPersonalEmails: boolean;
  private readonly revealPhoneNumber: boolean;
  private readonly revealLimit: number;

  constructor(
    private readonly apiKey = process.env.APOLLO_IO_API_KEY ?? process.env.APOLLO_API_KEY,
    baseUrl = process.env.APOLLO_IO_API_BASE ?? process.env.APOLLO_API_BASE ?? "https://api.apollo.io",
    private readonly timeoutMs = 15_000,
  ) {
    this.baseUrl = ApolloProvider.normalizeBase(baseUrl);
    this.revealContacts = ApolloProvider.flag(process.env.APOLLO_IO_REVEAL_CONTACTS, false);
    this.revealPersonalEmails = ApolloProvider.flag(process.env.APOLLO_IO_REVEAL_PERSONAL_EMAILS, this.revealContacts);
    this.revealPhoneNumber = ApolloProvider.flag(process.env.APOLLO_IO_REVEAL_PHONE, false);
    const configuredLimit = Number(process.env.APOLLO_IO_REVEAL_LIMIT ?? 25);
    this.revealLimit = Number.isFinite(configuredLimit) ? Math.max(0, Math.min(100, Math.trunc(configuredLimit))) : 25;
  }

  health(): ProviderHealth {
    return this.apiKey?.trim()
      ? {
          name: this.name,
          status: "IMPLEMENTED",
          detail: `Apollo.io people search configured; contact reveal ${this.revealContacts ? "on (spends credits)" : "off (search rows carry no emails)"}`,
        }
      : { name: this.name, status: "DISABLED", detail: "APOLLO_IO_API_KEY is not configured" };
  }

  async searchBusinesses(input: ParsedBusinessSearchInput): Promise<DiscoveredBusiness[]> {
    if (!this.apiKey?.trim()) throw Object.assign(new Error("Apollo.io is disabled: configure APOLLO_IO_API_KEY"), { statusCode: 503 });
    const params = this.buildParams(input);
    const payload = await this.fetchPeople(params);
    let rows = this.normalize(payload);
    if (this.revealContacts && rows.length) rows = await this.reveal(rows);
    return rows;
  }

  private buildParams(input: ParsedBusinessSearchInput): URLSearchParams {
    const params = new URLSearchParams({ page: "1", per_page: String(Math.min(100, Math.max(1, input.limit))) });
    if (input.query.trim()) params.set("q_keywords", input.query.trim());
    const append = (key: string, values: string[] | undefined) => {
      for (const value of values ?? []) if (value.trim()) params.append(`${key}[]`, value.trim());
    };
    append("person_locations", input.city ? [`${input.city}, ${input.country ?? ""}`.replace(/, $/, "")] : input.country ? [input.country] : undefined);
    append("person_titles", input.titles);
    append("person_seniorities", input.seniorities);
    if (input.mode === "person" && input.names?.length === 1) params.set("q_person_name", input.names[0]!);
    if (input.verifiedEmailOnly || input.mode === "buyer") append("contact_email_status", ["verified"]);
    return params;
  }

  private async fetchPeople(params: URLSearchParams): Promise<Record<string, unknown>> {
    try {
      return await this.request("/api/v1/mixed_people/api_search", params);
    } catch (error) {
      const status = Number((error as { statusCode?: unknown }).statusCode ?? 0);
      if (![404, 405, 410].includes(status)) throw error;
      return this.request("/api/v1/mixed_people/search", params);
    }
  }

  private async request(path: string, params: URLSearchParams, body?: string): Promise<Record<string, unknown>> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const response = await fetch(`${this.baseUrl}${path}?${params.toString()}`, {
        method: "POST",
        signal: controller.signal,
        headers: {
          accept: "application/json",
          "content-type": "application/json",
          "cache-control": "no-cache",
          "x-api-key": this.apiKey ?? "",
          "user-agent": "WINDELS-AIWorkforce/1.0 (+lead-discovery)",
        },
        body: body ?? "{}",
      });
      const payload: unknown = await response.json().catch(() => null);
      if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
        throw Object.assign(new Error(`Apollo.io returned a non-JSON response (HTTP ${response.status})`), { statusCode: response.status || 502, retryable: response.status === 429 || response.status >= 500 });
      }
      const object = payload as Record<string, unknown>;
      const statusValue = typeof object.status === "string" ? object.status.toLowerCase() : "";
      const hasData = ["people", "contacts", "matches", "person", "organizations"].some(key => key in object);
      const message = ApolloProvider.errorDetail(object);
      if (!response.ok || (!hasData && ["error", "failed", "unauthorized", "forbidden"].includes(statusValue))) {
        const error = Object.assign(new Error(message || `Apollo.io request failed (HTTP ${response.status})`), {
          statusCode: response.status || 502,
          retryable: response.status === 429 || response.status >= 500,
        });
        throw error;
      }
      return object;
    } catch (error) {
      if ((error as { name?: string }).name === "AbortError") throw Object.assign(new Error("Apollo.io request timed out"), { statusCode: 504, retryable: true });
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  }

  private normalize(payload: Record<string, unknown>): DiscoveredBusiness[] {
    const people = Array.isArray(payload.people) ? payload.people : Array.isArray(payload.contacts) ? payload.contacts : [];
    return people.flatMap(value => {
      if (!value || typeof value !== "object" || Array.isArray(value)) return [];
      const person = value as Record<string, unknown>;
      const id = typeof person.id === "string" ? person.id.trim() : "";
      if (!id) return [];
      const organization = ApolloProvider.object(person.organization);
      const first = typeof person.first_name === "string" ? person.first_name : "";
      const last = typeof person.last_name === "string" ? person.last_name : typeof person.last_name_obfuscated === "string" ? person.last_name_obfuscated : "";
      const company = typeof organization.name === "string" ? organization.name : typeof person.organization_name === "string" ? person.organization_name : "";
      const email = typeof person.email === "string" && ApolloProvider.isUsableEmail(person.email) ? person.email.trim().toLowerCase() : null;
      const phone = ApolloProvider.firstPhone(person);
      const city = typeof person.city === "string" ? person.city : typeof organization.city === "string" ? organization.city : null;
      const country = typeof person.country === "string" ? person.country : typeof organization.country === "string" ? organization.country : null;
      const title = typeof person.title === "string" ? person.title : typeof person.headline === "string" ? person.headline : "";
      const linkedin = typeof person.linkedin_url === "string" ? person.linkedin_url : null;
      const hasEmail = typeof person.has_email === "boolean" ? person.has_email : email !== null;
      const hasPhone = typeof person.has_direct_phone === "boolean" ? person.has_direct_phone : typeof person.has_direct_phone === "string" ? person.has_direct_phone.toLowerCase().startsWith("yes") : phone !== null;
      const metadata: Record<string, unknown> = {
        provider: "Windels A",
        source: "apollo",
        person_id: id,
        has_email: hasEmail,
        has_direct_phone: hasPhone,
        privacy_safe: email === null && phone === null && linkedin === null,
        ...(title ? { title } : {}),
        ...(company ? { company } : {}),
        ...(email ? { email } : {}),
        ...(person.email_status != null ? { email_status: person.email_status } : {}),
        ...(linkedin ? { linkedin_url: linkedin } : {}),
        ...(organization.industry ? { industry: organization.industry } : {}),
      };
      return [{
        sourceId: `apollo:${id}`,
        name: `${first} ${last}`.trim() || company || "Unknown",
        category: title || (typeof organization.industry === "string" ? organization.industry : "business"),
        address: [city, typeof person.state === "string" ? person.state : typeof organization.state === "string" ? organization.state : null, country].filter(Boolean).join(", ") || null,
        city,
        region: typeof person.state === "string" ? person.state : typeof organization.state === "string" ? organization.state : null,
        country,
        phone,
        website: ApolloProvider.safeUrl(typeof organization.website_url === "string" ? organization.website_url : typeof organization.primary_domain === "string" ? `https://${organization.primary_domain}` : null),
        latitude: null,
        longitude: null,
        metadata,
      }];
    });
  }

  private async reveal(rows: DiscoveredBusiness[]): Promise<DiscoveredBusiness[]> {
    const candidates = rows.filter(row => Boolean(row.metadata.person_id) && (row.metadata.has_email === true || row.metadata.has_direct_phone === true)).slice(0, this.revealLimit);
    for (let start = 0; start < candidates.length; start += 10) {
      const batch = candidates.slice(start, start + 10);
      const details = batch.map(row => ({ id: String(row.metadata.person_id) }));
      try {
        const params = new URLSearchParams({
          reveal_personal_emails: String(this.revealPersonalEmails),
          reveal_phone_number: String(this.revealPhoneNumber),
        });
        const payload = await this.request("/api/v1/people/bulk_match", params, JSON.stringify({ details }));
        const matches = Array.isArray(payload.matches) ? payload.matches : [];
        const byId = new Map(matches.filter(item => item && typeof item === "object" && !Array.isArray(item)).map(item => [String((item as Record<string, unknown>).id ?? ""), item as Record<string, unknown>]));
        for (const row of batch) {
          const match = byId.get(String(row.metadata.person_id));
          if (match) this.merge(row, match);
        }
      } catch {
        // A missing enrichment scope, no credits, or a rate limit must not
        // turn a valid privacy-safe search into fabricated contact data.
        break;
      }
    }
    return rows;
  }

  private merge(row: DiscoveredBusiness, person: Record<string, unknown>): void {
    const email = typeof person.email === "string" && ApolloProvider.isUsableEmail(person.email) ? person.email.trim().toLowerCase() : null;
    if (email) row.metadata.email = email;
    if (person.email_status != null) row.metadata.email_status = person.email_status;
    const phone = ApolloProvider.firstPhone(person);
    if (phone) row.phone = phone;
    const name = typeof person.name === "string" ? person.name : `${String(person.first_name ?? "")} ${String(person.last_name ?? "")}`.trim();
    if (name && !name.includes("*")) row.name = name;
    const organization = ApolloProvider.object(person.organization);
    if (typeof organization.name === "string" && organization.name) row.metadata.company = organization.name;
    if (typeof person.linkedin_url === "string" && person.linkedin_url) row.metadata.linkedin_url = person.linkedin_url;
    row.metadata.enriched = true;
    row.metadata.has_email = Boolean(row.metadata.email);
    row.metadata.has_direct_phone = Boolean(row.phone);
    row.metadata.privacy_safe = !row.metadata.has_email && !row.metadata.has_direct_phone;
  }

  static isUsableEmail(value: string): boolean {
    const email = value.trim().toLowerCase();
    return Boolean(email) && !email.includes("*") && !email.includes(" ") && !email.includes("not_unlocked") && !email.endsWith(".invalid") && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  static isVerifiedEmailStatus(value: unknown): boolean {
    if (typeof value === "string") return value.trim().toLowerCase() === "verified";
    if (!value || typeof value !== "object" || Array.isArray(value)) return false;
    const object = value as Record<string, unknown>;
    if (object.verified === true) return true;
    return ["status", "email_status", "value"].some(key => typeof object[key] === "string" && object[key]!.trim().toLowerCase() === "verified")
      || Object.values(object).some(child => ApolloProvider.isVerifiedEmailStatus(child));
  }

  static normalizeBase(value: string): string {
    let base = value.trim().replace(/\/$/, "") || "https://api.apollo.io";
    if (!/^https?:\/\//i.test(base)) base = `https://${base}`;
    base = base.replace(/^http:\/\//i, "https://").replace(/\/api\/v\d+$/i, "");
    try {
      const url = new URL(base);
      if (["apollo.io", "www.apollo.io", "app.apollo.io", "docs.apollo.io", "developer.apollo.io"].includes(url.hostname.toLowerCase())) return "https://api.apollo.io";
      return url.toString().replace(/\/$/, "");
    } catch {
      return "https://api.apollo.io";
    }
  }

  private static flag(value: string | undefined, fallback: boolean): boolean {
    if (value == null || value.trim() === "") return fallback;
    return ["1", "true", "on", "yes", "y"].includes(value.trim().toLowerCase());
  }

  private static object(value: unknown): Record<string, any> {
    return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, any> : {};
  }

  private static firstPhone(person: Record<string, unknown>): string | null {
    for (const key of ["phone_number", "sanitized_phone", "direct_dial_phone", "mobile_phone", "raw_phone"]) {
      const value = person[key];
      if (typeof value === "string" && value.trim() && !value.includes("*")) return value.trim();
    }
    return null;
  }

  private static safeUrl(value: string | null): string | null {
    if (!value) return null;
    try {
      const url = new URL(value);
      return ["http:", "https:"].includes(url.protocol) ? url.toString() : null;
    } catch {
      return null;
    }
  }

  private static errorDetail(payload: Record<string, unknown>): string {
    for (const key of ["error_message", "message", "error_code", "code", "error"]) {
      const value = payload[key];
      if (typeof value === "string" && value.trim()) return value.trim();
    }
    return "";
  }
}

export type DiscoveredBusiness = { sourceId: string; name: string; category: string | null; address: string | null; phone: string | null; website: string | null; latitude: number | null; longitude: number | null; metadata: Record<string, unknown> };

/** Google Places Text Search adapter. It fails explicitly; it never fabricates businesses. */
export class GooglePlacesProvider {
  readonly name = "google_places";
  constructor(private readonly apiKey = process.env.GOOGLE_PLACES_API_KEY, private readonly timeoutMs = 12_000, private readonly attempts = 2) {}
  health() { return this.apiKey ? { status: "IMPLEMENTED" as const, detail: "Google Places Text Search configured" } : { status: "DISABLED" as const, detail: "GOOGLE_PLACES_API_KEY is not configured" }; }
  async search(query: string, limit: number): Promise<DiscoveredBusiness[]> {
    if (!this.apiKey) throw Object.assign(new Error("Google Places is disabled: configure GOOGLE_PLACES_API_KEY"), { statusCode: 503 });
    let last: Error | undefined;
    for (let attempt = 0; attempt < this.attempts; attempt++) {
      try { return await this.request(query, limit); } catch (error) { last = error as Error; if (!("retryable" in last) || !(last as Error & { retryable?: boolean }).retryable || attempt === this.attempts - 1) throw last; await new Promise(resolve => setTimeout(resolve, 150 * (attempt + 1))); }
    }
    throw last ?? new Error("Google Places request failed");
  }
  private async request(query: string, limit: number): Promise<DiscoveredBusiness[]> {
    const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const response = await fetch("https://places.googleapis.com/v1/places:searchText", { method: "POST", signal: controller.signal, headers: { "content-type": "application/json", "x-goog-api-key": this.apiKey!, "x-goog-fieldmask": "places.id,places.displayName,places.formattedAddress,places.types,places.nationalPhoneNumber,places.websiteUri,places.location" }, body: JSON.stringify({ textQuery: query, maxResultCount: limit }) });
      const payload = await response.json() as { places?: Array<Record<string, unknown>>; error?: { message?: string } };
      if (!response.ok || payload.error) { const error = Object.assign(new Error(payload.error?.message ?? "Google Places request failed"), { statusCode: response.status, retryable: response.status === 429 || response.status >= 500 }); throw error; }
      return (payload.places ?? []).flatMap(place => { const sourceId = typeof place.id === "string" ? place.id : ""; if (!sourceId) return []; const display = place.displayName as { text?: unknown } | undefined; const location = place.location as { latitude?: unknown; longitude?: unknown } | undefined; const types = Array.isArray(place.types) ? place.types.filter((x): x is string => typeof x === "string") : []; return [{ sourceId, name: typeof display?.text === "string" ? display.text : "Unnamed business", category: types.slice(0, 3).join(", ") || null, address: typeof place.formattedAddress === "string" ? place.formattedAddress : null, phone: typeof place.nationalPhoneNumber === "string" ? place.nationalPhoneNumber : null, website: typeof place.websiteUri === "string" ? place.websiteUri : null, latitude: typeof location?.latitude === "number" ? location.latitude : null, longitude: typeof location?.longitude === "number" ? location.longitude : null, metadata: { provider: "Google Places", types } }]; });
    } catch (error) { if ((error as { name?: string }).name === "AbortError") throw Object.assign(new Error("Google Places request timed out"), { statusCode: 504, retryable: true }); throw error; } finally { clearTimeout(timeout); }
  }
}

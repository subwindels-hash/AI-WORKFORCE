interface CacheEntry<T> {
  value: T;
  expiresAt: number;
  createdAt: number;
}

/** Tiny TTL cache used for market-data caching (Redis-compatible interface for Phase 2+). */
export class TtlCache<T> {
  private store = new Map<string, CacheEntry<T>>();
  private hits = 0;
  private misses = 0;

  constructor(private readonly defaultTtlMs: number, private readonly maxEntries = 500) {}

  get(key: string): T | undefined {
    const e = this.store.get(key);
    if (!e) {
      this.misses++;
      return undefined;
    }
    if (Date.now() > e.expiresAt) {
      this.store.delete(key);
      this.misses++;
      return undefined;
    }
    this.hits++;
    return e.value;
  }

  set(key: string, value: T, ttlMs: number = this.defaultTtlMs): void {
    if (this.store.size >= this.maxEntries) {
      // Drop the oldest entry.
      const firstKey = this.store.keys().next().value;
      if (firstKey !== undefined) this.store.delete(firstKey);
    }
    this.store.set(key, { value, expiresAt: Date.now() + ttlMs, createdAt: Date.now() });
  }

  delete(key: string): void {
    this.store.delete(key);
  }

  clear(): void {
    this.store.clear();
  }

  stats(): { size: number; hits: number; misses: number } {
    return { size: this.store.size, hits: this.hits, misses: this.misses };
  }
}

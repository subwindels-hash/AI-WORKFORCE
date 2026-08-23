/**
 * Circuit breaker for outbound provider calls.
 *
 * CLOSED  -> normal operation, failures are counted
 * OPEN    -> after `threshold` failures inside `windowMs`, all calls fail fast
 *            for `cooldownMs`
 * HALF_OPEN -> one probe call is allowed; success closes, failure re-opens
 */
export class CircuitBreaker {
  private failures: number[] = [];
  private state: 'CLOSED' | 'OPEN' | 'HALF_OPEN' = 'CLOSED';
  private openedAt = 0;

  constructor(
    readonly name: string,
    private readonly threshold = 5,
    private readonly windowMs = 60_000,
    private readonly cooldownMs = 30_000,
  ) {}

  currentState(): 'CLOSED' | 'OPEN' | 'HALF_OPEN' {
    if (this.state === 'OPEN' && Date.now() - this.openedAt >= this.cooldownMs) {
      this.state = 'HALF_OPEN';
    }
    return this.state;
  }

  canCall(): boolean {
    const s = this.currentState();
    return s === 'CLOSED' || s === 'HALF_OPEN';
  }

  recordSuccess(): void {
    this.failures = [];
    this.state = 'CLOSED';
  }

  recordFailure(): void {
    const now = Date.now();
    this.failures.push(now);
    this.failures = this.failures.filter((t) => now - t <= this.windowMs);
    if (this.state === 'HALF_OPEN' || this.failures.length >= this.threshold) {
      this.state = 'OPEN';
      this.openedAt = now;
    }
  }

  snapshot(): { state: 'CLOSED' | 'OPEN' | 'HALF_OPEN'; recentFailures: number } {
    return { state: this.currentState(), recentFailures: this.failures.length };
  }
}

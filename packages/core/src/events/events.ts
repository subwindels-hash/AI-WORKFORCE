import { randomUUID } from 'node:crypto';
import type { AuditEvent, PlatformEvent } from '../types';

type Listener = (e: AuditEvent) => void;

/**
 * Event bus + append-only audit trail (Rule 5).
 *
 * Phase 1 persists audit events to an in-memory ring buffer and (when a path
 * is configured) appends JSON lines to disk. In Phase 2 the same interface is
 * backed by PostgreSQL `audit_logs` + Redis streams — the API does not change.
 */
export class EventBus {
  private listeners: Listener[] = [];
  private buffer: AuditEvent[] = [];
  private readonly maxBuffer = 1000;

  constructor(private readonly auditFilePath?: string) {}

  subscribe(fn: Listener): () => void {
    this.listeners.push(fn);
    return () => {
      this.listeners = this.listeners.filter((l) => l !== fn);
    };
  }

  emit(type: PlatformEvent, summary: string, detail?: Record<string, unknown>, actor: 'system' | 'user' = 'system'): AuditEvent {
    const event: AuditEvent = {
      id: randomUUID(),
      type,
      at: new Date().toISOString(),
      actor,
      summary,
      detail,
    };
    this.buffer.push(event);
    if (this.buffer.length > this.maxBuffer) this.buffer.shift();
    for (const l of this.listeners) {
      try {
        l(event);
      } catch {
        // listener errors never break the pipeline
      }
    }
    return event;
  }

  recent(limit = 100): AuditEvent[] {
    return this.buffer.slice(-limit).reverse();
  }

  get bufferSize(): number {
    return this.buffer.length;
  }
}

/** Append-only JSONL audit writer (best-effort; failures never break trading logic). */
export class JsonlAuditSink {
  constructor(private readonly filePath: string) {}

  async append(event: AuditEvent): Promise<void> {
    const { appendFile, mkdir } = await import('node:fs/promises');
    const path = await import('node:path');
    await mkdir(path.dirname(this.filePath), { recursive: true });
    await appendFile(this.filePath, JSON.stringify(event) + '\n', 'utf8');
  }
}

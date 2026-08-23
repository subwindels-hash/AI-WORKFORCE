'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import type { SystemStatus } from '@/lib/types';
import { HeaderBar } from './panels';

export { default as Nav } from './nav';

/**
 * The full header (with kill-switch controls) needs system status; this shim
 * keeps the strategy page lean while preserving the global chrome.
 */
export function HeaderBarShim() {
  const [status, setStatus] = useState<SystemStatus | null>(null);
  useEffect(() => {
    api.systemStatus().then(setStatus).catch(() => undefined);
    const id = setInterval(() => api.systemStatus().then(setStatus).catch(() => undefined), 60_000);
    return () => clearInterval(id);
  }, []);
  return (
    <HeaderBar
      status={status}
      onKillSwitch={async (active) => {
        try {
          await api.killSwitch(active, active ? 'Engaged from Strategy Lab' : 'Released from Strategy Lab');
          setStatus(await api.systemStatus());
        } catch { /* surfaced in status poll */ }
      }}
    />
  );
}

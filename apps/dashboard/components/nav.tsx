'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

const TABS = [
  { href: '/', label: 'Intelligence' },
  { href: '/strategy', label: 'Strategy Lab' },
  { href: '/journal', label: 'Journal & Analytics' },
];

export default function Nav() {
  const pathname = usePathname();
  return (
    <nav className="border-b border-[#1d2333] bg-[#0b0e15]">
      <div className="mx-auto flex max-w-[1500px] gap-1 px-4">
        {TABS.map((t) => {
          const active = pathname === t.href;
          return (
            <Link
              key={t.href}
              href={t.href}
              className={`-mb-px border-b-2 px-3 py-2 text-xs font-semibold transition ${
                active
                  ? 'border-sky-500 text-sky-300'
                  : 'border-transparent text-slate-500 hover:text-slate-300'
              }`}
            >
              {t.label}
            </Link>
          );
        })}
        <span className="ml-auto self-center text-[10px] text-slate-600">
          Phase 2 · ANALYSIS_ONLY + strategy lab — no orders, no live trading
        </span>
      </div>
    </nav>
  );
}

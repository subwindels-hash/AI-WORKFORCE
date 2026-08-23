'use client';

import { useMemo } from 'react';
import type { Candle, TradeSetup } from '@/lib/types';
import { formatPrice } from '@/lib/api';

function emaSeries(values: number[], period: number): (number | null)[] {
  const k = 2 / (period + 1);
  const out: (number | null)[] = [];
  let prev: number | null = null;
  let seed = 0;
  for (let i = 0; i < values.length; i++) {
    if (i < period - 1) {
      seed += values[i];
      out.push(null);
      continue;
    }
    if (prev === null) {
      seed += values[i];
      prev = seed / period;
    } else {
      prev = values[i] * k + prev * (1 - k);
    }
    out.push(prev);
  }
  return out;
}

interface Props {
  candles: Candle[];
  support: number[];
  resistance: number[];
  setup: TradeSetup | null;
}

export default function CandleChart({ candles, support, resistance, setup }: Props) {
  const W = 920;
  const H = 400;
  const PAD_L = 8;
  const PAD_R = 76;
  const PAD_T = 12;
  const VOL_H = 64;
  const priceH = H - PAD_T - VOL_H - 18;

  const view = useMemo(() => {
    const visible = candles.slice(-140);
    const closes = visible.map((c) => c.close);
    const ema20 = emaSeries(closes, 20);
    const ema50 = emaSeries(closes, 50);
    let lo = Math.min(...visible.map((c) => c.low));
    let hi = Math.max(...visible.map((c) => c.high));
    for (const l of [...support, ...resistance]) {
      if (l > lo * 0.9 && l < hi * 1.1) {
        lo = Math.min(lo, l);
        hi = Math.max(hi, l);
      }
    }
    if (setup) {
      lo = Math.min(lo, setup.stopLoss, setup.entry.min);
      hi = Math.max(hi, setup.stopLoss, setup.takeProfit[0]);
    }
    const pad = (hi - lo) * 0.04 || hi * 0.001;
    lo -= pad;
    hi += pad;
    const maxVol = Math.max(...visible.map((c) => c.volume), 1);
    const n = visible.length;
    const step = (W - PAD_L - PAD_R) / Math.max(1, n);
    const x = (i: number) => PAD_L + i * step + step / 2;
    const y = (p: number) => PAD_T + priceH - ((p - lo) / (hi - lo)) * priceH;
    const vy = (v: number) => H - 12 - (v / maxVol) * VOL_H;
    return { visible, ema20, ema50, lo, hi, x, y, vy, step };
  }, [candles, support, resistance, setup]);

  const line = (series: (number | null)[]) =>
    series
      .map((v, i) => (v === null ? null : `${view.x(i).toFixed(1)},${view.y(v).toFixed(1)}`))
      .filter(Boolean)
      .join(' ');

  const gridPrices = useMemo(() => {
    const out: number[] = [];
    for (let i = 0; i <= 4; i++) out.push(view.lo + ((view.hi - view.lo) * i) / 4);
    return out;
  }, [view.lo, view.hi]);

  return (
    <div className="scroll-thin overflow-x-auto">
      <svg viewBox={`0 0 ${W} ${H}`} className="min-w-[720px] w-full" role="img" aria-label="Candlestick chart">
        {/* grid */}
        {gridPrices.map((p, i) => (
          <g key={i}>
            <line x1={PAD_L} x2={W - PAD_R} y1={view.y(p)} y2={view.y(p)} stroke="#141926" strokeWidth="1" />
            <text x={W - PAD_R + 6} y={view.y(p) + 3.5} fontSize="10" fill="#5b6478" className="font-mono">
              {formatPrice(p)}
            </text>
          </g>
        ))}

        {/* support / resistance */}
        {resistance.map((r, i) => (
          <line key={`r${i}`} x1={PAD_L} x2={W - PAD_R} y1={view.y(r)} y2={view.y(r)} stroke="#f87171" strokeWidth="1" strokeDasharray="2 5" opacity="0.55">
            <title>Resistance {formatPrice(r)}</title>
          </line>
        ))}
        {support.map((s, i) => (
          <line key={`s${i}`} x1={PAD_L} x2={W - PAD_R} y1={view.y(s)} y2={view.y(s)} stroke="#38bdf8" strokeWidth="1" strokeDasharray="2 5" opacity="0.55">
            <title>Support {formatPrice(s)}</title>
          </line>
        ))}

        {/* trade setup overlay */}
        {setup && (
          <g>
            <rect
              x={PAD_L}
              y={view.y(setup.entry.max)}
              width={W - PAD_L - PAD_R}
              height={Math.max(2, view.y(setup.entry.min) - view.y(setup.entry.max))}
              fill={setup.action === 'BUY' ? '#22c55e' : '#ef4444'}
              opacity="0.12"
            />
            <line x1={PAD_L} x2={W - PAD_R} y1={view.y(setup.stopLoss)} y2={view.y(setup.stopLoss)} stroke="#ef4444" strokeWidth="1.4" strokeDasharray="6 3" />
            <text x={PAD_L + 4} y={view.y(setup.stopLoss) - 3} fontSize="10" fill="#f87171" className="font-mono">SL {formatPrice(setup.stopLoss)}</text>
            {setup.takeProfit.map((tp, i) => (
              <g key={i}>
                <line x1={PAD_L} x2={W - PAD_R} y1={view.y(tp)} y2={view.y(tp)} stroke="#22c55e" strokeWidth="1" strokeDasharray="6 3" opacity={0.9 - i * 0.25} />
                <text x={PAD_L + 4} y={view.y(tp) - 3} fontSize="10" fill="#4ade80" className="font-mono">TP{i + 1} {formatPrice(tp)}</text>
              </g>
            ))}
          </g>
        )}

        {/* volume */}
        {view.visible.map((c, i) => (
          <rect
            key={`v${i}`}
            x={view.x(i) - view.step * 0.32}
            y={view.vy(c.volume)}
            width={Math.max(1, view.step * 0.64)}
            height={H - 12 - view.vy(c.volume)}
            fill={c.close >= c.open ? '#134e4a' : '#4c1d24'}
          />
        ))}

        {/* candles */}
        {view.visible.map((c, i) => {
          const up = c.close >= c.open;
          const color = up ? '#26a69a' : '#ef5350';
          const yO = view.y(c.open);
          const yC = view.y(c.close);
          const top = Math.min(yO, yC);
          const h = Math.max(1, Math.abs(yC - yO));
          return (
            <g key={i}>
              <title>
                {`${new Date(c.timestamp).toISOString().replace('T', ' ').slice(0, 16)} UTC — O ${formatPrice(c.open)} H ${formatPrice(c.high)} L ${formatPrice(c.low)} C ${formatPrice(c.close)} V ${Math.round(c.volume)}`}
              </title>
              <line x1={view.x(i)} x2={view.x(i)} y1={view.y(c.high)} y2={view.y(c.low)} stroke={color} strokeWidth="1" />
              <rect x={view.x(i) - Math.max(1, view.step * 0.3)} y={top} width={Math.max(1.5, view.step * 0.6)} height={h} fill={color} />
            </g>
          );
        })}

        {/* EMA overlays */}
        <polyline points={line(view.ema20)} fill="none" stroke="#fbbf24" strokeWidth="1.3" opacity="0.9">
          <title>EMA 20</title>
        </polyline>
        <polyline points={line(view.ema50)} fill="none" stroke="#a78bfa" strokeWidth="1.3" opacity="0.9">
          <title>EMA 50</title>
        </polyline>
      </svg>
      <div className="flex gap-4 px-4 pb-2 text-[10px] text-slate-500">
        <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#fbbf24]" /> EMA20</span>
        <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#a78bfa]" /> EMA50</span>
        <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#38bdf8]" /> Support</span>
        <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#f87171]" /> Resistance</span>
        {setup && <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#ef4444]" /> SL</span>}
        {setup && <span className="flex items-center gap-1"><span className="inline-block h-0.5 w-4 bg-[#22c55e]" /> TP ladder</span>}
      </div>
    </div>
  );
}

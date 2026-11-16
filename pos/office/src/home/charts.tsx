import { makeStyles, tokens } from '@fluentui/react-components'
import { useId, useMemo, useState } from 'react'

/**
 * Small, light charts for Home, drawn as SVG (no chart library): a sparkline, the KPI meter's gauge, a donut, bars and
 * lines. Series colours are the validated categorical set (global.css --zx-s1…8, light and dark), in a fixed order;
 * every mark has a hover tooltip and every chart a text alternative.
 */
export const SERIES = ['var(--zx-s1)', 'var(--zx-s2)', 'var(--zx-s3)', 'var(--zx-s4)', 'var(--zx-s5)', 'var(--zx-s6)', 'var(--zx-s7)', 'var(--zx-s8)']

const useStyles = makeStyles({
  wrap: { position: 'relative', width: '100%' },
  tip: {
    position: 'absolute', pointerEvents: 'none', zIndex: 5, transform: 'translate(-50%, calc(-100% - 10px))', whiteSpace: 'nowrap',
    padding: '6px 10px', borderRadius: tokens.borderRadiusMedium, backgroundColor: tokens.colorNeutralBackground1, boxShadow: tokens.shadow8,
    border: `1px solid ${tokens.colorNeutralStroke2}`, fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground1,
  },
  axis: { fill: tokens.colorNeutralForeground3, fontSize: '11px' },
  grid: { stroke: tokens.colorNeutralStroke2, strokeWidth: 1 },
  sr: { position: 'absolute', width: '1px', height: '1px', overflow: 'hidden', clip: 'rect(0 0 0 0)' },
})

type Tip = { x: number | string; y: number; lines: string[] } | null

function TipBox({ tip }: { tip: Tip }) {
  const s = useStyles()
  if (!tip) return null
  return <div className={s.tip} style={{ left: tip.x, top: tip.y }}>{tip.lines.map((l, i) => <div key={i} style={{ fontWeight: i === 0 ? 600 : 400 }}>{l}</div>)}</div>
}

/** A thin trend line under a KPI (no axes; it shows the shape, the number above it says how much). */
export function Sparkline({ values, label, up = true }: { values: number[]; label: string; up?: boolean }) {
  const gid = useId()
  if (values.length < 2) return null
  const w = 88
  const h = 28
  const max = Math.max(...values)
  const min = Math.min(...values)
  const pts = values.map((v, i) => [(i / (values.length - 1)) * w, h - 3 - ((v - min) / (max - min || 1)) * (h - 6)] as const)
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const colour = up ? 'var(--zx-good)' : 'var(--zx-s2)'
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} role="img" aria-label={label}>
      <defs><linearGradient id={gid} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={colour} stopOpacity=".28" /><stop offset="1" stopColor={colour} stopOpacity="0" /></linearGradient></defs>
      <path d={`${line} L${w},${h} L0,${h} Z`} fill={`url(#${gid})`} />
      <path d={line} fill="none" stroke={colour} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/** The KPI meter: a half circle from 0 to the scale's end, the needle at the figure, a tick at the period before. */
export function Gauge({ value, previous, max, label, display, minLabel, maxLabel, previousLabel }: {
  value: number; previous: number | null; max: number; label: string; display: string; minLabel: string; maxLabel: string; previousLabel?: string
}) {
  const s = useStyles()
  const r = 92
  const cx = 120
  const cy = 112
  const frac = Math.max(0, Math.min(1, max > 0 ? value / max : 0))
  const at = (f: number, rr = r) => [cx - rr * Math.cos(Math.PI * f), cy - rr * Math.sin(Math.PI * f)] as const
  const arc = (f: number) => { const [x, y] = at(f); return `M${cx - r},${cy} A${r},${r} 0 0 1 ${x.toFixed(2)},${y.toFixed(2)}` }
  const prev = previous !== null && max > 0 ? Math.max(0, Math.min(1, previous / max)) : null
  return (
    <svg viewBox="0 0 240 150" width="100%" role="img" aria-label={`${label}: ${display}${previousLabel ? `, ${previousLabel}` : ''}`}>
      <path d={arc(1)} fill="none" stroke={tokens.colorNeutralStroke2} strokeWidth={18} strokeLinecap="round" />
      {frac > 0 && <path d={arc(frac)} fill="none" stroke="var(--zx-good)" strokeWidth={18} strokeLinecap="round" className="zx-grow" />}
      {prev !== null && (() => { const [x1, y1] = at(prev, r - 14); const [x2, y2] = at(prev, r + 14); return <line x1={x1} y1={y1} x2={x2} y2={y2} stroke={tokens.colorNeutralForeground2} strokeWidth={2} /> })()}
      <text x={cx} y={cy - 10} textAnchor="middle" style={{ fontSize: display.length > 9 ? '18px' : '22px', fontWeight: 700, fill: tokens.colorNeutralForeground1 }}>{display}</text>
      <text x={cx} y={cy + 8} textAnchor="middle" className={s.axis}>{label.length > 26 ? `${label.slice(0, 25)}…` : label}</text>
      <text x={cx - r} y={cy + 22} textAnchor="middle" className={s.axis}>{minLabel}</text>
      <text x={cx + r} y={cy + 22} textAnchor="middle" className={s.axis}>{maxLabel}</text>
      {prev !== null && previousLabel && <text x={cx} y={cy + 30} textAnchor="middle" className={s.axis}>{previousLabel}</text>}
    </svg>
  )
}

/** A donut of shares (the first seven, then "Other"), with a 2px gap between slices; hover a slice for its figure. */
export function Donut({ items, format, label }: { items: { name: string; value: number }[]; format: (v: number) => string; label: string }) {
  const [tip, setTip] = useState<Tip>(null)
  const slices = useMemo(() => {
    const top = items.slice(0, 7)
    const rest = items.slice(7).reduce((a, i) => a + i.value, 0)
    return rest > 0 ? [...top, { name: 'Other', value: rest }] : top
  }, [items])
  const total = slices.reduce((a, i) => a + i.value, 0) || 1
  const r = 80
  const w = 26
  // Where each slice starts: the shares added up, from the top, going clockwise.
  const starts = slices.reduce<number[]>((acc, sl) => [...acc, acc[acc.length - 1] + (sl.value / total) * Math.PI * 2], [-Math.PI / 2])
  return (
    <div style={{ position: 'relative' }} onMouseLeave={() => setTip(null)}>
      <svg viewBox="0 0 200 200" width="100%" style={{ maxWidth: 240, display: 'block', margin: '0 auto' }} role="img" aria-label={label}>
        {slices.map((sl, i) => {
          const start = starts[i]
          const end = starts[i + 1]
          const a = end - start
          const gap = slices.length > 1 ? 0.012 : 0
          const p = (t: number, rr: number) => `${(100 + rr * Math.cos(t)).toFixed(2)},${(100 + rr * Math.sin(t)).toFixed(2)}`
          const big = a - 2 * gap > Math.PI ? 1 : 0
          const d = slices.length === 1
            ? `M100,${100 - r} A${r},${r} 0 1 1 99.99,${100 - r} L99.99,${100 - r + w} A${r - w},${r - w} 0 1 0 100,${100 - r + w} Z`
            : `M${p(start + gap, r)} A${r},${r} 0 ${big} 1 ${p(end - gap, r)} L${p(end - gap, r - w)} A${r - w},${r - w} 0 ${big} 0 ${p(start + gap, r - w)} Z`
          const mid = start + a / 2
          return <path key={sl.name} d={d} fill={SERIES[i % 8]} className="zx-fade" tabIndex={0} aria-label={`${sl.name}: ${format(sl.value)}`}
            onMouseMove={(e) => { const b = (e.currentTarget.ownerSVGElement as SVGSVGElement).getBoundingClientRect(); setTip({ x: (100 + (r - w / 2) * Math.cos(mid)) / 200 * b.width, y: (100 + (r - w / 2) * Math.sin(mid)) / 200 * b.height, lines: [sl.name, `${format(sl.value)} · ${Math.round(sl.value * 100 / total)} %`] }) }} />
        })}
      </svg>
      <TipBox tip={tip} />
      <Legend items={slices.map((s2, i) => ({ name: s2.name, colour: SERIES[i % 8], value: format(s2.value) }))} />
    </div>
  )
}

export function Legend({ items }: { items: { name: string; colour: string; value?: string }[] }) {
  return (
    <ul className="zx-legend">
      {items.map((i) => <li key={i.name}><span className="zx-swatch" style={{ background: i.colour }} /><span className="zx-legend-name">{i.name}</span>{i.value && <span className="zx-legend-value">{i.value}</span>}</li>)}
    </ul>
  )
}

/**
 * Bars or lines over months (one axis, shared scale). Bars stand on the baseline with 4px rounded tops and a 2px gap;
 * lines are 2px. `dashed` draws an extra dashed line (e.g. a 3-month average). Hover a month for all its figures.
 */
export function TimeChart({ labels, series, kind, format, formatAxis, dashed }: {
  labels: string[]; series: { key: string; label: string; values: number[] }[]; kind: 'bars' | 'lines'
  format: (v: number) => string; formatAxis: (v: number) => string; dashed?: { label: string; values: number[] }
}) {
  const s = useStyles()
  const [hover, setHover] = useState<number | null>(null)
  const W = 640
  const H = 240
  const left = 56
  const bottom = 26
  const top = 10
  const all = [...series.flatMap((x) => x.values), ...(dashed?.values ?? [])]
  const niceMax = nice(Math.max(1, ...all))
  const y = (v: number) => top + (H - top - bottom) * (1 - v / niceMax)
  const band = (W - left - 8) / Math.max(1, labels.length)
  const x = (i: number) => left + band * i + band / 2
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * niceMax)
  const bw = Math.max(4, Math.min(22, (band - 10) / Math.max(1, series.length) - 2))
  const path = (vals: number[]) => vals.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ')

  return (
    <div className={s.wrap} onMouseLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label={`${series.map((x2) => x2.label).join(' and ')} by month`}>
        {ticks.map((t) => <g key={t}><line x1={left} x2={W - 8} y1={y(t)} y2={y(t)} className={s.grid} /><text x={left - 6} y={y(t) + 4} textAnchor="end" className={s.axis}>{formatAxis(t)}</text></g>)}
        {labels.map((l, i) => <text key={l} x={x(i)} y={H - 8} textAnchor="middle" className={s.axis}>{l}</text>)}
        {hover !== null && <rect x={left + band * hover} y={top} width={band} height={H - top - bottom} fill={tokens.colorNeutralBackground1Hover} />}
        {kind === 'bars'
          ? series.map((ser, si) => ser.values.map((v, i) => {
            const bx = x(i) - (series.length * (bw + 2)) / 2 + si * (bw + 2) + 1
            const h2 = Math.max(0, y(0) - y(v))
            return <path key={`${ser.key}-${i}`} className="zx-grow" fill={SERIES[si]} d={roundTop(bx, y(v), bw, h2, Math.min(4, h2))} />
          }))
          : series.map((ser, si) => <path key={ser.key} d={path(ser.values)} fill="none" stroke={SERIES[si]} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />)}
        {kind === 'lines' && series.map((ser, si) => ser.values.map((v, i) => hover === i && <circle key={`${ser.key}-${i}`} cx={x(i)} cy={y(v)} r={4} fill={SERIES[si]} stroke={tokens.colorNeutralBackground1} strokeWidth={2} />))}
        {dashed && <path d={path(dashed.values)} fill="none" stroke={tokens.colorNeutralForeground3} strokeWidth={2} strokeDasharray="5 4" />}
        <line x1={left} x2={W - 8} y1={y(0)} y2={y(0)} stroke={tokens.colorNeutralStroke1} />
        {labels.map((l, i) => <rect key={`hit-${l}`} x={left + band * i} y={top} width={band} height={H - top - bottom} fill="transparent" onMouseEnter={() => setHover(i)} />)}
      </svg>
      {hover !== null && (
        <TipBox tip={{ x: `${Math.min(85, Math.max(15, (x(hover) / W) * 100))}%`, y: 44, lines: [labels[hover], ...series.map((ser) => `${ser.label}: ${format(ser.values[hover])}`), ...(dashed ? [`${dashed.label}: ${format(dashed.values[hover])}`] : [])] }} />
      )}
      <Legend items={[...series.map((ser, si) => ({ name: ser.label, colour: SERIES[si] })), ...(dashed ? [{ name: dashed.label, colour: 'transparent' }] : [])]} />
    </div>
  )
}

/** Horizontal bars, one per item (a single series: the title names it, no legend). */
export function HBars({ items, format }: { items: { name: string; value: number }[]; format: (v: number) => string }) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <ul className="zx-hbars">
      {items.map((i) => (
        <li key={i.name} title={`${i.name}: ${format(i.value)}`}>
          <span className="zx-hbar-name">{i.name}</span>
          <span className="zx-hbar-track"><span className="zx-hbar zx-grow-x" style={{ width: `${(i.value / max) * 100}%` }} /></span>
          <span className="zx-hbar-value">{format(i.value)}</span>
        </li>
      ))}
    </ul>
  )
}

function nice(v: number): number {
  const p = 10 ** Math.floor(Math.log10(v))
  return [1, 2, 2.5, 5, 10].map((m) => m * p).find((m) => m >= v) ?? v
}

function roundTop(x: number, y: number, w: number, h: number, r: number): string {
  if (h <= 0) return ''
  return `M${x},${y + h} L${x},${y + r} Q${x},${y} ${x + r},${y} L${x + w - r},${y} Q${x + w},${y} ${x + w},${y + r} L${x + w},${y + h} Z`
}

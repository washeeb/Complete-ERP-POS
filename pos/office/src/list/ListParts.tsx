import { Link, makeStyles, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { WindowArrowUpRegular } from '@fluentui/react-icons'
import type { ReactNode } from 'react'
import { navigate } from '../nav'
import { classicPathFor } from '../paths'

const useStyles = makeStyles({
  page: { flex: '1 1 auto', overflowY: 'auto', padding: '16px 20px 32px', '@media (max-width: 760px)': { padding: '12px 10px 24px' } },
  head: { display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  seg: { display: 'flex', gap: '2px', flexWrap: 'wrap' },
  cards: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px', marginBottom: '12px' },
  card: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  big: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
})

export function usePageStyles() {
  return useStyles()
}

/** A rebuilt page's heading, its one-line explanation, and the way to its classic version. */
export function PageHead({ title, hint, classic }: { title: string; hint: string; classic: string }) {
  const s = useStyles()
  return (
    <div className={s.head}>
      <div>
        <h1 className={s.title}>{title}</h1>
        <Text className={s.muted}>{hint}</Text>
      </div>
      <Link href={classicPathFor(classic)} onClick={(e) => { e.preventDefault(); navigate(classicPathFor(classic)) }}><WindowArrowUpRegular /> Classic version</Link>
    </div>
  )
}

export const PERIODS: [string, string][] = [['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['', 'All']]

/** Today, 7, 30 or 90 days, or All. */
export function PeriodButtons({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const s = useStyles()
  return (
    <div className={s.seg} role="group" aria-label="When">
      {PERIODS.map(([k, l]) => <ToggleButton key={k || 'all'} size="small" checked={value === k} onClick={() => onChange(k)}>{l}</ToggleButton>)}
    </div>
  )
}

/** Figures for what the list shows (how many, the total…). */
export function Cards({ items }: { items: { label: string; value: ReactNode; tone?: string }[] }) {
  const s = useStyles()
  return (
    <div className={s.cards}>
      {items.map((i) => (
        <div key={i.label} className={s.card}>
          <Text size={200} className={s.muted}>{i.label}</Text>
          <span className={s.big} style={i.tone ? { color: i.tone } : undefined}>{i.value}</span>
        </div>
      ))}
    </div>
  )
}

export const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

/** Over is amber, short is red, balanced is green: the colour carries meaning, never on its own (the sign and amount stay). */
export const overShortColour = (v: number | null) => (v === null ? undefined : v < 0 ? 'var(--zx-bad)' : v > 0 ? 'var(--zx-warn)' : 'var(--zx-good)')

import { Dropdown, Field, makeStyles, Option as Choice, Text, tokens } from '@fluentui/react-components'
import type { ReactNode } from 'react'
import type { Option } from '../api'

/** Shared looks for the Cash pages (Close the day, Cash desk, Income control, Shifts and vaults). */
export const useCashStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto', marginBottom: '12px' },
  panelHead: { display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '10px', flexWrap: 'wrap' },
  heading: { display: 'block', fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold },
  hint: { display: 'block', marginBottom: '8px', color: tokens.colorNeutralForeground3 },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  act: { textAlign: 'right', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  warn: { color: 'var(--zx-warn)' },
  bad: { color: 'var(--zx-bad)' },
  good: { color: 'var(--zx-good)' },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

export const END = { style: { justifyContent: 'flex-end' } }

/** A titled box with its one-line explanation and, on the right, its buttons. */
export function Panel({ title, hint, actions, children }: { title: string; hint?: string; actions?: ReactNode; children: ReactNode }) {
  const s = useCashStyles()
  return (
    <section className={s.panel} aria-label={title}>
      <div className={s.panelHead}>
        <div><Text className={s.heading}>{title}</Text>{hint && <Text className={s.hint}>{hint}</Text>}</div>
        {actions}
      </div>
      {children}
    </section>
  )
}

/** Choose the branch (shown only when there is more than one). */
export function BranchPick({ branches, value, onChange }: { branches: Option[]; value: string | null; onChange: (id: string) => void }) {
  if (branches.length < 2) return null
  return (
    <Field label="Branch" size="small">
      <Dropdown size="small" style={{ minWidth: 200 }} value={branches.find((b) => b.id === value)?.label ?? ''} selectedOptions={value ? [value] : []}
        onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
        {branches.map((b) => <Choice key={b.id} value={b.id}>{b.label}</Choice>)}
      </Dropdown>
    </Field>
  )
}

/** A day as "Fri 20 Nov" (or with the year when asked). */
export const dayLabel = (iso: string, year = false) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', ...(year ? { year: 'numeric' } : {}) })

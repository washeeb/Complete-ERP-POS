import { Link, makeStyles, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import type { FrCell, FrTable } from '../api'

const useStyles = makeStyles({
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto', marginBottom: '12px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  head: { fontWeight: tokens.fontWeightBold, paddingTop: '12px' },
  sub: { fontWeight: tokens.fontWeightSemibold },
  total: { fontWeight: tokens.fontWeightBold, borderTop: `2px solid ${tokens.colorNeutralForeground1}` },
  muted: { color: tokens.colorNeutralForeground3 },
  caption: { display: 'block' },
  hint: { display: 'block', marginBottom: '6px' },
  click: { cursor: 'pointer' },
})

const END = { style: { justifyContent: 'flex-end' } }
export const TONE = { good: 'var(--zx-good)', bad: 'var(--zx-bad)' } as const

/**
 * A report's table as the server wrote it (financial reports, Insights): columns (numbers to the right), rows of
 * ready-written cells — a cell may open another report or page (onOpen) or be shown good/bad — heading, subtotal and
 * total rows, and a total line. The export writes the same cells.
 */
export function ReportTable({ table: t, caption, empty, onOpen, onDrill }: {
  table: FrTable; caption: boolean; empty?: string; onOpen: (to: string) => void; onDrill?: (drill: Record<string, string>) => void
}) {
  const s = useStyles()
  const cell = (c: FrCell) => {
    if (c === null || typeof c === 'string') return c ?? ''
    const text = c.strong ? <Text weight="semibold">{c.t}</Text> : c.t
    if (c.to) return <Link onClick={(e) => { e.stopPropagation(); onOpen(c.to!) }}>{text}</Link>
    return c.tone ? <span style={{ color: TONE[c.tone], fontWeight: c.tone === 'bad' ? 600 : undefined }}>{text}</span> : text
  }
  return (
    <section className={s.panel} aria-label={t.title}>
      {caption && <Text weight="semibold" size={400} className={s.caption}>{t.title}</Text>}
      {t.hint && <Text size={200} className={`${s.muted} ${s.hint}`}>{t.hint}</Text>}
      <Table size="small" aria-label={t.title} style={{ minWidth: t.columns.length > 2 ? 560 : undefined }}>
        <TableHeader><TableRow>{t.columns.map((c, j) => <TableHeaderCell key={j} className={c.end ? s.end : undefined} button={c.end ? END : undefined}>{c.label}</TableHeaderCell>)}</TableRow></TableHeader>
        <TableBody>
          {t.rows.length === 0 && <TableRow><TableCell colSpan={t.columns.length}><Text className={s.muted}>{t.empty ?? empty ?? 'Nothing to show.'}</Text></TableCell></TableRow>}
          {t.rows.map((row, j) => (
            <TableRow key={j} className={row.drill && onDrill ? s.click : undefined} onClick={row.drill && onDrill ? () => onDrill(row.drill!) : undefined}
              tabIndex={row.drill && onDrill ? 0 : undefined} onKeyDown={row.drill && onDrill ? (e) => { if (e.key === 'Enter') onDrill(row.drill!) } : undefined}>
              {row.cells.map((c, k) => (
                <TableCell key={k} className={[t.columns[k]?.end ? s.end : '', row.kind ? s[row.kind] : ''].join(' ')}>{cell(c)}</TableCell>
              ))}
            </TableRow>
          ))}
          {t.foot.length > 0 && <TableRow>{t.foot.map((c, k) => <TableCell key={k} className={[t.columns[k]?.end ? s.end : '', s.total].join(' ')}>{cell(c)}</TableCell>)}</TableRow>}
        </TableBody>
      </Table>
    </section>
  )
}

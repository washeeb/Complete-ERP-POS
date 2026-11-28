import {
  Button, Checkbox, Dropdown, makeStyles, mergeClasses, Option, ProgressBar, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ChevronLeftRegular, ChevronRightRegular } from '@fluentui/react-icons'
import type { ReactNode } from 'react'

const useStyles = makeStyles({
  wrap: { position: 'relative', overflowX: 'auto', backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge,
    border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4 },
  bar: { position: 'absolute', insetInline: 0, top: 0 },
  head: { backgroundColor: tokens.colorNeutralBackground2, position: 'sticky', top: 0, zIndex: 1 },
  row: { cursor: 'pointer', ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}`, outlineOffset: '-2px' } },
  // Fluent's Text sets its own left alignment; a second line in a number column (the amount under a count) follows the column.
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', '& .fui-Text': { textAlign: 'inherit' } },
  tick: { width: '36px', paddingInlineEnd: 0 },
  empty: { padding: '40px 16px', textAlign: 'center', color: tokens.colorNeutralForeground3 },
  pager: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', padding: '10px 4px' },
  pagerRight: { display: 'flex', alignItems: 'center', gap: '6px' },
})

export type Column<T> = { key: string; label: string; sort?: string; end?: boolean; width?: number | string; render: (row: T) => ReactNode }

/**
 * A list as a Fluent table: headers sort (click again to turn the order round), a row opens with a click or Enter, a
 * thin bar shows while the next page loads. Rows keep their place while loading, so the page does not jump. With
 * `selected`, each row has a tick box (and the heading one ticks the whole page) for actions on several rows.
 */
export function DataTable<T extends { id: string }>({ label, columns, rows, sort, dir, loading, empty, onSort, onOpen, selected, onSelect }: {
  label: string; columns: Column<T>[]; rows: T[]; sort: string; dir: 'asc' | 'desc'; loading: boolean; empty: ReactNode
  onSort: (sort: string, dir: 'asc' | 'desc') => void; onOpen: (row: T) => void
  selected?: ReadonlySet<string>; onSelect?: (ids: string[], on: boolean) => void
}) {
  const s = useStyles()
  const ticking = selected !== undefined && onSelect !== undefined
  const onPage = rows.filter((r) => selected?.has(r.id)).length
  return (
    <div className={s.wrap}>
      {loading && <ProgressBar className={s.bar} thickness="medium" aria-label="Loading" />}
      <Table aria-label={label} size="medium" style={{ minWidth: 640 }}>
        <TableHeader className={s.head}>
          <TableRow>
            {ticking && (
              <TableHeaderCell className={s.tick}>
                <Checkbox aria-label="Tick every row on this page" checked={onPage === 0 ? false : onPage === rows.length ? true : 'mixed'}
                  onChange={(_, d) => onSelect(rows.map((r) => r.id), d.checked === true)} />
              </TableHeaderCell>
            )}
            {columns.map((c) => (
              <TableHeaderCell key={c.key} className={mergeClasses(c.end && s.end)} style={c.width ? { width: c.width } : undefined}
                button={c.end ? { style: { justifyContent: 'flex-end' } } : undefined}
                sortDirection={c.sort && c.sort === sort ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}
                onClick={c.sort ? () => onSort(c.sort!, c.sort === sort && dir === 'desc' ? 'asc' : 'desc') : undefined}
                aria-sort={c.sort && c.sort === sort ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                {c.label}
              </TableHeaderCell>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.id} className={s.row} tabIndex={0} onClick={() => onOpen(r)}
              onKeyDown={(e) => { if (e.key === 'Enter') onOpen(r) }} aria-selected={ticking ? selected.has(r.id) : undefined}>
              {ticking && (
                <TableCell className={s.tick} onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
                  <Checkbox aria-label="Tick this row" checked={selected.has(r.id)} onChange={(_, d) => onSelect([r.id], d.checked === true)} />
                </TableCell>
              )}
              {columns.map((c) => <TableCell key={c.key} className={mergeClasses(c.end && s.end)}>{c.render(r)}</TableCell>)}
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {!loading && rows.length === 0 && <div className={s.empty}>{empty}</div>}
    </div>
  )
}

/** "1–25 of 312", previous and next, and how many rows a page shows. */
export function Pager({ page, per, total, options, onPage, onPer }: {
  page: number; per: number; total: number; options: number[]; onPage: (p: number) => void; onPer: (n: number) => void
}) {
  const s = useStyles()
  const last = Math.max(1, Math.ceil(total / per))
  const from = total === 0 ? 0 : (page - 1) * per + 1
  const to = Math.min(total, page * per)
  return (
    <div className={s.pager}>
      <Text size={300}>{from}–{to} of {total.toLocaleString('en-GB')}</Text>
      <div className={s.pagerRight}>
        <Text size={300}>Rows</Text>
        <Dropdown aria-label="Rows per page" size="small" value={String(per)} selectedOptions={[String(per)]} style={{ minWidth: 70 }}
          onOptionSelect={(_, d) => d.optionValue && onPer(Number(d.optionValue))}>
          {options.map((o) => <Option key={o} value={String(o)}>{String(o)}</Option>)}
        </Dropdown>
        <Button appearance="subtle" icon={<ChevronLeftRegular />} aria-label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)} />
        <Text size={300}>Page {page} of {last}</Text>
        <Button appearance="subtle" icon={<ChevronRightRegular />} aria-label="Next page" disabled={page >= last} onClick={() => onPage(page + 1)} />
      </div>
    </div>
  )
}

import {
  Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BudgetView } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '6px 10px', overflowX: 'auto' },
  cell: { width: '92px', minWidth: 0 },
  acc: { width: '240px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Budgets (release 8): for a financial year and branch, twelve months per income and cost account, as positive
 * amounts. Type a yearly figure and Spread shares it over the months. Save keeps the whole year; compare it under
 * Reports → Budget vs actual. Leaving with unsaved changes asks first.
 */
export function BudgetsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<BudgetView | null>(null)
  const [amounts, setAmounts] = useState<Record<string, string[]>>({})
  const [annual, setAnnual] = useState<Record<string, string>>({})
  const [dirty, setDirty] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [saving, setSaving] = useState(false)
  const [nonce, setNonce] = useState(0)
  const year = str(params, 'year')
  const branch = str(params, 'branch')
  useEffect(() => {
    api.budget(year, branch).then((d) => { setData(d); setAmounts(Object.fromEntries(d.rows.map((r) => [r.id, r.amounts]))); setAnnual({}); setDirty(false); setError('') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [year, branch, nonce])
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the budget…" />}</div>
  const change = (c: { year?: string; branch?: string }) => { if (dirty && !window.confirm('Leave without saving the budget?')) return; set(c) }
  const put = (id: string, i: number, v: string) => { setAmounts({ ...amounts, [id]: (amounts[id] ?? []).map((x, j) => (j === i ? v : x)) }); setDirty(true); setDone('') }
  const total = (id: string) => (amounts[id] ?? []).reduce((t, v) => t + (Number(v) || 0), 0)
  const can = data.can_save

  return (
    <div className={page.page}>
      <PageHead title="Budgets" hint="Income and costs you plan for each month of the year, per branch, as positive amounts. Compare them under Reports → Budget vs actual." />
      <div className={s.bar}>
        <Field label="Financial year"><Input type="number" min={2000} max={2100} value={String(data.year)} onChange={(_, d) => d.value.length === 4 && change({ year: d.value })} style={{ width: 110 }} /></Field>
        <Field label="Branch">
          <Dropdown aria-label="Branch" style={{ minWidth: 200 }} value={data.branches.find((b) => b.id === data.branch)?.label ?? ''} selectedOptions={[data.branch]}
            onOptionSelect={(_, d) => d.optionValue && change({ branch: d.optionValue })}>
            {data.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <Button onClick={() => navigate(`/office/financial-reports?report=budget`)}>Budget vs actual</Button>
        <span className={s.grow} />
        {dirty && <Text className={s.muted}>Not saved yet</Text>}
        {can && <Button appearance="primary" icon={<SaveRegular />} disabled={saving || !dirty} onClick={async () => {
          setSaving(true); setError('')
          try { setDone((await api.saveBudget(data.year, data.branch, amounts)).message); setNonce((n) => n + 1) } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
          setSaving(false)
        }}>{saving ? 'Saving…' : 'Save budget'}</Button>}
      </div>
      <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>Enter income and costs as positive amounts. Type a yearly figure and press Spread to share it over the 12 months.</Text>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Budget">
        <Table size="small" aria-label="Budget" style={{ minWidth: 240 + 104 + 76 + 12 * 104 + 120 }}>
          <TableHeader><TableRow><TableHeaderCell style={{ width: 240 }}>Account</TableHeaderCell><TableHeaderCell style={{ width: 104 }}>Year</TableHeaderCell>
            <TableHeaderCell style={{ width: 76 }} />
            {data.months.map((m, i) => <TableHeaderCell key={i} style={{ width: 104 }}>{m}</TableHeaderCell>)}<TableHeaderCell style={{ width: 120 }}>Total</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{data.rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell className={s.acc}>{r.label}</TableCell>
              <TableCell><Input className={s.cell} inputMode="decimal" aria-label={`Year ${r.label}`} disabled={!can} value={annual[r.id] ?? ''} onChange={(_, d) => setAnnual({ ...annual, [r.id]: d.value })} /></TableCell>
              <TableCell>{can && <Button size="small" appearance="subtle" disabled={!annual[r.id]} onClick={async () => {
                try { setAmounts({ ...amounts, [r.id]: (await api.spread(annual[r.id] ?? '')).amounts }); setDirty(true); setDone('') } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
              }}>Spread</Button>}</TableCell>
              {(amounts[r.id] ?? []).map((v, i) => (
                <TableCell key={i}><Input className={s.cell} inputMode="decimal" aria-label={`${r.label} month ${i + 1}`} disabled={!can} value={v} onChange={(_, d) => put(r.id, i, d.value)} /></TableCell>
              ))}
              <TableCell style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{total(r.id) ? total(r.id).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </section>
    </div>
  )
}

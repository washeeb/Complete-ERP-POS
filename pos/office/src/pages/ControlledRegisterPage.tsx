import {
  Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowDownloadRegular, PrintRegular } from '@fluentui/react-icons'
import { api } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px 12px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '10px 12px', marginBottom: '12px' },
  scroll: { overflowX: 'auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  end: { textAlign: 'right' },
})

const stamp = (iso: string) => new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/**
 * Controlled-drug register (pharmacy): every movement of each controlled medicine in one warehouse over a period,
 * read from the stock ledger, with the opening and closing balance; Print the register, and download the NHIS
 * claims (CSV) for people who manage the pharmacy.
 */
export function ControlledRegisterPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.controlledRegister, serialize(params))
  const open = (url: string | null, blank: boolean) => { if (url) window.open(url, blank ? '_blank' : '_self', 'noopener') }
  return (
    <div className={page.page}>
      <PageHead title="Controlled-drug register" hint="Every movement of each controlled medicine, read from the stock ledger, with the balance after each one." />
      <div className={s.bar}>
        <Field label="Warehouse" size="small">
          <Dropdown size="small" style={{ minWidth: 200 }} value={data?.warehouses.find((w) => w.id === data.warehouse)?.label ?? ''} selectedOptions={[data?.warehouse ?? '']}
            onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })}>
            {(data?.warehouses ?? []).map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="From" size="small"><Input size="small" type="date" value={str(params, 'from') || data?.from || ''} onChange={(_, d) => set({ from: d.value })} /></Field>
        <Field label="To" size="small"><Input size="small" type="date" value={str(params, 'to') || data?.to || ''} onChange={(_, d) => set({ to: d.value })} /></Field>
        <span className={s.grow} />
        <Button icon={<PrintRegular />} disabled={!data?.print || data.bad_period} onClick={() => open(data?.print ?? null, true)}>Print register</Button>
        {data?.can_claims && <Button icon={<ArrowDownloadRegular />} disabled={data.bad_period} onClick={() => open(data.claims, false)}>NHIS claims (CSV)</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {data?.bad_period && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>The start date is after the end date.</MessageBarBody></MessageBar>}
      {loading && !data ? <Spinner label="Reading the register…" /> : data && !data.bad_period && (data.products.length === 0
        ? <section className={s.panel}><Text>No controlled medicines moved here in this period. Mark a product's schedule as “Controlled drug” to keep it in this register.</Text></section>
        : data.products.map((p) => (
          <section key={p.id} className={s.panel} aria-label={p.name}>
            <Text block weight="semibold" size={400}>{p.name}</Text>
            <Text block size={200} className={s.muted}>Opening {p.opening} · closing {p.closing}</Text>
            <div className={s.scroll}>
              <Table size="small" aria-label={p.name} style={{ minWidth: 820 }}>
                <TableHeader><TableRow><TableHeaderCell>Date</TableHeaderCell><TableHeaderCell>Entry</TableHeaderCell><TableHeaderCell>Batch</TableHeaderCell>
                  <TableHeaderCell>Patient / prescriber</TableHeaderCell><TableHeaderCell>By</TableHeaderCell><TableHeaderCell className={s.end}>In / out</TableHeaderCell>
                  <TableHeaderCell className={s.end}>Balance</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{p.rows.map((r, i) => (
                  <TableRow key={i}>
                    <TableCell>{stamp(r.at)}</TableCell>
                    <TableCell>{r.entry}{r.ref && <Text size={200} className={s.muted}> {r.ref}</Text>}</TableCell>
                    <TableCell>{r.batch ?? '—'}</TableCell>
                    <TableCell>{r.patient ?? '—'}{r.prescriber && <Text size={200} className={s.muted}> · {r.prescriber}</Text>}</TableCell>
                    <TableCell>{r.by ?? '—'}</TableCell>
                    <TableCell className={s.end}>{r.qty}</TableCell>
                    <TableCell className={s.end}><Text weight="semibold">{r.balance}</Text></TableCell>
                  </TableRow>
                ))}</TableBody>
              </Table>
            </div>
          </section>
        )))}
    </div>
  )
}

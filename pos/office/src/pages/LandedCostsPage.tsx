import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, SearchBox, Table, TableBody,
  TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { AddRegular, DeleteRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type LandedLines, type LandedRow, type SpreadForm } from '../api'
import { Ask } from '../form/Ask'
import { Choose, ChooseMany, TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { day, END, useProcStyles } from './ProcurementParts'

type Charge = { kind: string; description: string; amount: string }
const blank = (): Charge => ({ kind: 'freight', description: '', amount: '' })

/**
 * Landed costs (part 19): freight, import duty, clearing and insurance spread over deliveries, so stock costs what it
 * really cost to bring in. The bill for the charge goes to "Freight, duty and clearing to spread"; Spread freight and
 * duty moves it into stock (what is still here) and cost of sales (what was already sold). Posted once: a correction
 * is another landed cost, with a negative amount. Each row opens its share per product.
 */
export function LandedCostsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.landedCosts, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [form, setForm] = useState<SpreadForm | null>(null)
  const [v, setV] = useState({ receipts: [] as string[], method: 'value', note: '', charges: [blank()] })
  const [open, setOpen] = useState<LandedLines | null>(null)
  const [done, setDone] = useState('')
  const [formError, setFormError] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  const spread = () => {
    setDone('')
    setFormError('')
    api.spreadForm().then((f) => { setForm(f); setV({ receipts: [], method: 'value', note: '', charges: [blank()] }) })
      .catch((e: unknown) => setFormError(e instanceof Error ? e.message : String(e)))
  }
  const charge = (i: number, patch: Partial<Charge>) => setV((o) => ({ ...o, charges: o.charges.map((c, j) => (j === i ? { ...c, ...patch } : c)) }))
  const ready = v.receipts.length > 0 && v.charges.length > 0 && v.charges.every((c) => c.description.trim() !== '' && c.amount.trim() !== '' && Number(c.amount) !== 0)
  return (
    <div className={page.page}>
      <PageHead title="Landed costs" hint="Freight, duty and clearing spread over deliveries, so stock costs what it really cost to bring in." classic="/admin/landed-costs" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or charge" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search landed costs" />
        <span className={s.grow} />
        {data?.can_spread && <Button appearance="primary" icon={<VehicleTruckProfileRegular />} onClick={spread}>Spread freight and duty</Button>}
      </div>
      {(error || formError) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || formError}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<LandedRow> label="Landed costs" rows={data?.rows ?? []} sort="date" dir="desc" loading={loading} empty="No landed costs match." onSort={() => undefined}
        onOpen={(r) => api.landedCost(r.id).then(setOpen).catch((e: unknown) => setFormError(e instanceof Error ? e.message : String(e)))}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
          { key: 'date', label: 'Date', render: (r) => day(r.date) },
          { key: 'charges', label: 'Charges', render: (r) => r.charges },
          { key: 'method', label: 'Spread', render: (r) => r.method },
          { key: 'total', label: 'Total', end: true, render: (r) => m(r.total) },
          { key: 'stock', label: 'Into stock', end: true, render: (r) => m(r.to_stock) },
          { key: 'cogs', label: 'Cost of sales', end: true, render: (r) => m(r.to_cogs) },
          { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {form && (
        <Ask title="Spread freight and duty" go="Post" width={720} ready={ready} onClose={() => setForm(null)}
          run={() => api.spreadLanded({ ...v, note: v.note || null }).then((r) => { setForm(null); setDone(r.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Text>First enter the freight, customs or clearing bill as a supplier bill, with an expense line to "Freight, duty and clearing to spread". Then spread it here over
              the deliveries it belongs to. What is still in stock costs more; what was already sold goes to cost of sales.</Text>
            <ChooseMany label="Deliveries (goods received)" options={form.receipts} value={v.receipts} onChange={(x) => setV({ ...v, receipts: x })} placeholder="Choose one or more" />
            <Choose label="Spread" required options={form.methods} value={v.method} onChange={(x) => setV({ ...v, method: x })}
              hint="By value suits most goods (a dearer item carries more of the freight). By quantity suits bulky goods of similar size." />
            {v.charges.map((c, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Charge ${i + 1}`}>
                <TextField label="Description" required maxLength={255} placeholder="e.g. Freight Kumasi–Tarkwa, waybill 4471" value={c.description} onChange={(x) => charge(i, { description: x })} />
                <Choose label="Kind" required options={form.kinds} value={c.kind} onChange={(x) => charge(i, { kind: x })} />
                <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
                  <TextField label="Amount (before VAT)" required type="number" step="any" prefix={form.currency} value={c.amount} onChange={(x) => charge(i, { amount: x })}
                    hint={i === 0 ? 'Negative to correct an earlier one.' : undefined} />
                  <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove charge ${i + 1}`} disabled={v.charges.length === 1}
                    onClick={() => setV({ ...v, charges: v.charges.filter((_, j) => j !== i) })} />
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setV({ ...v, charges: [...v.charges, blank()] })}>Add a charge</Button></div>
            <TextField label="Note" maxLength={255} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
          </div>
        </Ask>
      )}

      {open && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setOpen(null) }}>
          <DialogSurface style={{ maxWidth: 820 }}>
            <DialogBody>
              <DialogTitle>{open.number}: per product</DialogTitle>
              <DialogContent>
                <Text block style={{ marginBottom: 8 }}>{open.charges.map((c) => `${c.kind}: ${c.description} (${money(c.amount, open.currency, open.exponent)})`).join(' · ')}</Text>
                {open.note && <Text block className={s.muted} style={{ marginBottom: 8 }}>{open.note}</Text>}
                {open.lines.length === 0 ? <Text>Nothing.</Text> : (
                  <div style={{ overflowX: 'auto' }}>
                    <Table size="small" aria-label="Per product" style={{ minWidth: 620 }}>
                      <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell button={END}>Share</TableHeaderCell>
                        <TableHeaderCell button={END}>Into stock</TableHeaderCell><TableHeaderCell button={END}>Cost of sales</TableHeaderCell>
                        <TableHeaderCell button={END}>Cost per unit before → after</TableHeaderCell></TableRow></TableHeader>
                      <TableBody>{open.lines.map((l, i) => (
                        <TableRow key={i}><TableCell>{l.product}</TableCell><TableCell className={s.end}>{money(l.allocated, open.currency, open.exponent)}</TableCell>
                          <TableCell className={s.end}>{money(l.to_stock, open.currency, open.exponent)}</TableCell>
                          <TableCell className={s.end}>{money(l.to_cogs, open.currency, open.exponent)}</TableCell>
                          <TableCell className={s.end}>{l.before ?? '—'} → {l.after ?? '—'}</TableCell></TableRow>
                      ))}</TableBody>
                    </Table>
                  </div>
                )}
              </DialogContent>
              <DialogActions><Button onClick={() => setOpen(null)}>Close</Button></DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  )
}

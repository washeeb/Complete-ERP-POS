import { Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type MfgOrderValues, type MfgTable, type Option } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { END, useProcStyles } from './ProcurementParts'

/** A table as the current Manufacturing pages show it (materials, operations, BOM lines): headings, rows, number columns. */
export function MfgRows({ t, label, empty }: { t: MfgTable; label: string; empty: string }) {
  const s = useProcStyles()
  if (t.rows.length === 0) return <Text className={s.muted}>{empty}</Text>
  return (
    <Table size="small" aria-label={label}>
      <TableHeader><TableRow>{t.headings.map((h, i) => (
        <TableHeaderCell key={i} className={t.numeric.includes(i) ? s.end : undefined} button={t.numeric.includes(i) ? END : undefined}>{h}</TableHeaderCell>
      ))}</TableRow></TableHeader>
      <TableBody>{t.rows.map((r, ri) => (
        <TableRow key={ri}>{r.map((c, ci) => <TableCell key={ci} className={t.numeric.includes(ci) ? s.end : undefined}>{c}</TableCell>)}</TableRow>
      ))}</TableBody>
    </Table>
  )
}

/**
 * New work order (part 35), as the current "New work order" form: the warehouse materials come from and output goes to,
 * the product to make (those with a bill of materials in use; chosen already when it comes from a BOM), how many, needed
 * by, the customer order it is for, and a note. It starts as a draft that does nothing until released.
 */
export function NewMfgOrder({ product, onClose, onDone }: { product?: string; onClose: () => void; onDone: (id: string) => void }) {
  const f = useFieldStyles()
  const [opts, setOpts] = useState<{ warehouses: Option[]; products: Option[] } | null>(null)
  const [v, setV] = useState<MfgOrderValues | null>(null)
  useEffect(() => {
    api.mfgOrderForm(product ?? '').then((x) => { setOpts(x); setV({ ...x.values, warehouse_id: x.values.warehouse_id || (x.warehouses.length === 1 ? x.warehouses[0].id : '') }) })
      .catch(() => setOpts({ warehouses: [], products: [] }))
  }, [product])
  const put = <K extends keyof MfgOrderValues>(k: K) => (x: MfgOrderValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  return (
    <Ask title="New work order" go="Create" width={640} onClose={onClose} ready={!!v && !!v.warehouse_id && !!v.product_id && Number(v.qty) > 0}
      run={() => api.newMfgOrder(v!).then((x) => onDone(x.id))}>
      {!opts || !v ? <Text>Opening…</Text> : (
        <div className={f.grid}>
          <Text className={f.spanAll}>It starts as a draft. Nothing moves until it is released.</Text>
          <Choose label="Warehouse (materials from, output to)" required options={opts.warehouses} value={v.warehouse_id} onChange={put('warehouse_id')} />
          <Choose label="Product to make" required options={opts.products} value={v.product_id} onChange={put('product_id')} />
          <TextField label="How many (base units)" required type="number" min={0.001} step="any" value={v.qty} onChange={put('qty')} />
          <TextField label="Needed by" type="date" value={v.due_on} onChange={put('due_on')} />
          <div className={f.spanAll}>
            <SearchPicker label="For customer order (optional)" value={v.customer_order_id} text={v.customer_order} search={api.mfgCustomerOrders} placeholder="Order number"
              onChange={(x) => setV({ ...v, customer_order_id: x?.id ?? '', customer_order: x?.label ?? null })} />
          </div>
          <LongText label="Note" className={f.spanAll} maxLength={2000} value={v.note} onChange={put('note')} />
          {opts.products.length === 0 && <Text className={f.spanAll}>No product has a bill of materials in use yet. Put one in use first.</Text>}
        </div>
      )}
    </Ask>
  )
}

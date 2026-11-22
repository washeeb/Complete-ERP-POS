import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, SearchBox, Table, TableBody, TableCell, TableRow,
  Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { BillDetail, BillList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { Fact, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = BillList['rows'][number]
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/**
 * Restaurant → Bills (part 30): open tables and tabs now, and the history of every order, with the items voided and who
 * did it. Read-only (bills are worked at the till); a row opens the bill. The bill opened lives in the address (?bill=).
 */
export function BillsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.restBills, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [bill, setBill] = useState<BillDetail | null>(null)
  const open = str(params, 'bill')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!open) { setBill(null); return }
    api.restBill(open).then(setBill).catch(() => set({ bill: '' }))
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Bills" hint="Open tables and tabs now, and every order, with voids and who did them." classic="/admin/dining-orders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or waiter" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search bills" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="Kind" any="Any kind" options={data?.kinds ?? []} value={str(params, 'kind')} onChange={(v) => set({ kind: v })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Bills" rows={data?.rows ?? []} sort="opened" dir="desc" loading={loading} empty="No bills match." onSort={() => undefined}
        onOpen={(r) => set({ bill: r.id })}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'opened', label: 'Opened', render: (r) => when(r.opened) },
          { key: 'where', label: 'Where', render: (r) => r.where },
          { key: 'waiter', label: 'Waiter', render: (r) => r.waiter },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'voids', label: 'Voided items', end: true, render: (r) => r.voids || '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {bill && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) set({ bill: '' }) }}>
          <DialogSurface style={{ maxWidth: 720 }}><DialogBody>
            <DialogTitle>Bill {bill.number}</DialogTitle>
            <DialogContent>
              <div className={s.facts} style={{ marginBottom: 12 }}>
                <Fact label="Where">{bill.where}</Fact>
                <Fact label="Waiter">{bill.waiter}</Fact>
                <Fact label="Status">{bill.status_label}</Fact>
                <Fact label="Guests">{bill.guests}</Fact>
                <Fact label="Opened">{when(bill.opened)}</Fact>
                <Fact label="Closed">{when(bill.closed)}</Fact>
                <Fact label="Note">{bill.note ?? '—'}</Fact>
              </div>
              <Table size="small" aria-label="Items"><TableBody>{bill.items.map((i) => (
                <TableRow key={i.id}>
                  <TableCell>{i.qty} × {i.name}{i.extras && <Text size={200} block className={s.muted}>{i.extras}</Text>}</TableCell>
                  <TableCell>{i.status_label}{i.void && <Text size={200} block className={s.muted}>{i.void}</Text>}</TableCell>
                  <TableCell>{i.paid ? 'Paid' : ''}</TableCell>
                </TableRow>
              ))}</TableBody></Table>
            </DialogContent>
            <DialogActions><Button onClick={() => set({ bill: '' })}>Close</Button></DialogActions>
          </DialogBody></DialogSurface>
        </Dialog>
      )}
    </div>
  )
}

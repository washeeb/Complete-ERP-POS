import {
  Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { GiftRegular, HistoryRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type GiftCardRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  one: { display: 'grid', gap: '10px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const END = { style: { justifyContent: 'flex-end' } }
const BLANK = { amount: '', paid_by: '', reference: '', code: '', customer_id: '', customer: null as string | null }

/**
 * Gift cards & credit (part 16): every gift card and store credit with its balance and when it was last used. Search
 * by card number or customer; filter by kind. History shows each sale, use and refund of a card. Sell gift card takes
 * the amount and how it was paid (store credit comes from returns).
 */
export function GiftCardsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.giftCards, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  const [selling, setSelling] = useState(false)
  const [v, setV] = useState(BLANK)
  const [history, setHistory] = useState<{ card: GiftCardRow; rows: { at: string; type: string; doc: string | null; amount: number }[] | null } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  const kind = str(params, 'kind')
  const open = (card: GiftCardRow) => {
    setHistory({ card, rows: null })
    api.giftCardHistory(card.id).then((r) => setHistory({ card, rows: r.rows })).catch(() => setHistory({ card, rows: [] }))
  }
  const columns: Column<GiftCardRow>[] = [
    { key: 'code', label: 'Card', render: (r) => <Text font="monospace" weight="semibold">{r.code}</Text> },
    { key: 'kind', label: 'Kind', render: (r) => r.kind },
    { key: 'customer', label: 'Customer', render: (r) => r.customer ?? '—' },
    { key: 'balance', label: 'Balance', end: true, render: (r) => m(r.balance) },
    { key: 'used', label: 'Last used', render: (r) => (r.last_used ? when(r.last_used) : '—') },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.status === 'active' ? 'success' : 'subtle'}>{r.status}</Badge> },
    { key: 'history', label: '', render: (r) => <Button size="small" icon={<HistoryRegular />} onClick={(e) => { e.stopPropagation(); open(r) }}>History</Button> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Gift cards & credit" hint="Every gift card and store credit with its balance. Sell a gift card here; store credit comes from returns." classic="/admin/stored-values" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Card number or customer" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search gift cards" />
        <Dropdown aria-label="Kind" value={kind ? data?.kinds.find((x) => x.id === kind)?.label ?? '' : 'Any kind'} selectedOptions={[kind]}
          onOptionSelect={(_, d) => set({ kind: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Any kind</Option>{(data?.kinds ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {data?.can_sell && <Button appearance="primary" icon={<GiftRegular />} onClick={() => { setDone(''); setV(BLANK); setSelling(true) }}>Sell gift card</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Gift cards and credit" columns={columns} rows={data?.rows ?? []} sort="code" dir="desc" loading={loading}
        empty="No gift cards or store credit match." onSort={() => undefined} onOpen={open} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {selling && data && (
        <Ask title="Sell a gift card" go="Sell" ready={Number(v.amount) >= 1 && v.paid_by !== ''} onClose={() => setSelling(false)}
          run={async () => { const r = await api.sellGiftCard({ amount: v.amount, paid_by: v.paid_by, reference: v.reference, code: v.code, customer_id: v.customer_id }); setSelling(false); setDone(r.message); setNonce((n) => n + 1) }}>
          <div className={s.one}>
            <TextField label="Amount" required type="number" min={1} step="any" prefix={data.currency} value={v.amount} onChange={(x) => setV({ ...v, amount: x })} />
            <Choose label="Paid with" required options={data.paid_by} value={v.paid_by} onChange={(x) => setV({ ...v, paid_by: x })} />
            <TextField label="Payment reference" maxLength={30} value={v.reference} onChange={(x) => setV({ ...v, reference: x })} />
            <TextField label="Card number (printed card)" maxLength={30} hint="Leave blank to generate one." value={v.code} onChange={(x) => setV({ ...v, code: x })} />
            <SearchPicker label="For customer (optional)" value={v.customer_id} text={v.customer} search={api.giftCardCustomers} placeholder="Name, code or phone"
              onChange={(c) => setV({ ...v, customer_id: c?.id ?? '', customer: c?.label ?? null })} />
          </div>
        </Ask>
      )}
      {history && (
        <Ask title={`History: ${history.card.code}`} go="Close" ready onClose={() => setHistory(null)} run={async () => setHistory(null)}>
          {history.rows === null ? <Spinner size="tiny" /> : history.rows.length === 0 ? <Text className={s.muted}>Nothing yet.</Text> : (
            <Table size="small" aria-label="Card history">
              <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>Document</TableHeaderCell>
                <TableHeaderCell button={END}>Amount</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{history.rows.map((r, i) => (
                <TableRow key={i}><TableCell>{r.at}</TableCell><TableCell>{r.type}</TableCell><TableCell>{r.doc ?? '—'}</TableCell><TableCell className={s.end}>{m(r.amount)}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Ask>
      )}
    </div>
  )
}

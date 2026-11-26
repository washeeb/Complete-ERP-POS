import { Button, makeStyles, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { ArrowSwapRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BankForms, type MoneyMoveRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { MoveMoney } from './MoveMoney'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/** Money moved (release 8): moves between your own accounts, newest first — banking the till cash, petty cash top-ups, MoMo sweeps — with Move money. */
export function MoneyMovedPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [forms, setForms] = useState<BankForms | null>(null)
  const [moving, setMoving] = useState(false)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.moneyMoved, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.bankForms().then(setForms).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const columns: Column<MoneyMoveRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'moved_on', render: (r) => day(r.date) },
    { key: 'from', label: 'From', render: (r) => r.from ?? '—' },
    { key: 'to', label: 'To', render: (r) => r.to ?? '—' },
    { key: 'amount', label: 'Amount', sort: 'amount_minor', end: true, render: (r) => <Text weight="semibold">{m(r.amount)}</Text> },
    { key: 'reference', label: 'Reference', render: (r) => <>{r.reference ?? '—'}{r.note && <Text block size={200} className={s.muted}>{r.note}</Text>}</> },
    { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Money moved" hint="Moves between your own accounts: banking the till cash, topping up petty cash, sweeping mobile money to the bank." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search money moved" />
        <Button onClick={() => navigate('/office/bank-accounts')}>Bank & cash</Button>
        <span className={s.grow} />
        {data?.can_move && forms && <Button appearance="primary" icon={<ArrowSwapRegular />} onClick={() => { setDone(''); setMoving(true) }}>Move money</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Money moved" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'moved_on'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No money moved yet." onSort={(sort, dir) => set({ sort, dir })} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {moving && forms && <MoveMoney forms={forms} onClose={() => setMoving(false)} onDone={(msg) => { setMoving(false); setDone(msg); setNonce((n) => n + 1) }} />}
    </div>
  )
}

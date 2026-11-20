import {
  Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AssetDetail, type AssetOptions } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px 16px', marginBottom: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 16px', overflowX: 'auto', maxWidth: '520px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One fixed asset (release 8): its facts, book value now, how it left the books, and the depreciation charged month
 * by month. Dispose of (sold, scrapped, stolen…) depreciates it to that month and takes it off the books, with any
 * money received.
 */
export function FixedAssetPage({ id }: { id: string }) {
  const page = usePageStyles()
  const s = useStyles()
  const [a, setA] = useState<AssetDetail | null>(null)
  const [options, setOptions] = useState<AssetOptions | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [disposing, setDisposing] = useState(false)
  useEffect(() => { api.fixedAssetOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => { api.fixedAsset(id).then((d) => { setA(d.asset); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id, nonce])

  if (!a) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the asset…" />}</div>
  const m = (v: number) => money(v, a.currency, a.exponent)
  const fact = (label: string, value: React.ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text>{value}</Text></div>

  return (
    <div className={page.page}>
      <PageHead title={`${a.number} ${a.name}`} hint="A fixed asset: what it cost, its value now, and the depreciation charged so far." classic={`/admin/fixed-assets/${a.id}`} />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/fixed-assets')}>Fixed assets</Button>
        <span className={s.grow} />
        {a.can_dispose && options && <Button icon={<DeleteRegular />} style={{ color: 'var(--zx-bad)' }} onClick={() => { setDone(''); setDisposing(true) }}>Dispose of</Button>}
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <div className={s.facts}>
        {fact('Kind', a.kind ?? '—')}
        {fact('Status', <Badge appearance="tint" color={a.status === 'active' ? 'success' : 'subtle'}>{a.status === 'active' ? 'Active' : 'Disposed'}</Badge>)}
        {fact('Used at', a.branch ?? '—')}
        {fact('Bought on', day(a.acquired_on))}
        {fact('Cost', m(a.cost))}
        {fact('Worth at end of life', m(a.residual))}
        {fact('Life', `${a.life_months} months`)}
        {fact('Book value now', <Text weight="bold">{m(a.status === 'disposed' ? 0 : a.book_now)}</Text>)}
        {a.opening_depreciation > 0 && fact('Depreciation in the opening balances', m(a.opening_depreciation))}
        {fact('Serial', a.serial_no ?? '—')}
        {fact('Location', a.location ?? '—')}
        {a.disposal && fact('Disposed', `${a.disposal.date ?? ''}: ${a.disposal.reason} (${m(a.disposal.proceeds)})`)}
      </div>
      <section className={s.panel} aria-label="Depreciation charged">
        <Text weight="semibold" size={400} block>Depreciation charged</Text>
        {a.schedule.length === 0 ? <Text className={s.muted}>None yet. It is charged at the end of each month from the month after purchase.</Text> : (
          <Table size="small" aria-label="Depreciation charged">
            <TableHeader><TableRow><TableHeaderCell>Month</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Amount</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{a.schedule.map((r) => <TableRow key={r.month}><TableCell>{r.month}</TableCell><TableCell className={s.end}>{m(r.amount)}</TableCell></TableRow>)}</TableBody>
          </Table>
        )}
      </section>
      {disposing && options && <Dispose id={a.id} name={a.name} accounts={options.money_accounts} onClose={() => setDisposing(false)} onDone={(msg) => { setDisposing(false); setDone(msg); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function Dispose({ id, name, accounts, onClose, onDone }: { id: string; name: string; accounts: { id: string; label: string }[]; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const today = new Date().toISOString().slice(0, 10)
  const [date, setDate] = useState(today)
  const [reason, setReason] = useState('')
  const [proceeds, setProceeds] = useState('0')
  const [account, setAccount] = useState('')
  const paid = Number(proceeds) > 0
  return (
    <Ask title={`Dispose of ${name}?`} go="Dispose of it" ready={date !== '' && reason.trim() !== '' && (!paid || !!account)} onClose={onClose}
      run={async () => onDone((await api.disposeAsset(id, { date, reason, proceeds, account_id: account || null })).message)}>
      <div className={s.form}>
        <Text>It is depreciated up to the month it went, then taken off the books; any gain or loss against the money received is posted.</Text>
        <Field label="Date" required><Input type="date" value={date} max={today} onChange={(_, d) => setDate(d.value)} /></Field>
        <Field label="Sold, scrapped, stolen…" required><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
        <Field label="Money received"><Input type="number" min={0} value={proceeds} onChange={(_, d) => setProceeds(d.value)} /></Field>
        {paid && <Field label="Money went into" required>
          <Dropdown value={accounts.find((x) => x.id === account)?.label ?? ''} selectedOptions={[account]} onOptionSelect={(_, d) => setAccount(d.optionValue ?? '')}>
            {accounts.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>}
      </div>
    </Ask>
  )
}

import { Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { MoneyRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ConsignmentOwed } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', justifyContent: 'flex-end', marginBottom: '10px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * Consignment to pay (release 5): what each supplier is owed for consigned goods sold, per branch. Goods on consignment
 * stay the supplier's until sold; each sale adds what is owed here. Settle raises a supplier bill for everything owed
 * up to today, approved and paid in Payables as usual.
 */
export function ConsignmentPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [data, setData] = useState<ConsignmentOwed | null>(null)
  const [error, setError] = useState('')
  const [settling, setSettling] = useState(false)
  const [done, setDone] = useState('')
  useEffect(() => { api.consignment().then(setData).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up what is owed…" /></div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const owed = data.rows.reduce((t, r) => t + r.owed, 0)

  return (
    <div className={page.page}>
      <PageHead title="Consignment to pay" hint="Goods on consignment stay the supplier's until sold. Each sale adds what is owed here." classic="/admin/consignment-to-pay" />
      <div className={s.bar}>{data.rows.length > 0 && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => setSettling(true)}>Settle</Button>}</div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Cards items={[{ label: 'Suppliers and branches', value: data.rows.length.toLocaleString('en-GB') }, { label: 'Owed', value: m(owed), tone: owed ? 'var(--zx-warn)' : undefined }]} />
      <section className={s.panel} aria-label="Owed for consigned goods sold">
        {data.rows.length === 0 ? <Text className={s.muted}>Nothing is owed.</Text> : (
          <Table size="small" aria-label="Owed for consigned goods sold">
            <TableHeader><TableRow>
              <TableHeaderCell>Supplier</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Quantity sold</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Sold for (before tax)</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Owed</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{data.rows.map((r) => (
              <TableRow key={r.key}>
                <TableCell>{r.supplier_open ? <Link onClick={() => navigate(r.supplier_open!)}>{r.supplier}</Link> : r.supplier}</TableCell>
                <TableCell>{r.branch}</TableCell><TableCell className={s.end}>{r.qty}</TableCell>
                <TableCell className={s.end}>{m(r.sold)}</TableCell><TableCell className={s.end}><Text weight="semibold">{m(r.owed)}</Text></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </section>
      {settling && <Settle data={data} money={m} onClose={() => setSettling(false)} onDone={(next) => { setData(next); setSettling(false); setDone(`Bill ${next.bill} raised. Approve and pay it in Payables.`) }} />}
    </div>
  )
}

function Settle({ data, money: m, onClose, onDone }: { data: ConsignmentOwed; money: (v: number) => string; onClose: () => void; onDone: (next: ConsignmentOwed) => void }) {
  const s = useStyles()
  const [who, setWho] = useState(data.rows.length === 1 ? data.rows[0].key : '')
  const [statement, setStatement] = useState('')
  return (
    <Ask title="Settle" go="Raise the bill" ready={!!who && !!statement.trim()} onClose={onClose} run={() => api.settleConsignment(who, statement.trim()).then(onDone)}>
      <Text block style={{ marginBottom: 10 }}>Raises a supplier bill for everything owed up to today. It then goes to Payables, to be approved and paid as usual.</Text>
      <div className={s.one}>
        <Choose label="Supplier and branch" required options={data.rows.map((r) => ({ id: r.key, label: `${r.supplier} · ${r.branch} · ${m(r.owed)}` }))} value={who} onChange={setWho} />
        <TextField label="Supplier's statement or invoice number" required maxLength={60} value={statement} onChange={setStatement} />
      </div>
    </Ask>
  )
}

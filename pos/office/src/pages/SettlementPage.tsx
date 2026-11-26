import { Button, Input, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArrowDownloadRegular, ArrowLeftRegular, CheckmarkCircleRegular, DismissCircleRegular, EditRegular, SendRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type SettlementDetail } from '../api'
import { Ask } from '../form/Ask'
import { LongText, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'count' | 'submit' | 'post' | 'cancel'

/**
 * One van settlement (part 34), as the current page: the van, day, rep, sales, cash expected and handed in (over or
 * short), the stock difference once settled, who started, submitted and settled it; the stock on the van (should be,
 * counted, difference, unload, stays). Enter counts, Submit, Approve and settle (someone other than who submitted),
 * Cancel and the settlement sheet (PDF, to sign).
 */
export function SettlementPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<SettlementDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [counts, setCounts] = useState<Record<string, { counted: string; unload: string }>>({})
  const [text, setText] = useState('')
  const load = useCallback(() => {
    api.settlement(id).then((x) => { setD(x); onTitle(`Van settlement ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the settlement…" /></div>
  const m = (x: number | null) => (x === null ? '—' : money(x, d.currency, d.exponent))
  const a = d.actions
  const open = (x: Dialog) => {
    setDone(''); setText('')
    if (x === 'count') setCounts(Object.fromEntries(d.lines.map((l) => [l.product_id, { ...l.values }])))
    setDialog(x)
  }
  const run = (action: Dialog, body: Record<string, unknown> = {}) =>
    api.settlementAction(d.id, action, body).then((x) => { setDialog(null); setError(''); setDone(x.message); load() })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/distribution/settlements'))
  const over = d.cash_counted === null ? null : d.cash_counted - (d.cash_expected ?? 0)
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Van settlements</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.van}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.count && <Button icon={<EditRegular />} onClick={() => open('count')}>Enter counts</Button>}
          {a.submit && <Button icon={<SendRegular />} onClick={() => open('submit')}>Submit</Button>}
          {a.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('post')}>Approve and settle</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => open('cancel')}>Cancel</Button>}
          <Button icon={<ArrowDownloadRegular />} as="a" href={`/office-api/distribution/settlements/${d.id}/sheet`}>Settlement sheet (PDF)</Button>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Settlement">
        <div className={s.facts}>
          <Fact label="Van">{d.van ?? '—'}{d.reg && <Text size={200} block className={s.muted}>{d.reg}</Text>}</Fact>
          <Fact label="Day">{day(d.day)}</Fact>
          <Fact label="Rep">{d.rep ?? '—'}</Fact>
          <Fact label="Sales">{m(d.sales)}</Fact>
          <Fact label="Cash expected">{d.cash_expected === null ? 'Shift not closed yet' : m(d.cash_expected)}</Fact>
          <Fact label="Cash handed in">{m(d.cash_counted)}{over !== null && <Text size={200} block className={s.muted}>Over / short: {m(over)}</Text>}</Fact>
          <Fact label="Stock difference (value)">{m(d.stock_variance)}</Fact>
          <Fact label="Started by">{d.started_by ?? '—'}</Fact>
          <Fact label="Submitted by">{d.submitted_by ?? '—'}</Fact>
          <Fact label="Settled by">{d.posted_by ?? '—'}</Fact>
        </div>
        {d.note && <Text block style={{ marginTop: 10 }}><b>Note:</b> {d.note}</Text>}
      </Panel>
      <Panel title="Stock on the van">
        <Table size="small" aria-label="Stock on the van" style={{ minWidth: 720 }}>
          <TableHeader><TableRow><TableHeaderCell>SKU</TableHeaderCell><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell button={END}>Should be on van</TableHeaderCell>
            <TableHeaderCell button={END}>Counted</TableHeaderCell><TableHeaderCell button={END}>Difference</TableHeaderCell><TableHeaderCell button={END}>Unload to warehouse</TableHeaderCell>
            <TableHeaderCell button={END}>Stays on van</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.lines.length === 0 && <TableRow><TableCell colSpan={7}><Text className={s.muted}>Nothing was on the van.</Text></TableCell></TableRow>}
            {d.lines.map((l) => (
              <TableRow key={l.product_id}><TableCell>{l.sku}</TableCell><TableCell>{l.name}</TableCell><TableCell className={s.end}>{l.expected}</TableCell>
                <TableCell className={s.end}>{l.counted || '—'}</TableCell><TableCell className={s.end}>{l.difference ?? ''}</TableCell><TableCell className={s.end}>{l.unload}</TableCell>
                <TableCell className={s.end}>{l.stays ?? ''}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {dialog === 'count' && (
        <Ask title="Enter counts" go="Save counts" width={760} ready onClose={() => setDialog(null)} run={() => run('count', { counts })}>
          <Table size="small" aria-label="Counts">
            <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Counted on van</TableHeaderCell><TableHeaderCell>Unload</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.lines.map((l) => (
              <TableRow key={l.product_id}>
                <TableCell>{l.sku} · {l.name}<Text size={200} block className={s.muted}>should be {l.expected}</Text></TableCell>
                <TableCell><Input type="number" min={0} step="any" aria-label={`Counted: ${l.name}`} value={counts[l.product_id]?.counted ?? ''}
                  onChange={(_, e) => setCounts({ ...counts, [l.product_id]: { ...counts[l.product_id], counted: e.value } })} /></TableCell>
                <TableCell><Input type="number" min={0} step="any" aria-label={`Unload: ${l.name}`} value={counts[l.product_id]?.unload ?? '0'}
                  onChange={(_, e) => setCounts({ ...counts, [l.product_id]: { ...counts[l.product_id], unload: e.value } })} /></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        </Ask>
      )}
      {dialog === 'submit' && (
        <Ask title="Submit for approval?" go="Submit" ready onClose={() => setDialog(null)} run={() => run('submit', { note: text })}>
          <div className={s.one}>
            <Text>Someone else approves and settles it. Every product must be counted (0 if none is left).</Text>
            <LongText label="Note (e.g. why something is short)" maxLength={2000} value={text} onChange={setText} />
          </div>
        </Ask>
      )}
      {dialog === 'post' && (
        <Ask title="Approve and settle?" go="Approve and settle" ready onClose={() => setDialog(null)} run={() => run('post')}>
          <Text>Shortages and surpluses are booked on the van, and the unloaded stock goes back to the warehouse.</Text>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel this settlement?" go="Cancel settlement" ready={text.trim() !== ''} onClose={() => setDialog(null)} run={() => run('cancel', { reason: text })}>
          <TextField label="Reason" required maxLength={255} value={text} onChange={setText} />
        </Ask>
      )}
    </div>
  )
}

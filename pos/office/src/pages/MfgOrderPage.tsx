import { Button, Checkbox, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowDownloadRegular, ArrowLeftRegular, ArrowUndoRegular, ArrowUploadRegular, CheckmarkCircleRegular, ClockRegular, DeleteRegular, DismissCircleRegular,
  FlagRegular, PlayRegular, ShieldCheckmarkRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type MfgOrderDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { MfgRows } from './MfgParts'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Action = 'release' | 'output' | 'inspect' | 'issue' | 'return' | 'time' | 'finish' | 'cancel'
type Check = { check: string; result: 'pass' | 'fail'; value: string }
const BLANK = { sub: true, good: '', scrap: '', passed: '', failed: '', batch: '', expires: '', note: '', seq: '', minutes: '', reason: '' }

/**
 * One manufacturing work order (part 35), as the current page: the product, warehouse, planned, made, scrapped and waiting
 * for the quality check; its bill of materials, customer order and parent order; the cost (materials, labour, by-products,
 * into stock, work in progress, differences, per good unit); materials and operations; time and cost entries; quality
 * checks. Release, Report output, Quality check, Issue and Return materials, Record time, Finish, Cancel, Job card (PDF).
 */
export function MfgOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<MfgOrderDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<Action | null>(null)
  const [f, setF] = useState(BLANK)
  const [qty, setQty] = useState<Record<string, string>>({})
  const [checks, setChecks] = useState<Check[]>([])
  const load = useCallback(() => {
    api.mfgOrder(id).then((x) => { setD(x); onTitle(`${x.number} · ${x.product ?? ''}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the work order…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const a = d.actions
  const show = (x: Action) => { setDone(''); setF({ ...BLANK, seq: d.steps[0]?.id ?? '' }); setQty({}); setChecks([]); setAsk(x) }
  const run = (action: Action, body: Record<string, unknown> = {}) => api.mfgOrderAction(d.id, action, body).then((x) => {
    setAsk(null); setError(''); setDone(x.message); load()
  })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/manufacturing/orders'))
  const anyQty = Object.values(qty).some((x) => Number(x) > 0)
  const put = (k: keyof typeof BLANK) => (x: string) => setF({ ...f, [k]: x })
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Work orders</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.product}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.release && <Button appearance="primary" icon={<PlayRegular />} onClick={() => show('release')}>Release</Button>}
          {a.output && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => show('output')}>Report output</Button>}
          {a.inspect && <Button icon={<ShieldCheckmarkRegular />} onClick={() => show('inspect')}>Quality check</Button>}
          {a.issue && <Button icon={<ArrowUploadRegular />} onClick={() => show('issue')}>Issue materials</Button>}
          {a.time && <Button icon={<ClockRegular />} onClick={() => show('time')}>Record time</Button>}
          {a.return && <Button icon={<ArrowUndoRegular />} onClick={() => show('return')}>Return materials</Button>}
          {a.finish && <Button icon={<FlagRegular />} onClick={() => show('finish')}>Finish</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => show('cancel')}>Cancel</Button>}
          <Button icon={<ArrowDownloadRegular />} as="a" href={`/office-api/manufacturing/orders/${d.id}/card`}>Job card (PDF)</Button>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Work order">
        <div className={s.facts}>
          <Fact label="Product">{d.product}{d.sku && <Text size={200} block className={s.muted}>{d.sku}</Text>}</Fact>
          <Fact label="Warehouse">{d.warehouse}</Fact>
          <Fact label="Planned">{d.planned}</Fact>
          <Fact label="Made (in stock)">{d.done}</Fact>
          <Fact label="Scrapped">{d.scrapped}</Fact>
          {d.qc_required && <Fact label="Waiting for quality check">{d.awaiting_qc}</Fact>}
          <Fact label="Needed by">{day(d.due)}</Fact>
          <Fact label="Bill of materials">{d.bom ? <Link onClick={() => navigate(d.bom!.open)}>{d.bom.label}</Link> : '—'}</Fact>
          <Fact label="Customer order">{d.customer_order ?? '—'}</Fact>
          <Fact label="Sub-assembly for">{d.parent ? <Link onClick={() => navigate(d.parent!.open)}>{d.parent.number}</Link> : '—'}</Fact>
          <Fact label="Raised by">{d.raised_by ?? '—'}</Fact>
          <Fact label="Released by">{d.released_by ?? '—'}{d.released_at && <Text size={200} block className={s.muted}>{d.released_at}</Text>}</Fact>
          <Fact label="Finished / cancelled by">{d.closed_by ?? '—'}{d.close_reason && <Text size={200} block className={s.muted}>{d.close_reason}</Text>}</Fact>
        </div>
        {d.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}><b>Note:</b> {d.note}</Text>}
      </Panel>
      <Panel title="Cost">
        <div className={s.facts}>
          <Fact label="Materials used">{m(d.cost.material)}</Fact>
          <Fact label="Labour and overhead">{m(d.cost.labour)}</Fact>
          <Fact label="Less by-products">{m(d.cost.by_product)}</Fact>
          <Fact label="Put into stock">{m(d.cost.output)}</Fact>
          <Fact label="Still in work in progress">{m(d.cost.wip)}</Fact>
          <Fact label="Cost differences at finish">{m(d.cost.variance)}</Fact>
          <Fact label="Cost per good unit"><b>{d.cost.per_unit === null ? '—' : m(d.cost.per_unit)}</b></Fact>
        </div>
      </Panel>
      <Panel title="Materials"><MfgRows t={d.materials} label="Materials" empty="No materials." /></Panel>
      {d.operations.rows.length > 0 && <Panel title="Operations"><MfgRows t={d.operations} label="Operations" empty="No operations." /></Panel>}
      {d.costs.length > 0 && (
        <Panel title="Time and other costs">
          <Table size="small" aria-label="Time and other costs">
            <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Minutes</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Amount</TableHeaderCell><TableHeaderCell>By</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.costs.map((c) => (
              <TableRow key={c.id}><TableCell>{c.when}</TableCell><TableCell>{c.what ?? '—'}</TableCell><TableCell className={s.end}>{c.minutes}</TableCell>
                <TableCell className={s.end}>{m(c.amount)}</TableCell><TableCell>{c.by ?? '—'}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
      )}
      {d.qc_required && (
        <Panel title="Quality checks">
          {d.checks.length === 0 ? <Text className={s.muted}>No checks yet. Output waits here until it is checked.</Text> : (
            <Table size="small" aria-label="Quality checks">
              <TableHeader><TableRow><TableHeaderCell>No.</TableHeaderCell><TableHeaderCell>When</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Passed</TableHeaderCell>
                <TableHeaderCell className={s.end} button={END}>Failed</TableHeaderCell><TableHeaderCell>Checks</TableHeaderCell><TableHeaderCell>By</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{d.checks.map((c) => (
                <TableRow key={c.id}><TableCell>{c.number}</TableCell><TableCell>{c.when}</TableCell><TableCell className={s.end}>{c.passed}</TableCell>
                  <TableCell className={s.end}>{c.failed}</TableCell><TableCell>{c.checks || '—'}{c.note && <Text size={200} block className={s.muted}>{c.note}</Text>}</TableCell>
                  <TableCell>{c.by ?? '—'}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}

      {ask === 'release' && (
        <Ask title="Release this work order?" go="Release" ready onClose={() => setAsk(null)} run={() => run('release', { sub_assemblies: f.sub })}>
          <div className={s.one}>
            <Text>Released orders can take materials and report output. Backflushed materials come out of stock as output is reported.</Text>
            <Checkbox label="Also raise orders for sub-assemblies this warehouse is short of" checked={f.sub} onChange={(_, e) => setF({ ...f, sub: !!e.checked })} />
          </div>
        </Ask>
      )}
      {ask === 'output' && (
        <Ask title="Report output" go="Record" ready={Number(f.good) > 0 || Number(f.scrap) > 0} onClose={() => setAsk(null)}
          run={() => run('output', { good: f.good || 0, scrap: f.scrap || 0, batch_no: f.batch, expires_on: f.expires, note: f.note })}>
          <div className={s.one}>
            <Text>{d.qc_required ? 'Good units wait for the quality check before they go into stock.' : 'Good units go into stock at once.'}</Text>
            <TextField label="Good units made" type="number" min={0} step="any" value={f.good} onChange={put('good')} />
            <TextField label="Scrapped units" type="number" min={0} step="any" value={f.scrap} onChange={put('scrap')} />
            {d.batches && <TextField label="Batch / lot number" hint="Empty = the work order number" maxLength={60} value={f.batch} onChange={put('batch')} />}
            {d.batches && !d.qc_required && <TextField label="Best before / expiry" type="date" value={f.expires} onChange={put('expires')} />}
            <TextField label="Note" maxLength={2000} value={f.note} onChange={put('note')} />
          </div>
        </Ask>
      )}
      {ask === 'inspect' && (
        <Ask title="Quality check" go="Record" width={680} onClose={() => setAsk(null)}
          ready={(Number(f.passed) > 0 || Number(f.failed) > 0) && checks.every((c) => c.check.trim() !== '')}
          run={() => run('inspect', { passed: f.passed || 0, failed: f.failed || 0, checks, note: f.note, batch_no: f.batch, expires_on: f.expires })}>
          <div className={s.one}>
            <Text>{d.awaiting_qc} waiting. Passed units go into stock; failed units are scrapped.</Text>
            <TextField label="Passed" type="number" min={0} step="any" value={f.passed} onChange={put('passed')} />
            <TextField label="Failed" type="number" min={0} step="any" value={f.failed} onChange={put('failed')} />
            {checks.map((c, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Check ${i + 1}`}>
                <TextField label="Check" required maxLength={120} value={c.check} onChange={(x) => setChecks(checks.map((y, j) => (j === i ? { ...y, check: x } : y)))} />
                <Choose label="Result" required options={[{ id: 'pass', label: 'Pass' }, { id: 'fail', label: 'Fail' }]} value={c.result}
                  onChange={(x) => setChecks(checks.map((y, j) => (j === i ? { ...y, result: x === 'fail' ? 'fail' : 'pass' } : y)))} />
                <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
                  <TextField label="Reading" maxLength={60} value={c.value} onChange={(x) => setChecks(checks.map((y, j) => (j === i ? { ...y, value: x } : y)))} />
                  <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove check ${i + 1}`} onClick={() => setChecks(checks.filter((_, j) => j !== i))} />
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setChecks([...checks, { check: '', result: 'pass', value: '' }])}>Add check</Button></div>
            <LongText label="Note (why units failed)" maxLength={2000} value={f.note} onChange={put('note')} />
            {d.batches && <TextField label="Batch / lot number" maxLength={60} value={f.batch} onChange={put('batch')} />}
            {d.batches && <TextField label="Best before / expiry" type="date" value={f.expires} onChange={put('expires')} />}
          </div>
        </Ask>
      )}
      {(ask === 'issue' || ask === 'return') && (
        <Ask title={ask === 'issue' ? 'Issue materials' : 'Return materials'} go={ask === 'issue' ? 'Issue' : 'Return'} width={680} ready={anyQty} onClose={() => setAsk(null)}
          run={() => run(ask, { quantities: qty })}>
          <div className={s.one}>
            <Text>{ask === 'issue' ? 'Materials taken by hand (not backflushed), or more than planned.' : 'Materials issued but not used, back into the warehouse.'}</Text>
            <Table size="small" aria-label="Materials">
              <TableHeader><TableRow><TableHeaderCell>Material</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Planned</TableHeaderCell>
                <TableHeaderCell className={s.end} button={END}>Issued</TableHeaderCell><TableHeaderCell>{ask === 'issue' ? 'Issue now' : 'Return now'}</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{d.issue_lines.filter((l) => ask === 'issue' || l.issued_milli > 0).map((l) => (
                <TableRow key={l.id}><TableCell>{l.item}</TableCell><TableCell className={s.end}>{l.planned}</TableCell><TableCell className={s.end}>{l.issued}</TableCell>
                  <TableCell><TextField label={`Quantity of ${l.item}`} type="number" min={0} step="any" value={qty[l.id] ?? ''} onChange={(x) => setQty({ ...qty, [l.id]: x })} /></TableCell></TableRow>
              ))}</TableBody>
            </Table>
          </div>
        </Ask>
      )}
      {ask === 'time' && (
        <Ask title="Record time" go="Record" ready={!!f.seq && Number(f.minutes) > 0} onClose={() => setAsk(null)}
          run={() => run('time', { seq: Number(f.seq), minutes: f.minutes, note: f.note })}>
          <div className={s.one}>
            <Choose label="Operation" required options={d.steps} value={f.seq} onChange={put('seq')} />
            <TextField label="Minutes" required type="number" min={0.01} step="any" value={f.minutes} onChange={put('minutes')} />
            <TextField label="Note" maxLength={200} value={f.note} onChange={put('note')} />
          </div>
        </Ask>
      )}
      {ask === 'finish' && (
        <Ask title="Finish this work order?" go="Finish" ready onClose={() => setAsk(null)} run={() => run('finish', { reason: f.reason })}>
          <div className={s.one}>
            <Text>The cost is settled: anything left in work in progress becomes a cost difference. It cannot take more materials or output.</Text>
            <TextField label="Reason, if fewer were made than planned" maxLength={255} value={f.reason} onChange={put('reason')} />
          </div>
        </Ask>
      )}
      {ask === 'cancel' && (
        <Ask title="Cancel this work order?" go="Cancel work order" ready={f.reason.trim() !== ''} onClose={() => setAsk(null)} run={() => run('cancel', { reason: f.reason })}>
          <TextField label="Why" required maxLength={255} value={f.reason} onChange={put('reason')} />
        </Ask>
      )}
    </div>
  )
}

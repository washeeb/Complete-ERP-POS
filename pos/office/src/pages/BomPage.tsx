import { Button, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkCircleRegular, CopyRegular, DismissCircleRegular, EditRegular, WrenchRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type BomDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { MfgRows } from './MfgParts'
import { Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Action = 'activate' | 'revise' | 'make' | 'archive'

/**
 * One bill of materials (part 35), as the current page: what it makes, one batch, the quality check, today's estimated cost
 * per unit, its lines and operations, and the method. Change (a draft), Put in use, New revision, Make… (a work order) and
 * Stop using.
 */
export function BomPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<BomDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<Action | null>(null)
  const [f, setF] = useState({ warehouse_id: '', qty: '', due_on: '', reason: '' })
  const load = useCallback(() => {
    api.bom(id).then((x) => { setD(x); onTitle(`${x.code} · ${x.product ?? ''}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the bill of materials…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const a = d.actions
  const show = (x: Action) => { setDone(''); setF({ warehouse_id: d.warehouses.length === 1 ? d.warehouses[0].id : '', qty: '', due_on: '', reason: '' }); setAsk(x) }
  const run = (action: Action, body: Record<string, unknown> = {}) => api.bomAction(d.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    setAsk(null); setError(''); setDone(x.message); load()
  })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/manufacturing/boms'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Bills of materials</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.code}</span>{d.product}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/manufacturing/boms/${d.id}/edit`)}>Change</Button>}
          {a.activate && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => show('activate')}>Put in use</Button>}
          {a.make && <Button appearance="primary" icon={<WrenchRegular />} onClick={() => show('make')}>Make…</Button>}
          {a.revise && <Button icon={<CopyRegular />} onClick={() => show('revise')}>New revision</Button>}
          {a.archive && <Button icon={<DismissCircleRegular />} onClick={() => show('archive')}>Stop using</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Bill of materials">
        <div className={s.facts}>
          <Fact label="Makes">{d.product}{d.sku && <Text size={200} block className={s.muted}>{d.sku}</Text>}</Fact>
          <Fact label="One batch makes">{d.batch}</Fact>
          <Fact label="Quality check">{d.qc_required ? 'Required before stock' : 'Not required'}</Fact>
          <Fact label="Estimated cost per unit (today)"><b>{m(d.estimate.per_unit)}</b>
            <Text size={200} block className={s.muted}>materials {m(d.estimate.material)} · labour {m(d.estimate.labour)}{d.estimate.by_product ? ` · less by-products ${m(d.estimate.by_product)}` : ''}</Text>
          </Fact>
        </div>
        {d.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}><b>Method / notes:</b> {d.note}</Text>}
      </Panel>
      <Panel title="Lines"><MfgRows t={d.lines} label="Lines" empty="No lines." /></Panel>
      <Panel title="Operations"><MfgRows t={d.operations} label="Operations" empty="No operations: only materials are costed." /></Panel>

      {ask === 'activate' && (
        <Ask title={`Put ${d.code} in use?`} go="Put in use" ready onClose={() => setAsk(null)} run={() => run('activate')}>
          <Text>New work orders for {d.product} use it. The revision in use before is replaced. It cannot change any more.</Text>
        </Ask>
      )}
      {ask === 'revise' && (
        <Ask title="Start a new revision?" go="Start" ready onClose={() => setAsk(null)} run={() => run('revise')}>
          <Text>A draft copy to change. This one stays in use until the new revision is put in use.</Text>
        </Ask>
      )}
      {ask === 'make' && (
        <Ask title={`Make ${d.product}`} go="Create work order" ready={!!f.warehouse_id && Number(f.qty) > 0} onClose={() => setAsk(null)}
          run={() => run('make', { warehouse_id: f.warehouse_id, qty: f.qty, due_on: f.due_on || null })}>
          <div className={s.one}>
            <Text>A draft work order with this bill of materials. Nothing moves until it is released.</Text>
            <Choose label="Warehouse" required options={d.warehouses} value={f.warehouse_id} onChange={(x) => setF({ ...f, warehouse_id: x })} />
            <TextField label="How many" required type="number" min={0.001} step="any" value={f.qty} onChange={(x) => setF({ ...f, qty: x })} />
            <TextField label="Needed by" type="date" value={f.due_on} onChange={(x) => setF({ ...f, due_on: x })} />
          </div>
        </Ask>
      )}
      {ask === 'archive' && (
        <Ask title={`Stop using ${d.code}?`} go="Stop using" ready={f.reason.trim() !== ''} onClose={() => setAsk(null)} run={() => run('archive', { reason: f.reason })}>
          <div className={s.one}>
            <Text>No new work orders can use it. Work orders already raised keep it.</Text>
            <TextField label="Why" required maxLength={255} value={f.reason} onChange={(x) => setF({ ...f, reason: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}

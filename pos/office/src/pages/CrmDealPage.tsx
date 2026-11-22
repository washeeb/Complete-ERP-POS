import { Button, Link, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowUndoRegular, DismissCircleRegular, DocumentTextRegular, SaveRegular, TrophyRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type CrmDealDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Activities } from './CrmParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * One deal (part 25), as the current form: what they want, stage, customer (or a lead, for someone not yet a customer),
 * value, expected close, branch, who works it and notes. Its follow-ups, Make or Open quotation, Won (by hand, when sold
 * another way), Lost (with the reason) and Reopen. A closed deal is shown, not changed.
 */
export function CrmDealPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<CrmDealDetail | null>(null)
  const [v, setV] = useState<CrmDealDetail['values'] | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<'win' | 'lose' | 'reopen' | null>(null)
  const [reason, setReason] = useState('')
  const load = useCallback(() => {
    api.crmDeal(id, location.search.replace(/^\?/, '')).then((x) => { setD(x); setV(x.values); onTitle(x.id ? `${x.number} · ${x.values.title}` : 'New deal') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the deal…" /></div>
  const put = <K extends keyof CrmDealDetail['values']>(k: K) => (x: CrmDealDetail['values'][K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const edit = d.actions.edit
  const back = () => (history.length > 1 ? history.back() : navigate('/office/crm/deals'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const save = () => {
    setBusy(true)
    setError('')
    const { customer_name: _c, lead_name: _l, ...body } = v
    api.saveCrmDeal(d.id, body).then((x) => { if (d.id) { setDone(x.message); load() } else navigate(`/office/crm/deals/${x.id}`, true) }).catch(fail).finally(() => setBusy(false))
  }
  const run = (action: 'quote' | 'win' | 'lose' | 'reopen', body: Record<string, unknown> = {}) => api.crmDealAction(d.id!, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    setDialog(null)
    setDone(x.message)
    load()
  })
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Deals</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>}{d.id ? d.values.title : 'New deal'}
          {d.id && <ToneBadge tone={d.tone} label={d.status === 'open' ? d.stage ?? d.status_label : d.status_label} />}</h1>
        {d.id && <div className={s.actions}>
          {d.actions.quote && <Button icon={<DocumentTextRegular />} disabled={busy} onClick={() => { setBusy(true); run('quote').catch(fail).finally(() => setBusy(false)) }}>{d.quote ? 'Open quotation' : 'Make quotation'}</Button>}
          {d.actions.win && <Button icon={<TrophyRegular />} onClick={() => setDialog('win')}>Won</Button>}
          {d.actions.lose && <Button icon={<DismissCircleRegular />} onClick={() => { setReason(''); setDialog('lose') }}>Lost</Button>}
          {d.actions.reopen && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('reopen')}>Reopen</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.lost_reason && d.status === 'lost' && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>Lost: {d.lost_reason}</MessageBarBody></MessageBar>}
      {d.quote && <Text block style={{ marginBottom: 10 }}>Quotation: <Link onClick={() => navigate(d.quote!.open)}>{d.quote.number}</Link></Text>}
      <Panel title="Deal">
        <div className={f.grid}>
          <TextField label="What they want" required className={f.spanAll} maxLength={255} placeholder="e.g. Monthly supply for a clinic" disabled={!edit} value={v.title} onChange={put('title')} />
          <Choose label="Stage" required disabled={!edit} options={d.stages} value={v.stage_id} onChange={put('stage_id')} />
          <SearchPicker label="Customer" value={v.customer_id} text={v.customer_name} search={api.crmContacts('customer')} placeholder="Type a name or phone"
            onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer_name: x?.label ?? null })} />
          <SearchPicker label="Lead (someone not yet a customer)" value={v.lead_id} text={v.lead_name} search={api.crmContacts('lead')} placeholder="Type a name"
            onChange={(x) => setV({ ...v, lead_id: x?.id ?? '', lead_name: x?.label ?? null })} />
          <TextField label="Value" type="number" min={0} step="any" prefix={d.currency} disabled={!edit} value={v.value} onChange={put('value')} />
          <TextField label="Expected to close" type="date" disabled={!edit} value={v.expected_close_on} onChange={put('expected_close_on')} />
          <Choose label="Branch" required disabled={!edit} options={d.branches} value={v.branch_id} onChange={put('branch_id')} />
          <Choose label="Who works it" disabled={!edit} options={d.people} value={v.owner_id} onChange={put('owner_id')} clearable />
          <LongText label="Notes" className={f.spanAll} maxLength={5000} value={v.notes} onChange={put('notes')} />
        </div>
      </Panel>
      {edit && (
        <div className={s.foot}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !v.title.trim() || !v.stage_id || !v.branch_id} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add deal'}</Button>
        </div>
      )}
      {d.id && <Activities on={{ deal_id: d.id }} rows={d.activities} choices={d} canManage={d.actions.win || d.actions.reopen} onChanged={(m) => { setDone(m); load() }} />}

      {dialog === 'win' && (
        <Ask title="Mark this deal won?" go="Won" ready onClose={() => setDialog(null)} run={() => run('win')}>
          <Text>Usually a deal is won by itself when its quotation is confirmed as an order. Mark it won by hand only if it was sold another way.</Text>
        </Ask>
      )}
      {dialog === 'lose' && (
        <Ask title="Close this deal as lost?" go="Lost" ready={reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('lose', { reason })}>
          <TextField label="Why" required maxLength={255} placeholder="e.g. Price, went to a competitor, no longer needed" value={reason} onChange={setReason} />
        </Ask>
      )}
      {dialog === 'reopen' && (
        <Ask title="Reopen this deal?" go="Reopen" ready onClose={() => setDialog(null)} run={() => run('reopen')}>
          <Text>It goes back into the pipeline.</Text>
        </Ask>
      )}
    </div>
  )
}

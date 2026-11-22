import { Button, Link, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, DismissCircleRegular, PeopleAddRegular, SaveRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type CrmLeadDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Activities } from './CrmParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * One lead (part 25), as the current form: who they are and how to reach them, where they came from, status, what they
 * want and may be worth, branch and who follows up, notes, and which marketing they agreed to. Its follow-ups and notes,
 * Make customer (and maybe open a deal), and Lost (with the reason). A closed lead is shown, not changed.
 */
export function CrmLeadPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<CrmLeadDetail | null>(null)
  const [v, setV] = useState<CrmLeadDetail['values'] | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<'convert' | 'lose' | null>(null)
  const [extra, setExtra] = useState('')
  const load = useCallback(() => {
    api.crmLead(id).then((x) => { setD(x); setV(x.values); onTitle(x.id ? `${x.number} · ${x.values.name}` : 'New lead') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the lead…" /></div>
  const put = <K extends keyof CrmLeadDetail['values']>(k: K) => (x: CrmLeadDetail['values'][K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const edit = d.can_edit
  const back = () => (history.length > 1 ? history.back() : navigate('/office/crm/leads'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveCrmLead(d.id, { ...v, value: v.value || null }).then((x) => { if (d.id) { setDone(x.message); load() } else navigate(`/office/crm/leads/${x.id}`, true) })
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Leads</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>}{d.id ? d.values.name : 'New lead'}
          {d.status_label && <ToneBadge tone={d.open ? 'warning' : 'subtle'} label={d.status_label} />}</h1>
        {d.id && <div className={s.actions}>
          {d.can_convert && <Button appearance="primary" icon={<PeopleAddRegular />} onClick={() => { setExtra(''); setDialog('convert') }}>Make customer</Button>}
          {d.open && edit && <Button icon={<DismissCircleRegular />} onClick={() => { setExtra(''); setDialog('lose') }}>Lost</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.customer && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>Became a customer: <Link onClick={() => navigate(d.customer!.open)}>{d.customer.name}</Link></MessageBarBody></MessageBar>}
      <Panel title="Lead">
        <div className={f.grid}>
          <TextField label="Name" required maxLength={255} disabled={!edit} value={v.name} onChange={put('name')} />
          <TextField label="Business (if any)" maxLength={255} disabled={!edit} value={v.company_name} onChange={put('company_name')} />
          <Choose label="Where they came from" required disabled={!edit} options={d.sources} value={v.source} onChange={put('source')} />
          <TextField label="Phone" maxLength={30} disabled={!edit} hint="Ghana numbers are stored as +233…" value={v.phone} onChange={put('phone')} />
          <TextField label="E-mail" maxLength={255} disabled={!edit} value={v.email} onChange={put('email')} />
          <Choose label="Status" required disabled={!edit} options={d.statuses} value={v.status} onChange={put('status')} />
          <TextField label="What they are interested in" maxLength={255} disabled={!edit} value={v.interest} onChange={put('interest')} />
          <TextField label="What it may be worth" type="number" min={0} step="any" prefix={d.currency} disabled={!edit} value={v.value} onChange={put('value')} />
          <Choose label="Branch" required disabled={!edit} options={d.branches} value={v.branch_id} onChange={put('branch_id')} />
          <Choose label="Who follows it up" disabled={!edit} options={d.people} value={v.owner_id} onChange={put('owner_id')} clearable />
          <LongText label="Notes" className={f.spanAll} maxLength={5000} value={v.notes} onChange={put('notes')} />
        </div>
      </Panel>
      <Panel title="Marketing consent" about="Only tick what they agreed to. Campaigns go only to people who agreed to that channel.">
        <div className={f.grid}>
          {(['sms', 'whatsapp', 'email'] as const).map((c) => <Toggle key={c} label={{ sms: 'SMS', whatsapp: 'WhatsApp', email: 'E-mail' }[c]} disabled={!edit} value={!!v.consents[c]}
            onChange={(x) => put('consents')({ ...v.consents, [c]: x })} />)}
        </div>
      </Panel>
      {edit && (
        <div className={s.foot}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !v.name.trim() || (!v.phone.trim() && !v.email.trim())} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add lead'}</Button>
        </div>
      )}
      {d.id && <Activities on={{ lead_id: d.id }} rows={d.activities} choices={d} canManage={edit} onChanged={(m) => { setDone(m); load() }} />}

      {dialog === 'convert' && (
        <Ask title="Make them a customer?" go="Make customer" ready onClose={() => setDialog(null)}
          run={() => api.crmLeadAction(d.id!, 'convert', { deal: extra || null }).then((x) => navigate(x.open ?? '/office/crm/leads'))}>
          <div className={s.one}>
            <Text>They become a customer (or are linked to the customer with the same phone number), with their consents and follow-ups.</Text>
            <TextField label="Also open a deal for (optional)" maxLength={255} placeholder="e.g. Monthly supply of baby milk" value={extra} onChange={setExtra} />
          </div>
        </Ask>
      )}
      {dialog === 'lose' && (
        <Ask title="Close this lead as lost?" go="Lost" ready={extra.trim() !== ''} onClose={() => setDialog(null)}
          run={() => api.crmLeadAction(d.id!, 'lose', { reason: extra }).then((x) => { setDialog(null); setDone(x.message); load() })}>
          <TextField label="Why" required maxLength={255} placeholder="e.g. Too far, bought elsewhere, only asking" value={extra} onChange={setExtra} />
        </Ask>
      )}
    </div>
  )
}

import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type TicketForm, type TicketValues } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

/**
 * Service desk → New ticket (part 28), as the current form: what kind, priority, how it came, in a few words and what
 * happened; who it is for (a customer, or a name and number to answer them; a receipt for a return or warranty); the
 * equipment, branch and who handles it. Someone who may only ask for help gets "Ask for help": a request for themselves.
 */
export function TicketNewPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<TicketForm | null>(null)
  const [v, setV] = useState<TicketValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { api.ticketForm().then((x) => { setD(x); setV(x.values) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the form…" /></div>
  const put = <K extends keyof TicketValues>(k: K) => (x: TicketValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const internal = v.kind === 'internal'
  const back = () => (history.length > 1 ? history.back() : navigate('/office/tickets'))
  const reach = internal || !!v.customer_id || v.contact_phone.trim() !== '' || v.contact_email.trim() !== ''
  const save = () => {
    setBusy(true)
    setError('')
    const { customer_name: _c, ...body } = v
    api.openTicket(body).then((x) => navigate(`/office/tickets/${x.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{d.staff_only ? 'My requests' : 'Tickets'}</Button>
      <h1 className={s.title} style={{ marginBottom: 8 }}>{d.staff_only ? 'Ask for help' : 'New ticket'}</h1>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="Ticket">
        <div className={f.grid}>
          <Choose label="What kind" required options={d.kinds} value={v.kind} onChange={put('kind')} />
          <Choose label="Priority" required options={d.priorities} value={v.priority} onChange={put('priority')} />
          {!internal && <Choose label="Came by" required options={d.sources} value={v.source} onChange={put('source')} />}
          <TextField label="In a few words" required className={f.spanAll} maxLength={255} value={v.subject} onChange={put('subject')} />
          <LongText label="What happened" className={f.spanAll} maxLength={10000} value={v.description} onChange={put('description')} />
        </div>
      </Panel>
      {!internal && (
        <Panel title="Who it is for" about="Choose the customer, or give their name and a phone number or e-mail address to answer them.">
          <div className={f.grid}>
            <SearchPicker label="Customer" value={v.customer_id} text={v.customer_name} search={api.ticketCustomers} placeholder="Type a name or phone"
              onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer_name: x?.label ?? null })} />
            <TextField label="Name" maxLength={120} value={v.contact_name} onChange={put('contact_name')} />
            <TextField label="Phone" maxLength={30} value={v.contact_phone} onChange={put('contact_phone')} />
            <TextField label="E-mail" maxLength={255} value={v.contact_email} onChange={put('contact_email')} />
            <TextField label="Receipt number" maxLength={40} hint="For a return or warranty: the warranty is checked on the sale." value={v.receipt_no} onChange={put('receipt_no')} />
          </div>
        </Panel>
      )}
      <Panel title="Where and who">
        <div className={f.grid}>
          <Choose label="Equipment it is about" options={d.assets} value={v.asset_id} onChange={put('asset_id')} clearable />
          <Choose label="Branch" options={d.branches} value={v.branch_id} onChange={put('branch_id')} clearable />
          {!d.staff_only && <Choose label="Give it to" options={d.people} value={v.assigned_to} onChange={put('assigned_to')} clearable />}
        </div>
        {!reach && <Text size={200} block className={s.muted}>Add the customer, or a phone number or e-mail address, so you can answer them.</Text>}
      </Panel>
      <SaveBar value={v}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || v.subject.trim() === '' || !reach} onClick={save}>{busy ? 'Opening…' : d.staff_only ? 'Ask for help' : 'Open ticket'}</Button>
      </SaveBar>
    </div>
  )
}

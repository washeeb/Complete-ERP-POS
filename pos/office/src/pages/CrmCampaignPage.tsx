import { Button, Field, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Text } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CrmCampaignForm } from '../api'
import { Choose, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'

/**
 * A campaign (part 25), as the current form: the message (name, industry, SMS / WhatsApp / e-mail, subject or template,
 * the text with {customer} and {company}), who gets it (tags, home branch, spent, bought lately, not back for, leads)
 * with how many it reaches, and when. Saved as a draft; it is sent from the list after a last look.
 */
export function CrmCampaignPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<CrmCampaignForm | null>(null)
  const [v, setV] = useState<CrmCampaignForm['values'] | null>(null)
  const [reach, setReach] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.crmCampaign(id).then((x) => { setD(x); setV(x.values); onTitle(x.id ? `${x.number} · ${x.values.name}` : 'New campaign') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  const channel = v?.channel
  const audience = JSON.stringify(v?.audience ?? {})
  useEffect(() => {
    if (!v) return
    const t = setTimeout(() => { api.crmReach({ channel: v.channel, audience: v.audience }).then((x) => setReach(x.reach)).catch(() => undefined) }, 400)
    return () => clearTimeout(t)
  }, [channel, audience]) // eslint-disable-line react-hooks/exhaustive-deps

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the campaign…" /></div>
  const put = <K extends keyof CrmCampaignForm['values']>(k: K) => (x: CrmCampaignForm['values'][K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const aud = <K extends keyof CrmCampaignForm['values']['audience']>(k: K) => (x: CrmCampaignForm['values']['audience'][K]) => setV((o) => (o ? { ...o, audience: { ...o.audience, [k]: x } } : o))
  const back = () => navigate('/office/crm/campaigns')
  const ready = v.name.trim() !== '' && v.body.trim() !== '' && (v.channel !== 'email' || v.subject.trim() !== '') && (v.channel !== 'whatsapp' || v.whatsapp_template.trim() !== '')
    && (v.channel !== 'sms' || v.body.length <= 480)
  const save = () => {
    setBusy(true)
    setError('')
    api.saveCrmCampaign(d.id, { ...v, industry_id: d.industries ? v.industry_id || null : null, send_at: v.send_at || null }).then(() => navigate('/office/crm/campaigns', true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Campaigns</Button>
      <h1 className={s.title} style={{ marginBottom: 4 }}>{d.id ? `Change ${d.number}` : 'New campaign'}</h1>
      <Text className={s.muted}>Saved as a draft: send it from the list after a last look.</Text>
      {error && <MessageBar intent="error" style={{ margin: '12px 0' }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <div style={{ height: 12 }} />
      <Panel title="Message">
        <div className={f.grid}>
          <TextField label="Campaign name (for you)" required maxLength={255} placeholder="e.g. December offers" value={v.name} onChange={put('name')} />
          {d.industries && <Choose label="Industry" options={d.industries} value={v.industry_id} onChange={put('industry_id')} hint="Only people in this industry see it. Shared ones are seen by every industry." />}
          <Field label="Send by" required className={f.spanAll}>
            <RadioGroup layout="horizontal" value={v.channel} onChange={(_, e) => put('channel')(e.value)}>{d.channels.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}</RadioGroup>
          </Field>
          {v.channel === 'email' && <TextField label="E-mail subject" required maxLength={255} value={v.subject} onChange={put('subject')} />}
          {v.channel === 'whatsapp' && <TextField label="WhatsApp template name" required maxLength={80} value={v.whatsapp_template} onChange={put('whatsapp_template')}
            hint="WhatsApp only delivers business messages that use a template approved by Meta. Type its name; the text below must match it." />}
          <LongText label="Message" className={f.spanAll} maxLength={v.channel === 'sms' ? 480 : 10000} value={v.body} onChange={put('body')}
            hint={`{customer} becomes the person's name, {company} your company's.${v.channel === 'sms' ? ` ${v.body.length} of 480 characters.` : ''}${v.channel === 'email' ? ' A link to stop these e-mails is added at the end.' : ''}`} />
        </div>
      </Panel>
      <Panel title="Who gets it" about="Only people who agreed to this channel and have a phone number or e-mail address get it; the count shows how many.">
        <div className={f.grid}>
          <Tags label="Customers with any of these tags" placeholder="e.g. VIP, wholesale" value={v.audience.tags} onChange={aud('tags')} hint={d.tags.length ? `In use: ${d.tags.slice(0, 12).join(', ')}` : undefined} />
          <Choose label="Home branch" options={d.branches} value={v.audience.branch_id} onChange={aud('branch_id')} placeholder="All branches" clearable />
          <TextField label="Spent at least (last 12 months)" type="number" min={0} step="any" prefix={d.currency} value={v.audience.min_spent} onChange={aud('min_spent')} />
          <TextField label="Bought in the last … days" type="number" min={1} value={v.audience.bought_within_days} onChange={aud('bought_within_days')} />
          <TextField label="Not back for … days (win back)" type="number" min={1} value={v.audience.not_bought_days} onChange={aud('not_bought_days')} />
          <Toggle label="Also open leads who agreed" value={v.audience.include_leads} onChange={aud('include_leads')} />
        </div>
        {reach && <Text block weight="semibold" style={{ marginTop: 10 }}>{reach}</Text>}
      </Panel>
      <Panel title="When">
        <TextField label="Send at (leave empty to send when you press “Send”)" type="datetime-local" value={v.send_at} onChange={put('send_at')} hint="Mind the time: messages at night annoy people." />
      </Panel>
      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
      </div>
    </div>
  )
}

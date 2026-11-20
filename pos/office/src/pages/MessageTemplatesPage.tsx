import { Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text, Textarea, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowUndoRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type TemplateRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]

/** Message templates (settings and admin): the wording of every SMS, WhatsApp message and e-mail, and whether it is sent at all. Open one to change it. */
export function MessageTemplatesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [open, setOpen] = useState<TemplateRow | null>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.messageTemplates, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const channel = str(params, 'channel')
  const show = str(params, 'show')
  const channels = [['', 'Any channel'], ...(data?.channels ?? []).map((c) => [c.id, c.label])]
  const columns: Column<TemplateRow>[] = [
    { key: 'name', label: 'Message', render: (r) => <Text weight="semibold">{r.name}</Text> },
    { key: 'channel', label: 'Channel', render: (r) => <Badge appearance="tint">{r.channel_label}</Badge> },
    { key: 'body', label: 'Text', render: (r) => <Text>{r.body.length > 90 ? `${r.body.slice(0, 90)}…` : r.body}</Text> },
    { key: 'on', label: 'On', render: (r) => (r.archived ? <Badge appearance="tint" color="subtle">Archived</Badge> : <Badge appearance="tint" color={r.active ? 'success' : 'subtle'}>{r.active ? 'On' : 'Off'}</Badge>) },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Message templates" hint="The wording of every SMS, WhatsApp message and e-mail, and whether it is sent at all." classic="/admin/message-templates" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Message or text" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search templates" />
        <Dropdown aria-label="Channel" style={{ minWidth: 140 }} value={channels.find((x) => x[0] === channel)?.[1] ?? ''} selectedOptions={[channel]} onOptionSelect={(_, d) => set({ channel: d.optionValue ?? '' })}>
          {channels.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Message templates" columns={columns} rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} empty="No template matches."
        onSort={() => undefined} onOpen={(r) => { setDone(''); setOpen(r) }} />
      {open && data?.can_edit && <TemplateDialog t={open} onClose={() => setOpen(null)} onDone={(r) => { setOpen(null); setDone(r.message); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function TemplateDialog({ t, onClose, onDone }: { t: TemplateRow; onClose: () => void; onDone: (r: UserDone) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ active: t.active, subject: t.subject ?? '', body: t.body, whatsapp_template: t.whatsapp_template ?? '', whatsapp_language: t.whatsapp_language ?? '' })
  const [archiving, setArchiving] = useState(false)
  const [why, setWhy] = useState('')
  if (archiving) {
    return (
      <Ask title={`Archive ${t.name} (${t.channel_label})?`} go="Archive" ready={why.trim().length >= 3} onClose={() => setArchiving(false)} run={async () => onDone(await api.templateArchive(t.id, 'archive', why))}>
        <Text block>It is no longer sent, and leaves this list. You can restore it at any time.</Text>
        <div style={{ marginTop: 10 }}><TextField label="Why" required value={why} maxLength={255} onChange={setWhy} /></div>
      </Ask>
    )
  }
  return (
    <Ask title={`${t.name} (${t.channel_label})`} go="Save" ready={v.body.trim() !== '' && (t.channel !== 'email' || v.subject.trim() !== '')} onClose={onClose} width={640}
      run={async () => onDone(await api.saveTemplate(t.id, { ...v, subject: v.subject || null, whatsapp_template: v.whatsapp_template || null, whatsapp_language: v.whatsapp_language || null }))}>
      <div className={s.form}>
        <Text size={200} className={s.muted}>Placeholders you can use: {t.placeholders ?? '—'}</Text>
        <Switch label="Send this message" checked={v.active} onChange={(_, d) => setV({ ...v, active: d.checked })} />
        {t.channel === 'email' && <Field label="Subject" required><Input value={v.subject} maxLength={255} onChange={(_, d) => setV({ ...v, subject: d.value })} /></Field>}
        <Field label="Text" required hint={t.channel === 'sms' ? `${v.body.length} of 480 characters (3 SMS)` : undefined}><Textarea rows={6} value={v.body} onChange={(_, d) => setV({ ...v, body: d.value })} /></Field>
        {t.channel === 'whatsapp' && <>
          <Field label="Approved WhatsApp template name" hint="WhatsApp only delivers business-started messages from templates approved by Meta. Create a template with the same wording there; the placeholders become its {{1}}, {{2}}… in order.">
            <Input value={v.whatsapp_template} maxLength={80} onChange={(_, d) => setV({ ...v, whatsapp_template: d.value })} /></Field>
          <Field label="Template language code"><Input value={v.whatsapp_language} maxLength={10} onChange={(_, d) => setV({ ...v, whatsapp_language: d.value })} /></Field>
        </>}
        <div>{t.archived
          ? <Button icon={<ArrowUndoRegular />} onClick={() => void api.templateArchive(t.id, 'restore').then(onDone)}>Restore</Button>
          : <Button appearance="subtle" icon={<ArchiveRegular />} onClick={() => setArchiving(true)}>Archive</Button>}</div>
      </div>
    </Ask>
  )
}

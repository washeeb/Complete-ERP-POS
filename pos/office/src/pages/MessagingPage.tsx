import { Button, Field, Input, makeStyles, MessageBar, MessageBarBody, Spinner, Switch, Text, tokens } from '@fluentui/react-components'
import { SaveRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CredField, type MessagingSetupData, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4,
    padding: '14px 16px', marginBottom: '12px' },
  two: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))', gap: '10px 16px', marginTop: '10px', alignItems: 'start' },
  acts: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '12px' },
  muted: { color: tokens.colorNeutralForeground3 },
  code: { fontFamily: tokens.fontFamilyMonospace, wordBreak: 'break-all' },
})

type Channel = 'sms' | 'whatsapp' | 'email'

/**
 * Messaging (settings and admin): how SMS, WhatsApp and e-mail leave this server, through the client's own provider
 * accounts. Saved credentials are never shown again: an empty box keeps the saved one. Send a test to check.
 */
export function MessagingPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [d, setD] = useState<MessagingSetupData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<{ ok: boolean; text: string } | null>(null)
  const [nonce, setNonce] = useState(0)
  const [sms, setSms] = useState({ provider: 'hubtel', sender_id: '', active: false, credentials: {} as Record<string, string> })
  const [wa, setWa] = useState({ active: false, credentials: {} as Record<string, string> })
  const [testing, setTesting] = useState<Channel | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const { guard, dialog } = useReconfirm('Changing how messages are sent is protected.')
  useEffect(() => {
    api.messagingSetup().then((x) => {
      setD(x); setError('')
      setSms({ provider: x.sms.provider, sender_id: x.sms.sender_id ?? '', active: x.sms.active, credentials: {} })
      setWa({ active: x.whatsapp.active, credentials: {} })
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [nonce])

  if (!d) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the messaging set-up…" />}</div>
  const save = (channel: 'sms' | 'whatsapp') => guard(async () => {
    setBusy(channel); setDone(null); setError('')
    try {
      const r: UserDone = await api.saveProvider(channel, channel === 'sms' ? sms : wa)
      setDone({ ok: true, text: r.message }); setNonce((n) => n + 1)
    } catch (e) { setError(e instanceof Error ? e.message : String(e)) } finally { setBusy(null) }
  })
  const cred = (f: CredField, value: string, set: (v: string) => void) => (
    <Field key={f.key} label={f.label} hint={f.saved ? 'Saved — leave empty to keep' : undefined}>
      <Input type={f.secret ? 'password' : 'text'} autoComplete={f.secret ? 'new-password' : 'off'} value={value} placeholder={f.saved ? '••••••••' : undefined} onChange={(_, x) => set(x.value)} />
    </Field>
  )

  return (
    <div className={page.page}>
      <PageHead title="Messaging: SMS, WhatsApp and e-mail" hint="Messages go through your own provider accounts. Saved passwords and keys are never shown again: leave a box empty to keep what is saved." />
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent={done.ok ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{done.text}</MessageBarBody></MessageBar>}

      <section className={s.panel} aria-label="SMS">
        <Text weight="semibold" size={400} block>SMS</Text>
        <Text size={200} className={s.muted} block>Choose the SMS company you have an account with. Receipts, booking and appointment reminders and payment notes go through it. Without one, messages are listed in the message log as "not sent".</Text>
        <div className={s.two}>
          <Choose label="SMS provider" required options={d.sms.providers} value={sms.provider} onChange={(v) => setSms({ ...sms, provider: v, credentials: {} })} />
          <TextField label="Sender ID" hint="The name customers see (3–11 letters or digits), registered with your provider. Not needed for a webhook gateway." value={sms.sender_id} maxLength={11}
            onChange={(v) => setSms({ ...sms, sender_id: v })} />
          {(d.sms.fields[sms.provider] ?? []).map((f) => cred(f, sms.credentials[f.key] ?? '', (v) => setSms({ ...sms, credentials: { ...sms.credentials, [f.key]: v } })))}
        </div>
        <Switch style={{ marginTop: 10 }} label="Send SMS through this provider" checked={sms.active} onChange={(_, x) => setSms({ ...sms, active: x.checked })} />
        <Text block size={200} className={s.muted}>{d.sms.tested ?? 'Not tested yet.'}</Text>
        <div className={s.acts}>
          <Button appearance="primary" icon={busy === 'sms' ? <Spinner size="tiny" /> : <SaveRegular />} disabled={!!busy} onClick={() => void save('sms')()}>Save SMS settings</Button>
          <Button icon={<SendRegular />} onClick={() => { setDone(null); setTesting('sms') }}>Send a test SMS</Button>
        </div>
      </section>

      <section className={s.panel} aria-label="WhatsApp Business">
        <Text weight="semibold" size={400} block>WhatsApp Business</Text>
        <Text size={200} className={s.muted} block>Needs a WhatsApp Business account on Meta's Cloud API, with message templates approved by Meta (their names go in the message templates). Meta charges per conversation. Off until you have the account.</Text>
        <div className={s.two}>{d.whatsapp.fields.map((f) => cred(f, wa.credentials[f.key] ?? '', (v) => setWa({ ...wa, credentials: { ...wa.credentials, [f.key]: v } })))}</div>
        <Switch style={{ marginTop: 10 }} label="Send WhatsApp messages (preferred over SMS when a template is on)" checked={wa.active} onChange={(_, x) => setWa({ ...wa, active: x.checked })} />
        <Text block size={200} style={{ marginTop: 6 }}>{d.whatsapp.webhook
          ? <>To receive orders: at Meta (WhatsApp → Configuration → Webhook) enter the callback URL <span className={s.code}>{d.whatsapp.webhook.url}</span> and the verify token <span className={s.code}>{d.whatsapp.webhook.verify_token}</span>, then subscribe to "messages". Turn on Settings → WhatsApp orders.</>
          : 'Save these settings to get the address Meta sends incoming messages to.'}</Text>
        {d.whatsapp.tested && <Text block size={200} className={s.muted}>{d.whatsapp.tested}</Text>}
        <div className={s.acts}>
          <Button appearance="primary" icon={busy === 'whatsapp' ? <Spinner size="tiny" /> : <SaveRegular />} disabled={!!busy} onClick={() => void save('whatsapp')()}>Save WhatsApp settings</Button>
          <Button icon={<SendRegular />} onClick={() => { setDone(null); setTesting('whatsapp') }}>Send Meta's "hello_world" test</Button>
        </div>
      </section>

      <section className={s.panel} aria-label="E-mail">
        <Text weight="semibold" size={400} block>E-mail</Text>
        <Text size={200} className={s.muted} block>E-mail goes through the mail server set in the server's .env file (any SMTP server: Gmail / Google Workspace, Zoho, your web host). See the installation guide, Chapter 16.</Text>
        <Text block style={{ marginTop: 6 }}>Mail driver: {d.email.driver} · From: {d.email.from}{d.email.log_only ? ' — e-mails are only written to the log file, not sent. Set MAIL_MAILER=smtp to send.' : ''}</Text>
        <div className={s.acts}><Button icon={<SendRegular />} onClick={() => { setDone(null); setTesting('email') }}>Send a test e-mail</Button></div>
      </section>
      {testing && <TestDialog channel={testing} onClose={() => setTesting(null)} onDone={(r) => { setTesting(null); setDone({ ok: r.sent, text: r.message }); setNonce((n) => n + 1) }} />}
      {dialog}
    </div>
  )
}

function TestDialog({ channel, onClose, onDone }: { channel: Channel; onClose: () => void; onDone: (r: UserDone & { sent: boolean }) => void }) {
  const [to, setTo] = useState('')
  const label = channel === 'email' ? 'E-mail address' : channel === 'whatsapp' ? 'WhatsApp number' : 'Phone number'
  return (
    <Ask title={channel === 'email' ? 'Send a test e-mail' : channel === 'whatsapp' ? 'Send the WhatsApp test' : 'Send a test SMS'} go="Send" ready={to.trim().length >= 5} onClose={onClose}
      run={async () => onDone(await api.testMessage(channel, to))}>
      <TextField label={label} required value={to} maxLength={255} onChange={setTo} />
    </Ask>
  )
}

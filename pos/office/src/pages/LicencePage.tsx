import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, Textarea } from '@fluentui/react-components'
import { ArrowSyncRegular, KeyRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type LicenceData } from '../api'
import { Ask } from '../form/Ask'
import { LongText } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * System → Licence (part 22): the licence's state, who it is for and until when, what is in use against what is allowed,
 * what it covers, and when it last checked in. The owner or general manager sees the installation code (text and QR)
 * to send for a key, enters the key and checks in; everyone else sees why the system is locked.
 */
export function LicencePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<LicenceData | null>(null)
  const [error, setError] = useState('')
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [asking, setAsking] = useState(false)
  const [key, setKey] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { api.licence().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the licence…" /></div>
  const checkin = () => {
    setBusy(true)
    setNote(null)
    api.licenceAction('checkin').then((x) => { setD(x.licence); setNote({ good: x.good, text: x.message }) })
      .catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) })).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <PageHead title="Licence" hint="What this installation is licensed for, and how to renew it." />
      <div className={s.bar}>
        <span className={s.grow} />
        {d.can_checkin && <Button icon={<ArrowSyncRegular />} disabled={busy} onClick={checkin}>{busy ? 'Checking in…' : 'Check in now'}</Button>}
        {d.can_manage && <Button appearance="primary" icon={<KeyRegular />} onClick={() => { setKey(''); setNote(null); setAsking(true) }}>Enter licence key</Button>}
      </div>
      {note && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <Panel title="Licence">
        <div className={s.head} style={{ justifyContent: 'flex-start' }}>
          <ToneBadge tone={d.tone} label={d.state_label} />
          {d.customer && <Text weight="semibold">{d.customer}</Text>}
          {d.short && <Text className={s.muted}>Licence {d.short}</Text>}
        </div>
        {d.message && <Text block style={{ marginBottom: 8 }}>{d.message}</Text>}
        {d.expires_on && <Text block style={{ marginBottom: 8 }}>Valid until <b>{d.expires_on}</b>{d.days_left !== null && d.days_left >= 0 ? ` (${d.days_left} days left)` : ''}</Text>}
        {d.usage.length > 0 && (
          <Table size="small" aria-label="Licence use" style={{ maxWidth: 720 }}>
            <TableHeader><TableRow><TableHeaderCell /><TableHeaderCell>In use</TableHeaderCell><TableHeaderCell>Allowed</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>
              {d.usage.map((u) => <TableRow key={u.label}><TableCell>{u.label}</TableCell><TableCell>{u.used}</TableCell><TableCell>{u.max ?? 'Unlimited'}</TableCell>
                <TableCell>{u.full && <Text weight="semibold">Limit reached</Text>}</TableCell></TableRow>)}
              {d.covers.map((c) => <TableRow key={c.label}><TableCell>{c.label}</TableCell><TableCell colSpan={3}>{c.value}</TableCell></TableRow>)}
            </TableBody>
          </Table>
        )}
        {d.state !== 'unlicensed' && <Text block size={200} className={s.muted} style={{ marginTop: 8 }}>Last confirmed with the licence server: {d.last_checkin ?? 'never'}</Text>}
      </Panel>
      {d.can_manage && d.code && (
        <Panel title="Installation code">
          <Text block style={{ marginBottom: 6 }}>To activate or renew, send this code to {d.vendor}. You will receive a licence key; enter it with “Enter licence key”.</Text>
          {(d.phone || d.email || d.website) && <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>
            {d.phone && <>Hotline {d.phone}</>}
            {d.email && <> · <Link href={`mailto:${d.email}?subject=${encodeURIComponent(`${d.product ?? ''} licence`)}`}>{d.email}</Link></>}
            {d.website && <> · <Link href={d.website} target="_blank" rel="noopener">{d.website.replace(/^https?:\/\//, '')}</Link></>}
          </Text>}
          <Textarea readOnly value={d.code} rows={3} style={{ width: '100%', fontFamily: 'monospace' }} aria-label="Installation code" onFocus={(e) => e.target.select()} />
          {d.qr && <img src={d.qr} alt="QR code of the installation code" width={220} height={220} style={{ marginTop: 10, background: '#fff', borderRadius: 8 }} />}
        </Panel>
      )}
      {!d.can_manage && d.read_only && <Panel title="Renewal"><Text>Ask the owner or general manager to renew the licence.</Text></Panel>}

      {asking && (
        <Ask title="Enter licence key" go="Activate" ready={key.trim() !== ''} width={600} onClose={() => setAsking(false)}
          run={() => api.licenceAction('activate', key).then((x) => { setD(x.licence); setAsking(false); setNote({ good: true, text: x.message }) })}>
          <LongText label="Licence key (starts with SYN1-)" value={key} onChange={setKey} />
        </Ask>
      )}
    </div>
  )
}

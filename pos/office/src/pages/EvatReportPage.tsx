import { Badge, Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EvatDetail } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { EVAT_TONE } from './EvatPage'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px 16px', marginBottom: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 16px', marginBottom: '12px', minWidth: 0 },
  code: { margin: '8px 0 0', whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontFamily: tokens.fontFamilyMonospace, fontSize: tokens.fontSizeBase200, maxHeight: '420px', overflow: 'auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/** One GRA E-VAT report (release 8): its status and GRA's reference, what was sent and what GRA answered, and Send again when it was not accepted. */
export function EvatReportPage({ id }: { id: string }) {
  const page = usePageStyles()
  const s = useStyles()
  const [r, setR] = useState<EvatDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<{ ok: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { api.graEvatReport(id).then((d) => { setR(d.report); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id])

  if (!r) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the report…" />}</div>
  const fact = (label: string, value: React.ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text>{value}</Text></div>
  const send = async () => {
    setBusy(true); setDone(null); setError('')
    try {
      const x = await api.graEvatSend(r.id)
      setR(x.report); setDone({ ok: x.report.status === 'submitted', text: x.message })
    } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
    setBusy(false)
  }

  return (
    <div className={page.page}>
      <PageHead title={`${r.type_label} ${r.number}`} hint="What was reported to GRA for this document, and GRA's answer." classic="/admin/gra-evat" />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/gra-evat')}>GRA E-VAT</Button>
        <span className={s.grow} />
        {r.can_retry && <Button appearance="primary" icon={busy ? <Spinner size="tiny" /> : <SendRegular />} disabled={busy} onClick={send}>{busy ? 'Sending…' : 'Send again'}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent={done.ok ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{done.text}</MessageBarBody></MessageBar>}
      <div className={s.facts}>
        {fact('Status', <Badge appearance="tint" color={EVAT_TONE[r.status] ?? 'subtle'}>{r.status_label}</Badge>)}
        {fact('Date', day(r.date))}
        {fact('GRA reference', r.reference ?? '—')}
        {fact('Tries', String(r.attempts))}
        {r.next_attempt && fact('Next try by itself', new Date(r.next_attempt).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))}
        {r.batch && fact('Batch', r.batch)}
      </div>
      {r.error && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{r.error}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Sent to GRA">
        <Text weight="semibold" size={400} block>Sent to GRA</Text>
        {r.sent ? <pre className={s.code}>{r.sent}</pre> : <Text className={s.muted}>Nothing sent yet.</Text>}
      </section>
      <section className={s.panel} aria-label="GRA answered">
        <Text weight="semibold" size={400} block>GRA answered</Text>
        {r.answer ? <pre className={s.code}>{r.answer}</pre> : <Text className={s.muted}>No answer yet.</Text>}
      </section>
    </div>
  )
}

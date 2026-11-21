import {
  Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Text, tokens,
} from '@fluentui/react-components'
import { AlertOnRegular, CheckmarkRegular, DismissRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, ApiError, type TillRequestRow } from '../api'
import { Ask } from '../form/Ask'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { enablePush, pushState } from '../home/push'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px 12px', flexWrap: 'wrap', marginBottom: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
  push: { display: 'flex', gap: '12px', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' },
  acts: { display: 'flex', gap: '6px', justifyContent: 'flex-end', flexWrap: 'wrap' },
})

const SHOW = [['pending', 'Waiting'], ['answered', 'Answered'], ['all', 'All']]
const TONE: Record<string, 'warning' | 'success' | 'danger' | 'subtle'> = { pending: 'warning', approved: 'success', used: 'success', declined: 'danger' }

/**
 * Till requests (part 16): what tills ask a manager for — a return, a discount, removing items, opening the drawer.
 * Check what is asked, then Approve (confirming it is you) or Decline with why; the till goes on within seconds. Kept
 * fresh every few seconds. Turn on notifications to get them on this phone even with the back office closed. Opened
 * from a notification, it shows that one request.
 */
export function TillRequestsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.tillRequests, serialize(params), nonce)
  const [declining, setDeclining] = useState<TillRequestRow | null>(null)
  const [approving, setApproving] = useState<TillRequestRow | null>(null)
  const [note, setNote] = useState('')
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const [done, setDone] = useState('')
  const [push, setPush] = useState(pushState())
  const [pushBusy, setPushBusy] = useState(false)
  useEffect(() => { const t = setInterval(() => setNonce((n) => n + 1), 5000); return () => clearInterval(t) }, []) // a cashier is waiting: keep it fresh

  const decide = async (r: TillRequestRow, yes: boolean, why?: string) => {
    try {
      await api.decideTill(r.id, yes, why)
      setDone(yes ? `Approved: ${r.what}. The till goes on.` : `Declined: ${r.what}.`)
      setApproving(null)
      setDeclining(null)
      setNonce((n) => n + 1)
    } catch (e) {
      if (yes && e instanceof ApiError && e.code === 'reconfirm' && e.url) { setConfirmUrl(e.url); return }
      throw e
    }
  }
  const columns: Column<TillRequestRow>[] = [
    { key: 'asked', label: 'Asked', render: (r) => <>{when(r.asked)}{r.expires_at && <Text block size={200} className={s.muted}>Answer by {new Date(r.expires_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</Text>}</> },
    { key: 'till', label: 'Till', render: (r) => <>{r.till ?? '—'}<Text block size={200} className={s.muted}>{r.branch}</Text></> },
    { key: 'cashier', label: 'Cashier', render: (r) => r.cashier },
    { key: 'what', label: 'What', render: (r) => <><Text weight="semibold">{r.what}</Text><Text block size={200}>{r.summary}{r.reason ? ` · Reason: ${r.reason}` : ''}</Text></> },
    { key: 'items', label: 'Items', render: (r) => r.items ?? '—' },
    { key: 'amount', label: 'Amount', end: true, render: (r) => r.amount ?? '—' },
    { key: 'status', label: 'Answer', render: (r) => <><Badge appearance="tint" color={TONE[r.status] ?? 'subtle'}>{r.status_label}</Badge>{r.answered_by && <Text block size={200} className={s.muted}>{r.answered_by}</Text>}</> },
    { key: 'acts', label: '', render: (r) => (r.can_decide ? (
      <div className={s.acts}>
        <Button size="small" appearance="primary" icon={<CheckmarkRegular />} onClick={(e) => { e.stopPropagation(); setDone(''); setApproving(r) }}>Approve</Button>
        <Button size="small" icon={<DismissRegular />} onClick={(e) => { e.stopPropagation(); setDone(''); setNote(''); setDeclining(r) }}>Decline</Button>
      </div>
    ) : null) },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Till requests" hint="A till asks for your yes: check what is asked, then Approve or Decline. You need not be at the till, and your PIN is never typed where others can see it." classic="/admin/till-requests" />
      {data && push !== 'unsupported' && !(data.push.has_device && push === 'granted') && (
        <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>
          <div className={s.push}>
            <span><Text weight="semibold">Get till requests on this phone.</Text> A notification pops up even when the back office is closed; press it to approve. Do this once on each phone or computer you use.
              {push === 'denied' && <Text block style={{ color: 'var(--zx-bad)' }}>Notifications are blocked for this site: allow them in the browser's site settings, then press the button again.</Text>}
              <Text block size={200} className={s.muted}>iPhone: first add this site to the home screen (Share → Add to Home Screen) and open it from there.</Text></span>
            <Button icon={<AlertOnRegular />} disabled={pushBusy} onClick={() => {
              setPushBusy(true)
              enablePush(data.push.key).then((r) => { setPush(r); if (r === 'granted') { setDone('Notifications are on for this device.'); setNonce((n) => n + 1) } })
                .catch((e: unknown) => setDone(e instanceof Error ? e.message : String(e))).finally(() => setPushBusy(false))
            }}>Turn on notifications</Button>
          </div>
        </MessageBarBody></MessageBar>
      )}
      <div className={s.bar}>
        <Field label="Show" size="small">
          <Dropdown size="small" style={{ minWidth: 150 }} value={SHOW.find((x) => x[0] === (data?.show ?? str(params, 'show')))?.[1] ?? ''} selectedOptions={[data?.show ?? '']}
            onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
            {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
          </Dropdown>
        </Field>
        {data?.one && <Button onClick={() => set({ id: '', show: '' })}>Show all requests</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Till requests" columns={columns} rows={data?.rows ?? []} sort="asked" dir="desc" loading={loading && !data}
        empty="Nothing waiting. When a till asks for your yes, it shows here, on the bell and on your phone." onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {approving && (
        <Ask title={`Approve: ${approving.what}`} go="Approve" ready onClose={() => setApproving(null)} run={() => decide(approving, true)}>
          <Text>{approving.cashier} asks: {approving.summary}{approving.reason ? ` Reason: ${approving.reason}.` : ''} The till goes on as soon as you approve.</Text>
        </Ask>
      )}
      {declining && (
        <Ask title={`Decline: ${declining.what}`} go="Decline" ready={note.trim() !== ''} onClose={() => setDeclining(null)} run={() => decide(declining, false, note)}>
          <Field label="Why (the cashier sees it)" required><Input maxLength={255} value={note} onChange={(_, d) => setNote(d.value)} /></Field>
        </Ask>
      )}
      {confirmUrl && approving && (
        <ConfirmItsYou url={confirmUrl} why="Approving at the till is protected." go="Approve now"
          onCancel={() => { setConfirmUrl(null); setApproving(null) }}
          onGo={() => { const r = approving; setConfirmUrl(null); void decide(r, true).catch((e: unknown) => setDone(e instanceof Error ? e.message : String(e))) }} />
      )}
    </div>
  )
}

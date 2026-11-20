import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkRegular, DismissRegular, ArrowUndoRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type AccessRequestRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '4px', flexWrap: 'wrap' },
})

const TONE: Record<string, 'warning' | 'success' | 'danger' | 'subtle'> = { pending: 'warning', approved: 'success', rejected: 'danger', failed: 'danger' }
const when = (d: string | null) => (d ? new Date(d).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
type Doing = { step: 'approve' | 'reject' | 'withdraw'; row: AccessRequestRow } | null

/**
 * Access requests (settings and admin): what IT Admin asked for (a new user, access, a role, a till PIN or a two-step
 * reset). It happens only when the owner or SyncLytics support approves; it lapses after 72 hours. The person who asked
 * can withdraw one still waiting.
 */
export function AccessRequestsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [done, setDone] = useState<(UserDone & { failed?: boolean }) | null>(null)
  const [doing, setDoing] = useState<Doing>(null)
  const { data, loading, error } = useList(api.accessRequests, serialize(params), nonce)
  const status = str(params, 'status')
  const statuses = [['', 'Any status'], ...Object.entries(data?.statuses ?? {})]
  const columns: Column<AccessRequestRow>[] = [
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={TONE[r.status] ?? 'subtle'}>{r.status_label}</Badge> },
    { key: 'kind', label: 'What', render: (r) => r.kind },
    { key: 'summary', label: 'Details', render: (r) => <Text>{r.summary}</Text> },
    { key: 'by', label: 'Asked by', render: (r) => <>{r.by ?? '—'}<Text block size={200} className={s.muted}>{when(r.asked)}</Text></> },
    { key: 'decided', label: 'Decided by', render: (r) => <>{r.decided_by ?? (r.status === 'pending' ? `Lapses ${when(r.lapses)}` : '—')}{r.note && <Text block size={200} className={s.muted}>{r.note}</Text>}</> },
    { key: 'acts', label: '', render: (r) => <div className={s.acts} onClick={(e) => e.stopPropagation()}>
      {r.can_decide && <Button size="small" appearance="primary" icon={<CheckmarkRegular />} onClick={() => setDoing({ step: 'approve', row: r })}>Approve</Button>}
      {r.can_decide && <Button size="small" icon={<DismissRegular />} onClick={() => setDoing({ step: 'reject', row: r })}>Turn down</Button>}
      {r.can_withdraw && <Button size="small" appearance="subtle" icon={<ArrowUndoRegular />} onClick={() => setDoing({ step: 'withdraw', row: r })}>Withdraw</Button>}
    </div> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Access requests" hint="Changes IT Admin asked for. They happen only when the owner or SyncLytics support approves, and lapse after 72 hours. Taking access away is done at once." classic="/admin/access-requests" />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/roles')}>Roles</Button>
        <Dropdown aria-label="Status" style={{ minWidth: 220 }} value={statuses.find((x) => x[0] === status)?.[1] ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          {statuses.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <span className={s.grow} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent={done.failed ? 'error' : 'success'} style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      <DataTable label="Access requests" columns={columns} rows={data?.rows ?? []} sort="created_at" dir="desc" loading={loading}
        empty="No access requests. When IT Admin adds a user, gives access or changes a role, it waits here for the owner or SyncLytics support." onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing && <Decide doing={doing} onClose={() => setDoing(null)} onDone={(r) => { setDoing(null); setDone(r); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function Decide({ doing, onClose, onDone }: { doing: NonNullable<Doing>; onClose: () => void; onDone: (r: UserDone & { failed?: boolean }) => void }) {
  const [note, setNote] = useState('')
  const { guard, dialog } = useReconfirm('Approving access changes is protected.')
  const { step, row } = doing
  if (dialog) return dialog
  const title = step === 'approve' ? 'Approve this access change?' : step === 'reject' ? 'Turn this down?' : 'Withdraw your request?'
  return (
    <Ask title={title} go={step === 'approve' ? 'Approve' : step === 'reject' ? 'Turn down' : 'Withdraw'} ready={step !== 'reject' || note.trim() !== ''} onClose={onClose}
      run={guard(async () => onDone(await api.decideRequest(row.id, step, note || null)))}>
      <Text block>{row.summary}{step === 'approve' ? ' It is made now, in your name, and recorded in the audit log.' : ''}</Text>
      {step !== 'withdraw' && <div style={{ marginTop: 10 }}><TextField label={step === 'reject' ? 'Why (the person who asked is told)' : 'Note (optional)'} required={step === 'reject'}
        value={note} maxLength={250} onChange={setNote} /></div>}
    </Ask>
  )
}

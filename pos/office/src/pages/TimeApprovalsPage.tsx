import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { ArrowUndoRegular, CheckmarkRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type TimeApprovalData } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { day, Panel, useProcStyles } from './ProcurementParts'

type Row = TimeApprovalData['people'][number]['rows'][number]

/**
 * Projects → Time to approve (part 27): the weeks people sent, by person. Approve them (they become project costs at each
 * person's cost rate, and billable time) or send them back with what to correct — one entry, or those ticked. Your own
 * time is approved by someone else.
 */
export function TimeApprovalsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<TimeApprovalData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [ask, setAsk] = useState<{ ids: string[]; action: 'approve' | 'reject' } | null>(null)
  const [why, setWhy] = useState('')
  const load = useCallback(() => {
    api.timeApprovals().then((x) => { setD(x); setTicked(new Set()) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Finding time to approve…" /></div>
  const open = (ids: string[], action: 'approve' | 'reject') => { setWhy(''); setDone(''); setAsk({ ids, action }) }
  const tick = (ids: string[], on: boolean) => setTicked((o) => { const n = new Set(o); ids.forEach((i) => (on ? n.add(i) : n.delete(i))); return n })
  return (
    <div className={page.page}>
      <PageHead title="Time to approve" hint="Approved hours become project costs (at each person's cost rate) and billable time. Your own time is approved by someone else." />
      <div className={s.bar}>
        <span className={s.grow} />
        <Button icon={<ArrowUndoRegular />} disabled={ticked.size === 0} onClick={() => open([...ticked], 'reject')}>Send back ticked</Button>
        <Button appearance="primary" icon={<CheckmarkRegular />} disabled={ticked.size === 0} onClick={() => open([...ticked], 'approve')}>Approve ticked ({ticked.size})</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.people.length === 0 && <Panel title="Nothing to approve"><Text className={s.muted}>Nobody has sent time for approval.</Text></Panel>}
      {d.people.map((p) => (
        <Panel key={p.id} title={`${p.name} · ${p.total} hour(s)`}>
          <DataTable<Row> label={`Time from ${p.name}`} rows={p.rows} sort="day" dir="asc" loading={false} empty="" onSort={() => undefined} onOpen={() => undefined}
            selected={ticked} onSelect={tick}
            columns={[
              { key: 'day', label: 'Day', render: (r) => day(r.day) },
              { key: 'project', label: 'Project', render: (r) => <>{r.project ?? '—'}{r.task && <Text size={200} block className={s.muted}>{r.task}</Text>}</> },
              { key: 'note', label: 'What', render: (r) => r.note ?? '—' },
              { key: 'hours', label: 'Hours', end: true, render: (r) => r.hours },
              { key: 'billable', label: 'Billed', render: (r) => (r.billable ? 'Yes' : 'No') },
              { key: 'act', label: '', render: (r) => <div className={s.actions}>
                <Button size="small" icon={<CheckmarkRegular />} onClick={(e) => { e.stopPropagation(); open([r.id], 'approve') }}>Approve</Button>
                <Button size="small" onClick={(e) => { e.stopPropagation(); open([r.id], 'reject') }}>Send back</Button>
              </div> },
            ]} />
        </Panel>
      ))}
      {ask && (
        <Ask title={ask.action === 'approve' ? `Approve ${ask.ids.length} entr${ask.ids.length === 1 ? 'y' : 'ies'}?` : `Send back ${ask.ids.length} entr${ask.ids.length === 1 ? 'y' : 'ies'}?`}
          go={ask.action === 'approve' ? 'Approve' : 'Send back'} ready={ask.action === 'approve' || why.trim() !== ''} onClose={() => setAsk(null)}
          run={() => api.answerTime(ask.ids, ask.action, why).then((x) => { setAsk(null); setDone(x.message); load() })}>
          {ask.action === 'approve'
            ? <Text>They become project costs at each person's cost rate, and billable time.</Text>
            : <TextField label="What to correct" required maxLength={255} value={why} onChange={setWhy} />}
        </Ask>
      )}
    </div>
  )
}

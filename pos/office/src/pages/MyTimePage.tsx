import { Button, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ChevronLeftRegular, ChevronRightRegular, PlayRegular, SendRegular, StopRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type MyTimeData, type Option, type TimeValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { day, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = MyTimeData['rows'][number]

/** The project's open tasks for the time form, loaded when the project changes. */
function useTasks(project: string) {
  const [tasks, setTasks] = useState<Option[]>([])
  useEffect(() => {
    if (!project) { setTasks([]); return }
    api.timeTasks(project).then((x) => setTasks(x.tasks)).catch(() => setTasks([]))
  }, [project])
  return tasks
}

function TimeAsk({ title, go, d, v, set, onClose, run, timer }: {
  title: string; go: string; d: MyTimeData; v: TimeValues; set: (v: TimeValues) => void; onClose: () => void; run: () => Promise<unknown>; timer?: boolean
}) {
  const s = useProcStyles()
  const tasks = useTasks(v.project_id)
  return (
    <Ask title={title} go={go} width={560} ready={!!v.project_id && (timer || (!!v.work_date && Number(v.hours) > 0))} onClose={onClose} run={run}>
      <div className={s.one}>
        <Choose label="Project" required options={d.projects} value={v.project_id} onChange={(x) => set({ ...v, project_id: x, task_id: '' })} />
        <Choose label="Task" options={tasks} value={v.task_id} onChange={(x) => set({ ...v, task_id: x })} clearable />
        {!timer && <TextField label="Day" required type="date" value={v.work_date} onChange={(x) => set({ ...v, work_date: x })} />}
        {!timer && <TextField label="Hours" required type="number" min={0.05} max={24} step="any" hint="e.g. 1.5 for an hour and a half" value={v.hours} onChange={(x) => set({ ...v, hours: x })} />}
        <TextField label={timer ? 'What you are doing' : 'What you did'} maxLength={255} value={v.note} onChange={(x) => set({ ...v, note: x })} />
      </div>
    </Ask>
  )
}

/**
 * Projects → My time (part 27): the person's week — a start / stop timer and the hours logged day by day on projects and
 * tasks — then "Send this week" for a manager's approval. Entries sent back come with the reason and can be corrected.
 * The week lives in the address.
 */
export function MyTimePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<MyTimeData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [form, setForm] = useState<{ id: string | null; v: TimeValues; timer?: boolean } | null>(null)
  const [ask, setAsk] = useState<{ kind: 'remove'; row: Row } | { kind: 'submit' } | null>(null)
  const query = serialize(params)
  const load = useCallback(() => {
    const c = new AbortController()
    api.myTime(query, c.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening your week…" /></div>
  const after = (x: { message: string }) => { setForm(null); setAsk(null); setDone(x.message); load() }
  const blank = (): TimeValues => ({ project_id: '', task_id: '', work_date: d.today >= d.from && d.today <= d.to ? d.today : d.to, hours: '', note: '' })
  const timer = (action: 'stop') => api.timeTimer(action).then(after).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  return (
    <div className={page.page}>
      <PageHead title="My time" hint={`Week of ${day(d.from)} – ${day(d.to)} · ${d.total} hour(s). Log your hours, then “Send this week” for approval.`} classic={`/admin/my-time?week=${d.week}`} />
      <div className={s.bar}>
        <Button icon={<ChevronLeftRegular />} onClick={() => set({ week: d.previous })}>Previous week</Button>
        {d.next && <Button icon={<ChevronRightRegular />} iconPosition="after" onClick={() => set({ week: d.next! })}>Next week</Button>}
        <span className={s.grow} />
        {!d.running && <Button icon={<PlayRegular />} onClick={() => { setDone(''); setForm({ id: null, v: blank(), timer: true }) }}>Start timer</Button>}
        {d.running && <Button icon={<StopRegular />} onClick={() => timer('stop')}>Stop timer</Button>}
        {d.can_submit && <Button icon={<SendRegular />} onClick={() => setAsk({ kind: 'submit' })}>Send this week</Button>}
        <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setForm({ id: null, v: blank() }) }}>Add time</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.running && (
        <Panel title="Timer running">
          <Text weight="semibold" style={{ color: tokens.colorPaletteGreenForeground1 }}>● </Text>
          <Text weight="semibold">{d.running.project}{d.running.task ? ` · ${d.running.task}` : ''}</Text>
          <Text block className={s.muted}>Running since {new Date(d.running.since).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })} ({d.running.for}){d.running.note ? ` — ${d.running.note}` : ''}</Text>
        </Panel>
      )}
      <DataTable<Row> label="My time" rows={d.rows} sort="day" dir="asc" loading={false} empty="No time this week. Add your hours, or start the timer when you begin a task." onSort={() => undefined}
        onOpen={(r) => r.can_change && setForm({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'day', label: 'Day', render: (r) => (r.day ? new Date(`${r.day}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) : '—') },
          { key: 'project', label: 'Project', render: (r) => <>{r.project ?? '—'}{r.task && <Text size={200} block className={s.muted}>{r.task}</Text>}</> },
          { key: 'note', label: 'What', render: (r) => r.note ?? '—' },
          { key: 'hours', label: 'Hours', end: true, render: (r) => r.hours },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.reason && <Text size={200} block className={s.muted}>{r.reason}</Text>}</> },
          { key: 'act', label: '', render: (r) => r.can_change && <div className={s.actions}>
            <Button size="small" onClick={(e) => { e.stopPropagation(); setForm({ id: r.id, v: { ...r.values } }) }}>Change</Button>
            <Button size="small" onClick={(e) => { e.stopPropagation(); setAsk({ kind: 'remove', row: r }) }}>Remove</Button>
          </div> },
        ]} />

      {form && (
        <TimeAsk title={form.timer ? 'Start the timer' : form.id ? 'Change the time' : 'Add time'} go={form.timer ? 'Start' : form.id ? 'Save' : 'Add'} d={d} v={form.v} timer={form.timer}
          set={(v) => setForm({ ...form, v })} onClose={() => setForm(null)}
          run={() => (form.timer
            ? api.timeTimer('start', { project_id: form.v.project_id, task_id: form.v.task_id || null, note: form.v.note })
            : api.logTime(form.id, { ...form.v, task_id: form.v.task_id || null })).then(after)} />
      )}
      {ask?.kind === 'remove' && (
        <Ask title="Remove this time?" go="Remove" ready onClose={() => setAsk(null)} run={() => api.removeTime(ask.row.id).then(after)}>
          <Text>{ask.row.hours} on {ask.row.project} ({day(ask.row.day)}).</Text>
        </Ask>
      )}
      {ask?.kind === 'submit' && (
        <Ask title="Send this week?" go="Send" ready onClose={() => setAsk(null)} run={() => api.timeTimer('submit', { week: d.week }).then(after)}>
          <Text>Your hours this week go to your manager for approval; you cannot change them unless they are sent back.</Text>
        </Ask>
      )}
    </div>
  )
}

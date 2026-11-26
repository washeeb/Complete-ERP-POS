import { Button, Dropdown, Link, MessageBar, MessageBarBody, Option, Switch, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api } from '../api'
import type { CrmFollowUpList } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { useProcStyles } from './ProcurementParts'

type Row = CrmFollowUpList['rows'][number]
const OPEN: [string, string][] = [['1', 'To do only'], ['0', 'Done'], ['all', 'All']]

/**
 * CRM → Follow-ups (part 25): calls, visits and tasks to do — the person's own by default, overdue first — with Done (and
 * what came of it) and Another time. Plans are made from a lead, deal or customer ("Follow-up").
 */
export function CrmFollowUpsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.crmFollowUps, serialize(params), refresh)
  const [doing, setDoing] = useState<{ row: Row; kind: 'done' | 'move' } | null>(null)
  const [v, setV] = useState('')
  const [done, setDone] = useState('')
  const mine = str(params, 'mine') !== '0'
  const open = str(params, 'open') || '1'
  return (
    <div className={page.page}>
      <PageHead title="Follow-ups" hint="What to do and when. Plan new ones from a lead, a deal or a customer with “Follow-up”; you are reminded when one is due." />
      <div className={s.bar}>
        <Switch label="Mine only" checked={mine} onChange={(_, d) => set({ mine: d.checked ? '' : '0' })} />
        <Dropdown aria-label="To do or done" value={OPEN.find(([k]) => k === open)?.[1] ?? 'To do only'} selectedOptions={[open]} style={{ minWidth: 130 }} onOptionSelect={(_, d) => set({ open: d.optionValue ?? '1' })}>
          {OPEN.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        {!mine && <Pick label="Who" any="Anyone" options={data?.people ?? []} value={str(params, 'who')} onChange={(x) => set({ who: x })} />}
        <Pick label="What" any="Anything" options={data?.types ?? []} value={str(params, 'type')} onChange={(x) => set({ type: x })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Follow-ups" rows={data?.rows ?? []} sort="due" dir="asc" loading={loading} empty="Nothing to follow up." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'due', label: 'When', render: (r) => <span style={r.late ? { color: 'var(--zx-bad)' } : undefined}>{when(r.due_at)}{r.late && <Text size={200} block>Overdue</Text>}</span> },
          { key: 'type', label: 'What', render: (r) => r.type },
          { key: 'subject', label: 'About', render: (r) => <><Text weight="semibold">{r.subject}</Text>{r.details && <Text size={200} block className={s.muted}>{r.details}</Text>}</> },
          { key: 'with', label: 'With', render: (r) => (r.open ? <Link onClick={() => navigate(r.open!)}>{r.with}</Link> : r.with) },
          { key: 'who', label: 'Who', render: (r) => r.who ?? '—' },
          { key: 'done', label: 'Done', render: (r) => <>{r.done ?? 'To do'}{r.outcome && <Text size={200} block className={s.muted}>{r.outcome}</Text>}</> },
          { key: 'act', label: '', render: (r) => r.can_do && <div className={s.actions}>
            <Button size="small" onClick={() => { setV(''); setDone(''); setDoing({ row: r, kind: 'done' }) }}>Done</Button>
            <Button size="small" appearance="subtle" onClick={() => { setV(new Date(Date.now() + 864e5).toISOString().slice(0, 10) + 'T09:00'); setDone(''); setDoing({ row: r, kind: 'move' }) }}>Another time</Button>
          </div> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {doing && (
        <Ask title={doing.kind === 'done' ? `Done: ${doing.row.subject}` : `Another time: ${doing.row.subject}`} go={doing.kind === 'done' ? 'Done' : 'Move'}
          ready={doing.kind === 'done' || !!v} onClose={() => setDoing(null)}
          run={() => api.crmActivityAction(doing.row.id, doing.kind, doing.kind === 'done' ? { outcome: v || null } : { due_at: v }).then((x) => { setDoing(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          {doing.kind === 'done' ? <TextField label="What came of it" maxLength={255} value={v} onChange={setV} />
            : <TextField label="When" required type="datetime-local" value={v} onChange={setV} />}
        </Ask>
      )}
    </div>
  )
}

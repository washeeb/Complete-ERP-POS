import { Button, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { AlertRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type CrmActivityRow, type FollowUpChoices } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { when } from '../list/ListParts'
import { Panel, useProcStyles } from './ProcurementParts'

type On = { lead_id?: string; deal_id?: string; customer_id?: string }

/**
 * "Follow-up" (part 25) on a lead, a deal or a customer: something to do later (who does it, when), or a note of what
 * just happened (with what came of it), through Crm::logActivity, as the current pages' action.
 */
export function FollowUpAsk({ on, choices, onClose, onDone }: { on: On; choices: FollowUpChoices; onClose: () => void; onDone: (message: string) => void }) {
  const s = useProcStyles()
  const [v, setV] = useState({ type: 'call', subject: '', due_at: choices.due, assigned_to: choices.me, outcome: '', details: '' })
  const note = v.type === 'note'
  return (
    <Ask title="Plan a follow-up or note what happened" go="Save" width={560} ready={v.subject.trim() !== ''} onClose={onClose}
      run={() => api.crmActivity({ ...on, type: v.type, subject: v.subject, details: v.details || null, due_at: note ? null : v.due_at || null,
        assigned_to: !note && v.due_at ? v.assigned_to || null : null, outcome: !note && !v.due_at ? v.outcome || null : null }).then((x) => onDone(x.message))}>
      <div className={s.one}>
        <Choose label="What" required options={choices.types} value={v.type} onChange={(x) => setV({ ...v, type: x })} />
        <TextField label="About" required maxLength={255} placeholder="e.g. Call back about the price" value={v.subject} onChange={(x) => setV({ ...v, subject: x })} />
        {!note && <TextField label="When (leave empty if it already happened)" type="datetime-local" value={v.due_at} onChange={(x) => setV({ ...v, due_at: x })} />}
        {!note && v.due_at && <Choose label="Who does it" options={choices.people} value={v.assigned_to} onChange={(x) => setV({ ...v, assigned_to: x })} />}
        {!note && !v.due_at && <TextField label="What came of it" maxLength={255} value={v.outcome} onChange={(x) => setV({ ...v, outcome: x })} />}
        <LongText label="Details" maxLength={5000} value={v.details} onChange={(x) => setV({ ...v, details: x })} />
      </div>
    </Ask>
  )
}

/** Follow-ups and notes on a lead or a deal (the same list on both), with Follow-up and Done. */
export function Activities({ on, rows, choices, canManage, onChanged }: { on: On; rows: CrmActivityRow[]; choices: FollowUpChoices; canManage: boolean; onChanged: (message: string) => void }) {
  const s = useProcStyles()
  const [asking, setAsking] = useState(false)
  const [doing, setDoing] = useState<CrmActivityRow | null>(null)
  const [outcome, setOutcome] = useState('')
  return (
    <Panel title="Follow-ups and notes" actions={canManage ? <Button icon={<AlertRegular />} onClick={() => setAsking(true)}>Follow-up</Button> : undefined}>
      {rows.length === 0 ? <Text className={s.muted}>Nothing yet. Plan the next call or visit, or note what was said, with “Follow-up”.</Text> : (
        <Table size="small" aria-label="Follow-ups and notes" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>About</TableHeaderCell><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Who</TableHeaderCell>
            <TableHeaderCell>Done</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>{rows.map((a) => (
            <TableRow key={a.id}>
              <TableCell>{a.type}</TableCell>
              <TableCell>{a.subject}{a.details && <Text size={200} block className={s.muted}>{a.details}</Text>}</TableCell>
              <TableCell style={a.late ? { color: 'var(--zx-bad)' } : undefined}>{a.due_at ? when(a.due_at) : '—'}</TableCell>
              <TableCell>{a.who ?? '—'}</TableCell>
              <TableCell>{a.done ?? 'To do'}{a.outcome && <Text size={200} block className={s.muted}>{a.outcome}</Text>}</TableCell>
              <TableCell>{a.can_do && <Button size="small" onClick={() => { setOutcome(''); setDoing(a) }}>Done</Button>}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      )}
      {asking && <FollowUpAsk on={on} choices={choices} onClose={() => setAsking(false)} onDone={(m) => { setAsking(false); onChanged(m) }} />}
      {doing && (
        <Ask title={`Done: ${doing.subject}`} go="Done" ready onClose={() => setDoing(null)}
          run={() => api.crmActivityAction(doing.id, 'done', { outcome: outcome || null }).then((x) => { setDoing(null); onChanged(x.message) })}>
          <TextField label="What came of it" maxLength={255} value={outcome} onChange={setOutcome} />
        </Ask>
      )}
    </Panel>
  )
}

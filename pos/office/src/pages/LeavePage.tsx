import { Button, Dropdown, Field, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type LeaveRow, type Option as Choice } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * Request leave (part 21): for yourself (My leave & payslips) or, by HR, for anyone — the type, first and last day off,
 * the reason and a document such as a doctor's note (photo or PDF). Working days are counted from the dates.
 */
export function LeaveAsk({ mine, onClose, onDone }: { mine: boolean; onClose: () => void; onDone: (message: string) => void }) {
  const s = useProcStyles()
  const [opts, setOpts] = useState<{ types: Choice[]; employees: Choice[] } | null>(null)
  const [v, setV] = useState({ employee_id: '', leave_type_id: '', starts_on: '', ends_on: '', reason: '' })
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.leaveForm(mine).then(setOpts).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [mine])
  const ready = !!opts && (mine || !!v.employee_id) && !!v.leave_type_id && !!v.starts_on && !!v.ends_on
  return (
    <Ask title={mine ? 'Request leave' : 'Record leave for someone'} go="Request" ready={ready} onClose={onClose} width={560}
      run={() => {
        const body = new FormData()
        if (mine) body.append('mine', '1')
        for (const [k, x] of Object.entries(v)) if (x !== '') body.append(k, x)
        if (file) body.append('document', file)
        return api.requestLeave(body).then((x) => onDone(x.message))
      }}>
      <div className={s.one}>
        {error && <Text style={{ color: 'var(--zx-bad)' }}>{error}</Text>}
        {!mine && <Choose label="Employee" required options={opts?.employees ?? []} value={v.employee_id} onChange={(x) => setV({ ...v, employee_id: x })} />}
        <Choose label="Type" required options={opts?.types ?? []} value={v.leave_type_id} onChange={(x) => setV({ ...v, leave_type_id: x })} />
        <div className={s.grid}>
          <TextField label="From" required type="date" value={v.starts_on} onChange={(x) => setV({ ...v, starts_on: x })} />
          <TextField label="To (last day off)" required type="date" value={v.ends_on} onChange={(x) => setV({ ...v, ends_on: x })} />
        </div>
        <TextField label="Reason" maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        <Field label="Document (e.g. doctor's note)" hint="A photo or PDF, up to 5 MB.">
          <input type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </div>
    </Ask>
  )
}

/**
 * HR & payroll → Leave (part 21): every request with its type, dates, working days and status. The line manager
 * approves or refuses (with the reason) — never their own; a request not started yet can be cancelled. HR records
 * leave for anyone.
 */
export function LeavePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.leave, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [asking, setAsking] = useState(false)
  const [doing, setDoing] = useState<{ row: LeaveRow; action: 'approve' | 'refuse' | 'cancel' } | null>(null)
  const [note, setNote] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setAsking(false); setDoing(null); setDone(m); setRefresh((n) => n + 1) }
  const status = str(params, 'status')
  return (
    <div className={page.page}>
      <PageHead title="Leave" hint="Requests for leave, approved by the line manager (never their own)." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Employee" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search leave" />
        <Dropdown aria-label="Status" value={status ? data?.statuses.find((x) => x.id === status)?.label ?? '' : 'Any status'} selectedOptions={[status]} style={{ minWidth: 190 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          <Option value="">Any status</Option>{(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {data?.can_record && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setAsking(true) }}>Record leave for someone</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[{ label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined }]} />}
      <DataTable<LeaveRow> label="Leave" rows={data?.rows ?? []} sort="from" dir="desc" loading={loading} empty="No leave requests match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'employee', label: 'Employee', render: (r) => r.employee ?? '—' },
          { key: 'type', label: 'Type', render: (r) => r.type ?? '—' },
          { key: 'from', label: 'From', render: (r) => day(r.starts_on) },
          { key: 'to', label: 'To', render: (r) => day(r.ends_on) },
          { key: 'days', label: 'Working days', end: true, render: (r) => r.days },
          { key: 'reason', label: 'Reason', render: (r) => <>{r.reason ?? '—'}{r.document && <Text size={200} block className={s.muted}>Document attached</Text>}</> },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.note && <Text size={200} block className={s.muted}>{r.note}</Text>}</> },
          { key: 'act', label: '', render: (r) => (r.can_decide || r.can_cancel) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.employee}'s leave`} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_decide && <MenuItem onClick={() => { setNote(''); setDoing({ row: r, action: 'approve' }) }}>Approve</MenuItem>}
                {r.can_decide && <MenuItem onClick={() => { setNote(''); setDoing({ row: r, action: 'refuse' }) }}>Refuse</MenuItem>}
                {r.can_cancel && <MenuItem onClick={() => { setNote(''); setDoing({ row: r, action: 'cancel' }) }}>Cancel</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {asking && <LeaveAsk mine={false} onClose={() => setAsking(false)} onDone={finish} />}
      {doing && (
        <Ask title={{ approve: 'Approve this leave?', refuse: 'Refuse this leave?', cancel: 'Cancel this leave?' }[doing.action]}
          go={{ approve: 'Approve', refuse: 'Refuse', cancel: 'Cancel the leave' }[doing.action]} ready={doing.action !== 'refuse' || note.trim() !== ''} onClose={() => setDoing(null)}
          run={() => api.leaveAction(doing.row.id, doing.action, doing.action === 'refuse' ? { note } : {}).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>{doing.row.employee}: {doing.row.type}, {day(doing.row.starts_on)} to {day(doing.row.ends_on)} ({doing.row.days} working days).</Text>
            {doing.action === 'refuse' && <TextField label="Reason" required maxLength={255} value={note} onChange={setNote} />}
          </div>
        </Ask>
      )}
    </div>
  )
}

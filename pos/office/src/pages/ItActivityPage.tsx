import { Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Table, TableBody, TableCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type ActivityRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '260px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const WHO = [['', 'Everyone'], ['tech', 'IT and SyncLytics support only'], ['support', 'SyncLytics support only']]
const WHEN = [['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days'], ['all', 'All']]
const at = (d: string | null) => (d ? new Date(d).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

/**
 * IT and support activity (settings and admin): everything IT Admin and SyncLytics support did, and every technical
 * change by anyone (users, access, roles, devices, settings, sync, remote sessions, API keys, modules, backups, sign-in
 * security), in plain words, newest first, from the audit log, which cannot be changed. Read only; a row opens in full.
 */
export function ItActivityPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [open, setOpen] = useState<ActivityRow | null>(null)
  const [q, setQ] = useState(str(params, 'q'))
  const query = serialize({ ...params, when: str(params, 'when') || '30' })
  const { data, loading, error } = useList(api.itActivity, query, 0)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const who = str(params, 'who')
  const when = str(params, 'when') || '30'
  const columns: Column<ActivityRow>[] = [
    { key: 'when', label: 'When', render: (r) => at(r.when) },
    { key: 'who', label: 'Who', render: (r) => <>{r.who ?? '—'}{r.who_kind && <Text block size={200} className={s.muted}>{r.who_kind}</Text>}</> },
    { key: 'what', label: 'What', render: (r) => <><Badge appearance="tint">{r.what}</Badge><Text block size={200} className={s.muted}>{r.record}</Text></> },
    { key: 'details', label: 'Details', render: (r) => r.details ?? '—' },
  ]
  return (
    <div className={page.page}>
      <PageHead title="IT and support activity" hint="Everything IT Admin and SyncLytics support did, and every change to users, access, devices, settings and the other technical parts, by anyone. You see the technical side without being able to change it." classic="/admin/it-activity" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Who" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search by who" />
        <Dropdown aria-label="Who" style={{ minWidth: 220 }} value={WHO.find((x) => x[0] === who)?.[1] ?? ''} selectedOptions={[who]} onOptionSelect={(_, d) => set({ who: d.optionValue ?? '' })}>
          {WHO.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="When" style={{ minWidth: 150 }} value={WHEN.find((x) => x[0] === when)?.[1] ?? ''} selectedOptions={[when]} onOptionSelect={(_, d) => set({ when: d.optionValue ?? '30' })}>
          {WHEN.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="IT and support activity" columns={columns} rows={data?.rows ?? []} sort="when" dir="desc" loading={loading}
        empty="Nothing in this period: nothing technical was changed, and IT and support did nothing." onSort={() => undefined} onOpen={setOpen} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {open && (
        <Dialog open onOpenChange={(_, d) => !d.open && setOpen(null)}>
          <DialogSurface style={{ maxWidth: 640 }}>
            <DialogBody>
              <DialogTitle>{open.what}</DialogTitle>
              <DialogContent>
                <Text block>{at(open.when)} · {open.who ?? '—'}{open.who_kind ? ` (${open.who_kind})` : ''} · {open.record}</Text>
                {open.computer && <Text block size={200} className={s.muted}>{open.computer}</Text>}
                {open.reason && <Text block style={{ marginTop: 8 }}><b>Reason:</b> {open.reason}</Text>}
                {open.changes.length > 0 && <Table size="small" aria-label="What changed" style={{ marginTop: 8 }}><TableBody>
                  {open.changes.map((c, i) => <TableRow key={i}><TableCell style={{ width: '35%' }}>{c.field}</TableCell><TableCell>{c.value}</TableCell></TableRow>)}
                </TableBody></Table>}
              </DialogContent>
              <DialogActions><Button onClick={() => setOpen(null)}>Close</Button></DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  )
}

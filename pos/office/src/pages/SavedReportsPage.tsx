import { Button, Link, makeStyles, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SavedReportRow } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Saved reports (release 7): your own reports and those others shared, by name — the data, who saved it, when it is
 * sent next and how the last sending went. A report opens in the report builder; who saved it may delete it.
 */
export function SavedReportsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [q, setQ] = useState('')
  const [rows, setRows] = useState<SavedReportRow[] | null>(null)
  const [error, setError] = useState('')
  const [deleting, setDeleting] = useState<SavedReportRow | null>(null)
  const [nonce, setNonce] = useState(0)
  useEffect(() => {
    const t = setTimeout(() => {
      api.savedReports(q).then((d) => { setRows(d.rows); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
    }, 250)
    return () => clearTimeout(t)
  }, [q, nonce])

  const columns: Column<SavedReportRow>[] = [
    { key: 'name', label: 'Name', render: (r) => <Link onClick={(e) => { e.stopPropagation(); navigate(r.open) }}>{r.name}</Link> },
    { key: 'data', label: 'Data', render: (r) => r.dataset },
    { key: 'by', label: 'Saved by', render: (r) => <>{r.by ?? '—'}{r.shared && <Text block size={200} className={s.muted}>Shared</Text>}</> },
    { key: 'schedule', label: 'Schedule', render: (r) => <>{r.schedule_label}{r.next && <Text block size={200} className={s.muted}>Next: {r.next}</Text>}</> },
    { key: 'last', label: 'Last sent', render: (r) => r.last ?? '—' },
    { key: 'actions', label: '', render: (r) => (r.mine
      ? <Button size="small" appearance="subtle" icon={<DeleteRegular />} aria-label={`Delete ${r.name}`} onClick={(e) => { e.stopPropagation(); setDeleting(r) }} /> : null) },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Saved reports" hint="Reports saved from the report builder: yours and those shared with you. Open one to run it again; scheduled ones are sent by themselves." classic="/admin/saved-reports" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search saved reports" />
        <span className={s.grow} />
        <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/report-builder')}>New report</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="Saved reports" columns={columns} rows={rows ?? []} sort="name" dir="asc" loading={rows === null}
        empty={q ? 'No saved report has that name.' : 'No saved reports yet. Build one, then Save report.'} onSort={() => undefined} onOpen={(r) => navigate(r.open)} />
      {deleting && <Ask title={`Delete “${deleting.name}”?`} go="Delete" ready onClose={() => setDeleting(null)}
        run={async () => { await api.deleteReport(deleting.id); setDeleting(null); setNonce((n) => n + 1) }}>
        It stops being sent, and nobody can open it again. The data it reports on is not touched.
      </Ask>}
    </div>
  )
}

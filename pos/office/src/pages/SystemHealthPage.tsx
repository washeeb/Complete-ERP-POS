import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { ArrowSyncRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type HealthData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { END, Panel, useProcStyles } from './ProcurementParts'

const STATUS = { ok: ['OK', 'var(--zx-good)'], warning: ['Look at this', 'var(--zx-warn)'], fail: ['Fix this', 'var(--zx-bad)'] } as const

/**
 * System → Health (part 22): the launch checklist's live checks (scheduler, backups, disk, settings, licence and anything
 * stuck) and the slowest pages and till calls of the last 7 days, worst first.
 */
export function SystemHealthPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<HealthData | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    setBusy(true)
    api.systemHealth().then((x) => { setD(x); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }, [])
  useEffect(load, [load])
  return (
    <div className={page.page}>
      <PageHead title="System health" hint="The same checks run on the server with: php artisan erp:health" />
      <div className={s.bar}><span className={s.grow} /><Button icon={<ArrowSyncRegular />} disabled={busy} onClick={load}>{busy ? 'Checking…' : 'Check again'}</Button></div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {!d ? <Spinner label="Checking…" /> : <>
        <Panel title="Checks">
          <Table size="small" aria-label="Health checks" style={{ minWidth: 560 }}>
            <TableHeader><TableRow><TableHeaderCell>Check</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Detail</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.checks.map((c) => (
              <TableRow key={c.check}><TableCell>{c.check}</TableCell>
                <TableCell><Text weight="semibold" style={{ color: STATUS[c.status][1] }}>{STATUS[c.status][0]}</Text></TableCell><TableCell>{c.detail}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
        <Panel title="Slow pages (last 7 days)" about={`Pages and till calls that took over ${d.slow_over} s, worst first. Many database reads on one line points to a page to speed up; send this list to SyncLytics support.`}>
          <Table size="small" aria-label="Slow pages" style={{ minWidth: 640 }}>
            <TableHeader><TableRow><TableHeaderCell>Page or call</TableHeaderCell><TableHeaderCell button={END}>Times</TableHeaderCell><TableHeaderCell button={END}>Average</TableHeaderCell>
              <TableHeaderCell button={END}>Slowest</TableHeaderCell><TableHeaderCell button={END}>Database reads</TableHeaderCell><TableHeaderCell>Last</TableHeaderCell></TableRow></TableHeader>
            <TableBody>
              {d.slow.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>None: every page answered quickly.</Text></TableCell></TableRow>}
              {d.slow.map((p) => (
                <TableRow key={p.id}><TableCell><Text font="monospace" size={200}>{p.page}</Text></TableCell><TableCell className={s.end}>{p.times}</TableCell><TableCell className={s.end}>{p.avg}</TableCell>
                  <TableCell className={s.end}>{p.max}</TableCell><TableCell className={s.end}>{p.queries}</TableCell><TableCell>{p.last}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      </>}
    </div>
  )
}

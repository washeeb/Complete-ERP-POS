import { Button, MessageBar, MessageBarBody, Radio, RadioGroup, Field, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { PersonSupportRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SupportAccessData } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * System → Support access (part 23): when SyncLytics support may reach this company's tills and computers — for a few
 * hours or until a problem report is closed; free of charge. Everyone signed in sees a banner while it is open. Give
 * support access, and Withdraw now.
 */
export function SupportAccessPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<SupportAccessData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [v, setV] = useState<{ level: string; branch_id: string; until: string; support_report_id: string; reason: string } | null>(null)
  const [revoke, setRevoke] = useState<string | null>(null)
  useEffect(() => { api.supportAccess().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening support access…" /></div>
  const finish = (x: { message: string; support: SupportAccessData }) => { setD(x.support); setV(null); setRevoke(null); setDone(x.message) }
  return (
    <div className={page.page}>
      <PageHead title="Support access"
        hint="Let SyncLytics support reach your tills and computers to help: for a few hours, or until a problem report is closed. Free of charge. Everyone signed in sees a banner while it is open, and you can withdraw it at any time." />
      <div className={s.bar}>
        <span className={s.grow} />
        {d.can_grant && <Button appearance="primary" icon={<PersonSupportRegular />} onClick={() => { setDone(''); setV({ level: 'control', branch_id: '', until: '2', support_report_id: '', reason: '' }) }}>Give support access</Button>}
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Access given">
        <Table size="small" aria-label="Support access" style={{ minWidth: 720 }}>
          <TableHeader><TableRow><TableHeaderCell>Now</TableHeaderCell><TableHeaderCell>Support may</TableHeaderCell><TableHeaderCell>Devices of</TableHeaderCell><TableHeaderCell>For</TableHeaderCell>
            <TableHeaderCell>From</TableHeaderCell><TableHeaderCell>Until</TableHeaderCell><TableHeaderCell>Given by</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.rows.length === 0 && <TableRow><TableCell colSpan={8}><Text className={s.muted}>Support has never been given access.</Text></TableCell></TableRow>}
            {d.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell><ToneBadge tone={r.tone} label={r.state} /></TableCell><TableCell>{r.level}</TableCell><TableCell>{r.branch ?? 'Every branch'}</TableCell>
                <TableCell>{r.reason}</TableCell><TableCell>{when(r.from)}</TableCell><TableCell>{r.until ?? '—'}</TableCell>
                <TableCell>{r.by ?? '—'}{r.withdrawn_by && <Text size={200} block className={s.muted}>Withdrawn by {r.withdrawn_by}</Text>}</TableCell>
                <TableCell>{r.can_revoke && <Button size="small" onClick={() => { setDone(''); setRevoke(r.id) }}>Withdraw now</Button>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {v && (
        <Ask title="Give support access" go="Give access" width={600} ready={!!v.level && v.reason.trim() !== '' && (v.until !== 'report' || !!v.support_report_id)} onClose={() => setV(null)}
          run={() => api.grantSupport({ ...v, branch_id: v.branch_id || null, support_report_id: v.until === 'report' ? v.support_report_id : null }).then(finish)}>
          <div className={s.one}>
            <Field label="Support may" required>
              <RadioGroup value={v.level} onChange={(_, e) => setV({ ...v, level: e.value })}>{d.levels.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}</RadioGroup>
            </Field>
            <Choose label="Devices of" options={d.branches} value={v.branch_id} onChange={(x) => setV({ ...v, branch_id: x })} placeholder="Every branch" clearable />
            <Choose label="For" required options={d.durations} value={v.until} onChange={(x) => setV({ ...v, until: x })} />
            {v.until === 'report' && <Choose label="Problem report" required options={d.reports} value={v.support_report_id} onChange={(x) => setV({ ...v, support_report_id: x })} />}
            <TextField label="What support is helping with" required maxLength={255} placeholder="e.g. Printer on Till 2 prints blank receipts" value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
          </div>
        </Ask>
      )}
      {revoke && (
        <Ask title="Withdraw support access now?" go="Withdraw now" ready onClose={() => setRevoke(null)} run={() => api.revokeSupport(revoke).then(finish)}>
          <Text>Support loses access at once.</Text>
        </Ask>
      )}
    </div>
  )
}

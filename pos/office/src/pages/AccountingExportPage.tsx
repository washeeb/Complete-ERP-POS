import { Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowDownloadRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AccountingExportOptions } from '../api'
import { download } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'

const useStyles = makeStyles({
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '16px', maxWidth: '760px', display: 'flex', flexDirection: 'column', gap: '12px' },
  row: { display: 'flex', flexWrap: 'wrap', gap: '10px' },
  program: { flex: '2 1 340px', minWidth: 0 },
  date: { flex: '1 1 160px', minWidth: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Export to QuickBooks / Xero / Sage (release 8): every journal entry posted in a period, line by line with the account
 * code and name, as the file the other program imports. For a company-wide finance role (the whole company's books).
 */
export function AccountingExportPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [o, setO] = useState<AccountingExportOptions | null>(null)
  const [error, setError] = useState('')
  const [format, setFormat] = useState('quickbooks')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState('')
  useEffect(() => {
    api.accountingExport().then((d) => { setO(d); setFrom(d.from); setTo(d.to) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])

  if (!o) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening…" />}</div>
  const go = async () => {
    setBusy(true); setError(''); setDone('')
    const problem = await download(api.accountingExportUrl(format, from, to), `${format}-journals-${from}-to-${to}.csv`)
    if (problem) setError(problem); else setDone('The file is saved in your downloads.')
    setBusy(false)
  }

  return (
    <div className={page.page}>
      <PageHead title="Export to another accounting program" hint="The books for a period as journals for QuickBooks, Xero or Sage, for an accountant who keeps the books there." classic="/admin/accounting-export" />
      <section className={s.panel} aria-label="Export the books">
        <div className={s.row}>
          <Field className={s.program} label="Program" required>
            <Dropdown value={o.formats[format] ?? ''} selectedOptions={[format]} onOptionSelect={(_, d) => setFormat(d.optionValue ?? 'quickbooks')}>
              {Object.entries(o.formats).map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
            </Dropdown>
          </Field>
          <Field className={s.date} label="From" required><Input type="date" value={from} max={to || undefined} onChange={(_, d) => setFrom(d.value)} /></Field>
          <Field className={s.date} label="To" required><Input type="date" value={to} min={from || undefined} onChange={(_, d) => setTo(d.value)} /></Field>
        </div>
        <Text className={s.muted}>
          Every journal entry posted in the period, line by line, with the account code and name (amounts before tax as booked). Create the same account codes in the
          other program first, or match them up when importing. Dates are day/month/year.
        </Text>
        {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        {done && <MessageBar intent="success"><MessageBarBody>{done}</MessageBarBody></MessageBar>}
        <div><Button appearance="primary" icon={busy ? <Spinner size="tiny" /> : <ArrowDownloadRegular />} disabled={busy || !from || !to} onClick={go}>{busy ? 'Preparing…' : 'Download the file'}</Button></div>
      </section>
    </div>
  )
}

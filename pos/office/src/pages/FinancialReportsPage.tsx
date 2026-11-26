import {
  Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Text, tokens,
} from '@fluentui/react-components'
import { ArrowSplitRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type FrOptions, type FrReport } from '../api'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { ReportTable, TONE } from '../list/ReportTables'
import { parse, serialize, str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '12px' },
  muted: { color: tokens.colorNeutralForeground3 },
})


/**
 * Financial reports (release 7): trial balance, profit and loss, profit by industry, balance sheet, account ledger,
 * budget vs actual, VAT return and the ledger check — the same figures and filters as the current page. The report,
 * dates and filters live in the address, so a link or bookmark opens the same report. An account on the trial balance
 * opens its ledger; a journal on the ledger opens the journal. Export gives exactly what is shown.
 */
export function FinancialReportsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<FrOptions | null>(null)
  const [data, setData] = useState<FrReport | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [nonce, setNonce] = useState(0)
  const [sharing, setSharing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState('')
  const query = serialize(params)
  useEffect(() => { api.financialOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const c = new AbortController()
    setLoading(true)
    api.financialReport(query, c.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!c.signal.aborted) setLoading(false) })
    return () => c.abort()
  }, [query, nonce])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up the books…" /></div>
  const r = data.report
  const label = (o: { id: string; label: string }[] | undefined, id: string | null, none: string) => (id ? o?.find((x) => x.id === id)?.label ?? id : none)
  const pick = (name: string, value: string | null, items: { id: string; label: string }[], none: string, key: string, width = 180) => (
    <Field label={name}>
      <Dropdown aria-label={name} value={label(items, value, none)} selectedOptions={[value ?? '']} style={{ minWidth: width }}
        onOptionSelect={(_, d) => set({ [key]: d.optionValue || (key === 'industry' ? 'all' : '') })}>
        <Option value="">{none}</Option>{items.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
  const open = (to: string) => {
    if (to.startsWith('?')) set({ ...parse(to), industry: str(params, 'industry') })
    else navigate(to)
  }
  return (
    <div className={page.page}>
      <PageHead title="Financial reports" hint="The books: trial balance, profit and loss, balance sheet, account ledgers, budget, VAT and the ledger check. Choose the report, the dates and the filters." />
      <div className={s.bar}>
        {pick('Report', r, options?.reports ?? [], 'Trial balance', 'report', 190)}
        {r === 'ledger' && pick('Account', data.account, options?.accounts ?? [], 'Choose…', 'account', 240)}
        {r !== 'check' && <>
          {r !== 'bs' && <Field label="From"><Input type="date" value={data.from} onChange={(_, d) => d.value && set({ from: d.value })} /></Field>}
          <Field label={r === 'bs' ? 'As at' : 'To'}><Input type="date" value={data.to} onChange={(_, d) => d.value && set({ to: d.value })} /></Field>
          {r !== 'vat' && pick('Branch', data.branch, options?.branches ?? [], 'All branches', 'branch')}
          {r !== 'budget' && (options?.books.length ?? 0) > 0 && pick('Books', data.books, options!.books, r === 'vat' ? 'Head office company' : 'Whole group (consolidated)', 'books', 220)}
          {r !== 'vat' && r !== 'industries' && (options?.industries.length ?? 0) > 0 && pick('Industry', data.industry, options!.industries, 'All industries', 'industry')}
        </>}
        <span className={s.grow} />
        {loading && <Spinner size="tiny" />}
        {data.share && <Button icon={<ArrowSplitRegular />} onClick={() => { setDone(''); setSharing(true) }}>Share head office costs</Button>}
        <ExportMenu url="/office-api/financial-reports/export" query={query} total={data.tables.reduce((n, t) => n + t.rows.length, 0) || null} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data.warning && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{data.warning}</MessageBarBody></MessageBar>}
      {data.cards && <Cards items={data.cards.map((c) => ({ label: c.label, value: c.value, tone: c.tone ? TONE[c.tone] : undefined }))} />}
      {data.prompt ? <section className={s.panel} aria-label={data.title}><Text className={s.muted}>{data.prompt}</Text></section> : data.tables.map((t, i) => <ReportTable key={i} table={t} caption={data.tables.length > 1} empty={data.empty} onOpen={open} />)}
      {data.notes.map((n, i) => <Text key={i} block size={200} className={s.muted} style={{ marginBottom: 6 }}>{n}</Text>)}
      {sharing && data.share && (
        <Dialog open onOpenChange={(_, d) => !d.open && setSharing(false)}>
          <DialogSurface style={{ maxWidth: 520 }}>
            <DialogBody>
              <DialogTitle>Share head office costs for {data.share.month}</DialogTitle>
              <DialogContent>{data.share.text}</DialogContent>
              <DialogActions>
                <Button onClick={() => setSharing(false)}>Cancel</Button>
                {data.share.can && <Button appearance="primary" disabled={busy} onClick={async () => {
                  setBusy(true)
                  try { const res = await api.shareHeadOffice(data.to); setDone(res.message); setSharing(false); setNonce((n) => n + 1) } catch (e) { setError(e instanceof Error ? e.message : String(e)); setSharing(false) }
                  setBusy(false)
                }}>Post the journal</Button>}
              </DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  )
}

import { Button, Checkbox, Dropdown, Field, Link, MessageBar, MessageBarBody, Option, ProgressBar, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow,
  Text } from '@fluentui/react-components'
import { ArrowUploadRegular, DocumentArrowDownRegular, SearchRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type ImportPageData, type ImportResult, type ImportUpload } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'

/**
 * System → Import data (part 24), as the current page: 1. what you are importing (and from where, for which industry,
 * as of which date, into which count or adjustment), 2. the template, the column guide or your current data to edit,
 * 3. upload the file (its first rows, and match any column not found under its own name), Check file (nothing is saved)
 * or Import — a large file runs in the background and its progress shows here — then the result, the error report and
 * the history. Type, count or adjustment, and the import shown, live in the address.
 */
export function ImportDataPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<ImportPageData | null>(null)
  const [error, setError] = useState('')
  const [o, setO] = useState({ source: 'excel', industry: '', as_of: '', warehouse_id: '', reason_code: 'correction', category_id: '', skip_errors: false, create_missing: true, allow_same_name: false })
  const [up, setUp] = useState<ImportUpload | null>(null)
  const [mapping, setMapping] = useState<Record<string, string>>({})
  const [uploading, setUploading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [duplicate, setDuplicate] = useState('')
  const [again, setAgain] = useState(false)
  const [result, setResult] = useState<ImportResult | null>(null)
  const [confirm, setConfirm] = useState<'import' | { id: string; skip: boolean } | null>(null)
  const [fileKey, setFileKey] = useState(0)
  const type = str(params, 'type')
  const context = str(params, 'context')
  const show = str(params, 'show')

  useEffect(() => {
    api.importPage(type).then((x) => {
      setD(x)
      setO((p) => ({ ...p, industry: p.industry || x.industry, as_of: p.as_of || x.as_of, warehouse_id: p.warehouse_id || x.warehouses[0]?.id || '' }))
      if (x.type !== type) set({ type: x.type })
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [type]) // eslint-disable-line react-hooks/exhaustive-deps
  const follow = useCallback((id: string) => {
    api.importShow(id).then((x) => {
      setResult(x.import)
      if (!x.import.running && x.message) setNote({ good: x.import.status === 'imported' || (x.import.status === 'checked' && !x.import.errors), text: x.message })
    }).catch(() => undefined)
  }, [])
  useEffect(() => { if (show) follow(show); else setResult(null) }, [show, follow])
  useEffect(() => { // a background import: follow its progress every 2 seconds until it is done
    if (!result?.running) return
    const t = setInterval(() => follow(result.id), 2000)
    return () => clearInterval(t)
  }, [result?.running, result?.id, follow])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the import…" /></div>
  const q = new URLSearchParams({ type: d.type, context, source: o.source, industry: o.industry, warehouse_id: o.warehouse_id, category_id: o.category_id, as_of: o.as_of }).toString()
  const dl = (what: string, format: string) => `/office-api/system/import/download/${what}?${q}&format=${format}`
  const reset = () => { setUp(null); setMapping({}); setDuplicate(''); setAgain(false); setFileKey((k) => k + 1) }
  const choose = (file: File | null) => {
    reset()
    setNote(null)
    if (!file) return
    setUploading(true)
    api.importUpload(d.type, o.source, file).then((x) => { setUp(x); setMapping(x.mapping) }).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) }))
      .finally(() => setUploading(false))
  }
  const run = (commit: boolean) => {
    if (!up) return
    setBusy(true)
    setNote(null)
    api.importRun({ type: d.type, token: up.token, commit, mapping, context: context || null, import_again: again, ...o }).then((x) => {
      if (x.duplicate) { setDuplicate(x.duplicate); return }
      if (x.import) { setResult(x.import); set({ show: x.import.id }) }
      if (x.message) setNote({ good: x.import?.status === 'imported' || x.import?.running || (x.import?.status === 'checked' && !x.import.errors), text: x.message })
      if (x.import && (x.import.status === 'imported' || x.import.running)) reset() // done: the page starts clean, so the same file cannot be posted twice by mistake
    }).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) })).finally(() => setBusy(false))
  }
  const importChecked = (id: string, skip: boolean) => api.importChecked(id, skip).then((x) => {
    setResult(x.import)
    set({ show: x.import.id })
    setNote({ good: x.import.status === 'imported' || x.import.running, text: x.message })
    setConfirm(null)
  })
  const unmatched = Object.keys(mapping)
  const pct = result?.progress && result.progress.total ? Math.min(1, result.progress.done / result.progress.total) : 0
  return (
    <div className={page.page}>
      <PageHead title="Import data (CSV / Excel)" hint="Pick what to import, download the template, upload, Check file (nothing is saved), then Import. Every row goes through the same checks as the screens."
        classic={`/admin/import-data?type=${d.type}`} />
      {note && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <Panel title="1. What are you importing?">
        <div className={s.grid} style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 240px), 1fr))' }}>
          <Choose label="Import" required options={d.types} value={d.type} onChange={(x) => { reset(); set({ type: x, context: '', show: '' }) }} />
          <Choose label="Coming from" required options={d.sources} value={o.source} onChange={(x) => { setO({ ...o, source: x }); reset() }} />
          {d.asks_industry && <Choose label="For which industry?" required options={d.industries} value={o.industry} onChange={(x) => setO({ ...o, industry: x })} />}
          {d.asks_as_of && <TextField label="Balances as of (go-live date)" type="date" value={o.as_of} onChange={(x) => setO({ ...o, as_of: x })} />}
          {d.context === 'stock_count' && <Choose label="Stock count" required options={d.contexts} value={context} onChange={(x) => set({ context: x })} placeholder="Choose an open count…" />}
          {d.context === 'stock_adjustment' && <>
            <Choose label="Into" options={d.contexts} value={context} onChange={(x) => set({ context: x })} placeholder="A new adjustment…" clearable />
            {!context && <>
              <Choose label="Warehouse" required options={d.warehouses} value={o.warehouse_id} onChange={(x) => setO({ ...o, warehouse_id: x })} />
              <Choose label="Reason" required options={d.reasons} value={o.reason_code} onChange={(x) => setO({ ...o, reason_code: x })} />
              <Choose label="Category on the stock sheet" options={d.categories} value={o.category_id} onChange={(x) => setO({ ...o, category_id: x })} placeholder="All products" clearable />
            </>}
          </>}
        </div>
        <Text block className={s.muted} style={{ marginTop: 8 }}>{d.description}</Text>
      </Panel>

      <Panel title="2. Get the file ready" about="Start from the template, or from your current data, and fill it in Excel or Google Sheets. Upload it as Excel (.xlsx) or CSV. Use the Excel download when the file has barcodes or codes that start with 0: Excel keeps them as they are.">
        <div className={s.actions}>
          <Button as="a" href={dl('template', 'xlsx')} icon={<DocumentArrowDownRegular />}>Download template (Excel)</Button>
          <Button as="a" href={dl('template', 'csv')}>Template (CSV)</Button>
          <Button as="a" href={dl('guide', 'pdf')} target="_blank" rel="noopener">Column guide (PDF)</Button>
          {d.current === 'data' && <><Button as="a" href={dl('current', 'xlsx')}>Download current data to edit (Excel)</Button><Button as="a" href={dl('current', 'csv')}>Current data (CSV)</Button></>}
          {d.current === 'stock_adjustment' && <><Button as="a" href={dl('current', 'csv')}>Stock sheet — current stock standing (CSV)</Button><Button as="a" href={dl('current', 'pdf')} target="_blank" rel="noopener">Stock sheet (PDF)</Button></>}
          {d.current === 'stock_count' && <><Button as="a" href={dl('current', 'csv')} disabled={!context}>Count sheet (CSV)</Button><Button as="a" href={dl('current', 'pdf')} target="_blank" rel="noopener" disabled={!context}>Count sheet (PDF)</Button></>}
        </div>
        <details style={{ marginTop: 10 }}><summary>Columns in this file</summary>
          <Table size="small" aria-label="Columns"><TableHeader><TableRow><TableHeaderCell>Column</TableHeaderCell><TableHeaderCell>Required</TableHeaderCell><TableHeaderCell>What to put in it</TableHeaderCell><TableHeaderCell>Example</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.columns.map((c) => <TableRow key={c.name}><TableCell><Text font="monospace">{c.name}</Text></TableCell><TableCell>{c.required}</TableCell><TableCell>{c.help}</TableCell><TableCell>{c.example}</TableCell></TableRow>)}</TableBody></Table>
        </details>
      </Panel>

      <Panel title="3. Upload, check, import">
        <Field label="File (Excel .xlsx or CSV)">
          <input key={fileKey} type="file" accept=".csv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(e) => choose(e.target.files?.[0] ?? null)} />
        </Field>
        {uploading && <Spinner size="tiny" label="Uploading…" />}
        {up && <>
          {unmatched.length > 0 && (
            <div style={{ margin: '10px 0' }}>
              <Text block><b>Match columns:</b> these columns were not found under their own name. Choose which of your columns holds each one (or leave it empty).</Text>
              <div className={s.grid} style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 220px), 1fr))', marginTop: 6 }}>
                {unmatched.map((col) => (
                  <Field key={col} label={col}>
                    <Dropdown aria-label={`Column for ${col}`} value={mapping[col] || '— not in my file —'} selectedOptions={[mapping[col] ?? '']} onOptionSelect={(_, e) => setMapping({ ...mapping, [col]: e.optionValue ?? '' })}>
                      <Option value="">— not in my file —</Option>{up.headings.map((h) => <Option key={h} value={h}>{h}</Option>)}
                    </Dropdown>
                  </Field>
                ))}
              </div>
            </div>
          )}
          <details open style={{ marginTop: 8 }}><summary>Preview: first {up.rows.length} of {up.total} row(s) of {up.file}</summary>
            <div style={{ overflowX: 'auto' }}><Table size="small" aria-label="Preview"><TableHeader><TableRow>{up.headings.map((h) => <TableHeaderCell key={h}>{h}</TableHeaderCell>)}</TableRow></TableHeader>
              <TableBody>{up.rows.map((r, i) => <TableRow key={i}>{up.headings.map((h) => <TableCell key={h}>{r[h] ?? ''}</TableCell>)}</TableRow>)}</TableBody></Table></div>
          </details>
        </>}
        <div style={{ display: 'grid', gap: 2, margin: '10px 0' }}>
          <Checkbox checked={o.skip_errors} onChange={(_, e) => setO({ ...o, skip_errors: !!e.checked })} label="Skip rows with errors (import the good rows; otherwise nothing is imported while any row has an error)" />
          {d.products && <Checkbox checked={o.create_missing} onChange={(_, e) => setO({ ...o, create_missing: !!e.checked })} label="Create missing categories and brands" />}
          {d.new_products && <Checkbox checked={o.allow_same_name} onChange={(_, e) => setO({ ...o, allow_same_name: !!e.checked })} label="Allow names that already exist (only for genuinely different products with the same name)" />}
        </div>
        {duplicate && <MessageBar intent="warning" style={{ marginBottom: 8 }}><MessageBarBody>{duplicate}
          <Checkbox checked={again} onChange={(_, e) => setAgain(!!e.checked)} label="Import it again (I really mean to add it a second time)" /></MessageBarBody></MessageBar>}
        <div className={s.actions}>
          <Button icon={<SearchRegular />} disabled={!up || busy || (d.asks_industry && !o.industry)} onClick={() => run(false)}>{busy ? 'Working…' : 'Check file (nothing is saved)'}</Button>
          <Button appearance="primary" icon={<ArrowUploadRegular />} disabled={!up || busy || (d.asks_industry && !o.industry) || (!!duplicate && !again)} onClick={() => setConfirm('import')}>Import</Button>
        </div>

        {result && (
          <div style={{ marginTop: 14 }} aria-label="Import result" role="region">
            {result.running && result.progress ? <>
              <Text block><b>{result.status_label}</b> — {result.progress.done ? result.progress.label : `${result.file}: ${result.progress.label}`}
                {result.progress.done ? ` — ${result.progress.done.toLocaleString('en-GB')} of ${result.progress.total.toLocaleString('en-GB')} (${Math.floor(pct * 100)}%)` : ''}</Text>
              <ProgressBar value={pct} style={{ margin: '8px 0' }} />
              <Text size={200} className={s.muted}>{result.progress.left ? `${result.progress.left}. ` : ''}It runs on the server: you can keep working or close this page, and you get a notification when it is done.</Text>
            </> : <>
              <Text block><b>{result.status_label}</b> — {result.file}: {result.total} row(s){result.counts.length ? `, ${result.counts.join(', ')}` : ''},{' '}
                <span style={{ color: result.errors ? 'var(--zx-bad)' : undefined }}>{result.errors} with errors</span>.</Text>
              {result.ignored.length > 0 && <Text block size={200} className={s.muted}>Columns not used: {result.ignored.join(', ')}</Text>}
              <div className={s.actions} style={{ marginTop: 8 }}>
                {result.errors > 0 && <Button size="small" as="a" href={`/office-api/system/import/${result.id}/errors`}>Download error report (CSV)</Button>}
                {result.status === 'checked' && !result.errors && <Button size="small" appearance="primary" onClick={() => setConfirm({ id: result.id, skip: false })}>Import this file</Button>}
                {result.status === 'checked' && result.errors > 0 && result.errors < result.total && <Button size="small" onClick={() => setConfirm({ id: result.id, skip: true })}>Import, skipping the {result.errors} row(s) with errors</Button>}
                {result.status === 'checked' && <Text size={200} className={s.muted}>Uses the file already on the server, as checked: no need to upload it again.</Text>}
                {result.link && <Link onClick={() => navigate(result.link!.open)}>{result.link.label} →</Link>}
              </div>
              {result.results.length > 0 && (
                <div style={{ overflowX: 'auto', marginTop: 8 }}><Table size="small" aria-label="Rows">
                  <TableHeader><TableRow><TableHeaderCell>Row</TableHeaderCell><TableHeaderCell>Record</TableHeaderCell><TableHeaderCell>Result</TableHeaderCell><TableHeaderCell>Message</TableHeaderCell></TableRow></TableHeader>
                  <TableBody>{result.results.map((x, i) => <TableRow key={i} style={x.outcome === 'error' ? { background: 'rgb(239 68 68 / .12)' } : undefined}>
                    <TableCell>{x.row}</TableCell><TableCell>{x.ref}</TableCell><TableCell>{x.outcome}</TableCell><TableCell>{x.message}</TableCell></TableRow>)}</TableBody>
                </Table></div>
              )}
            </>}
          </div>
        )}
      </Panel>

      <Panel title="Import history">
        <div style={{ overflowX: 'auto' }}><Table size="small" aria-label="Import history" style={{ minWidth: 720 }}>
          <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Who</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>File</TableHeaderCell>
            <TableHeaderCell>Result</TableHeaderCell><TableHeaderCell>Rows</TableHeaderCell><TableHeaderCell>Errors</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.history.length === 0 && <TableRow><TableCell colSpan={8}><Text className={s.muted}>No imports yet.</Text></TableCell></TableRow>}
            {d.history.map((h) => (
              <TableRow key={h.id}><TableCell>{h.when}</TableCell><TableCell>{h.who}</TableCell><TableCell>{h.what}</TableCell><TableCell>{h.file}</TableCell><TableCell>{h.status}</TableCell>
                <TableCell>{h.rows}</TableCell><TableCell>{h.errors}</TableCell>
                <TableCell><div className={s.actions}>
                  {h.errors > 0 && <Button size="small" as="a" href={`/office-api/system/import/${h.id}/errors`}>Error report</Button>}
                  {h.checked && <Button size="small" onClick={() => set({ show: h.id })}>Open</Button>}
                </div></TableCell></TableRow>
            ))}
          </TableBody>
        </Table></div>
      </Panel>

      {confirm && (
        <Ask title="Import this file now?" go={typeof confirm === 'object' && confirm.skip ? 'Import the good rows' : 'Import'} ready onClose={() => setConfirm(null)}
          run={async () => { if (confirm === 'import') { setConfirm(null); run(true) } else await importChecked(confirm.id, confirm.skip) }}>
          <Text>{typeof confirm === 'object' && confirm.skip && result ? `Import the ${result.total - result.errors} good row(s) and skip the ${result.errors} with errors? ` : ''}
            Every row is recorded under your name in the audit log.</Text>
        </Ask>
      )}
    </div>
  )
}

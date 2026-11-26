import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { AiRequestDetail, AiUsageList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { Fact, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = AiUsageList['rows'][number]
const STATUS = [{ id: 'ok', label: 'OK' }, { id: 'error', label: 'Error' }]
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
const pre = { whiteSpace: 'pre-wrap' as const, overflowWrap: 'anywhere' as const, fontSize: tokens.fontSizeBase200, margin: '4px 0 10px' }

/**
 * Insights → AI usage log (part 29): every call to an AI model — who, what was sent (masked, as the model saw it), the
 * answer, tokens and cost — with this month's cloud spend against the budget. Read-only; a row opens the whole request.
 */
export function AiUsagePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.aiUsage, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [one, setOne] = useState<AiRequestDetail | null>(null)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="AI usage log"
        hint={data ? `Cloud AI spent this month: ${data.spent} of ${data.cap} budget. Local models cost nothing.` : 'Every call to an AI model, with its cost.'} />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Who or question" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search the AI usage log" />
        <Pick label="Feature" any="Any feature" options={data?.features ?? []} value={str(params, 'feature')} onChange={(v) => set({ feature: v })} />
        <Pick label="Status" any="Any status" options={STATUS} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="AI usage log" rows={data?.rows ?? []} sort="when" dir="desc" loading={loading} empty="No AI requests yet." onSort={() => undefined}
        onOpen={(r) => api.aiRequest(r.id).then(setOne).catch(() => undefined)}
        columns={[
          { key: 'when', label: 'When', render: (r) => when(r.when) },
          { key: 'who', label: 'Who', render: (r) => r.who },
          { key: 'feature', label: 'Feature', render: (r) => r.feature },
          { key: 'model', label: 'Model', render: (r) => r.model },
          { key: 'question', label: 'Question', render: (r) => r.question },
          { key: 'cost', label: 'Cost', end: true, render: (r) => r.cost },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.status === 'ok' ? 'success' : 'danger'} label={r.status} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {one && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setOne(null) }}>
          <DialogSurface style={{ maxWidth: 820 }}><DialogBody>
            <DialogTitle>AI request</DialogTitle>
            <DialogContent>
              <div className={s.facts} style={{ marginBottom: 12 }}>
                <Fact label="When">{when(one.when)}</Fact>
                <Fact label="Who">{one.who}</Fact>
                <Fact label="Feature">{one.feature}</Fact>
                <Fact label="Model">{one.model}</Fact>
                <Fact label="Tokens in / out">{one.tokens}</Fact>
                <Fact label="Cost">{one.cost}</Fact>
                <Fact label="Status">{one.status}{one.error && <Text size={200} block className={s.muted}>{one.error}</Text>}</Fact>
                <Fact label="Took">{one.took}</Fact>
              </div>
              <Text weight="semibold" block>Sent (as the model saw it)</Text>
              <div style={pre}>{one.prompt}</div>
              <Text weight="semibold" block>Answer</Text>
              <div style={pre}>{one.response ?? '—'}</div>
              <Text weight="semibold" block>Reports used</Text>
              <div style={pre}>{one.tools.length ? one.tools.join('\n') : '—'}</div>
            </DialogContent>
            <DialogActions><Button onClick={() => setOne(null)}>Close</Button></DialogActions>
          </DialogBody></DialogSurface>
        </Dialog>
      )}
    </div>
  )
}

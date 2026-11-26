import { Badge, Button, Dropdown, Field, makeStyles, MessageBar, MessageBarBody, Option, Text, Textarea, tokens } from '@fluentui/react-components'
import { CheckmarkRegular, ReceiptRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type ReviewRow } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px 12px', flexWrap: 'wrap', marginBottom: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '6px', justifyContent: 'flex-end', flexWrap: 'wrap' },
})

const SHOW = [['open', 'Open'], ['resolved', 'Resolved'], ['all', 'All']]

/**
 * The review queue (part 16): offline and sync situations the system must not decide alone — a price sold offline, a
 * sale after its shift was counted, stock below zero after syncing… Open the sale behind one; Resolve says what was
 * checked or done.
 */
export function ReviewQueuePage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.reviewQueue, serialize(params), nonce)
  const [resolving, setResolving] = useState<ReviewRow | null>(null)
  const [text, setText] = useState('')
  const [done, setDone] = useState('')
  const type = str(params, 'type')
  const columns: Column<ReviewRow>[] = [
    { key: 'at', label: 'When', render: (r) => when(r.at) },
    { key: 'type', label: 'Type', render: (r) => <Badge appearance="tint" color={r.mild ? 'warning' : 'danger'}>{r.type_label}</Badge> },
    { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
    { key: 'summary', label: 'Summary', render: (r) => r.summary },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.status === 'open' ? 'warning' : 'success'}>{r.status === 'open' ? 'Open' : 'Resolved'}</Badge> },
    { key: 'resolution', label: 'Resolution', render: (r) => (r.resolution ? <>{r.resolution}<Text block size={200} className={s.muted}>{r.resolved}</Text></> : '—') },
    { key: 'acts', label: '', render: (r) => (
      <div className={s.acts}>
        {r.sale && <Button size="small" icon={<ReceiptRegular />} onClick={(e) => { e.stopPropagation(); navigate(r.sale!) }}>Sale</Button>}
        {r.status === 'open' && <Button size="small" appearance="primary" icon={<CheckmarkRegular />} onClick={(e) => { e.stopPropagation(); setDone(''); setText(''); setResolving(r) }}>Resolve</Button>}
      </div>
    ) },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Review queue" hint="Offline and sync situations a person must decide. Check each one, then say what you checked or did." />
      <div className={s.bar}>
        <Field label="Show" size="small">
          <Dropdown size="small" style={{ minWidth: 130 }} value={SHOW.find((x) => x[0] === (data?.status ?? 'open'))?.[1] ?? ''} selectedOptions={[data?.status ?? 'open']}
            onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
            {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Type" size="small">
          <Dropdown size="small" style={{ minWidth: 220 }} value={type ? data?.types.find((x) => x.id === type)?.label ?? '' : 'Any type'} selectedOptions={[type]}
            onOptionSelect={(_, d) => set({ type: d.optionValue ?? '' })}>
            <Option value="">Any type</Option>{(data?.types ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          </Dropdown>
        </Field>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Review queue" columns={columns} rows={data?.rows ?? []} sort="at" dir="desc" loading={loading}
        empty="Nothing to review here." onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {resolving && (
        <Ask title="Resolve" go="Resolve" ready={text.trim() !== ''} onClose={() => setResolving(null)}
          run={async () => { const r = await api.resolveReview(resolving.id, text); setResolving(null); setDone(r.message); setNonce((n) => n + 1) }}>
          <Text block style={{ marginBottom: 8 }}>{resolving.summary}</Text>
          <Field label="What did you check or do?" required>
            <Textarea maxLength={255} value={text} placeholder="e.g. Price change was announced late; accepted. / Counted 3 left; adjustment posted." onChange={(_, d) => setText(d.value)} />
          </Field>
        </Ask>
      )}
    </div>
  )
}

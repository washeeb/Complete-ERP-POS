import { Button, Input, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text,
  tokens } from '@fluentui/react-components'
import { ArrowResetRegular, SendRegular } from '@fluentui/react-icons'
import { type FormEvent, useEffect, useRef, useState } from 'react'
import { api, type AnswerTable, type AskData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  quick: { display: 'flex', flexWrap: 'wrap', gap: '8px', marginBottom: '12px' },
  chat: { display: 'grid', gap: '12px', marginBottom: '12px' },
  user: { justifySelf: 'end', maxWidth: '80%', padding: '8px 12px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorBrandBackground2,
    fontWeight: tokens.fontWeightSemibold, overflowWrap: 'anywhere' },
  assistant: { padding: '10px 12px', borderRadius: tokens.borderRadiusLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, backgroundColor: tokens.colorNeutralBackground1,
    overflowX: 'auto', minWidth: 0 },
  text: { whiteSpace: 'pre-wrap', lineHeight: '1.5', marginBottom: '6px', overflowWrap: 'anywhere' },
  notice: { display: 'block', color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200, marginBottom: '6px' },
  table: { marginTop: '8px' },
  cell: { position: 'relative' },
  bar: { position: 'absolute', left: 0, top: '20%', height: '60%', backgroundColor: tokens.colorBrandBackground2, borderRadius: '3px', zIndex: 0 },
  value: { position: 'relative', zIndex: 1 },
  ask: { display: 'flex', gap: '8px', alignItems: 'center', position: 'sticky', bottom: 0, padding: '10px 0', backgroundColor: tokens.colorNeutralBackground2 },
  input: { flex: '1 1 auto', minWidth: 0 },
})

type Msg = { role: 'user' | 'assistant'; content: string; tables?: AnswerTable[]; notice?: string | null }

function Answer({ t }: { t: AnswerTable }) {
  const c = useStyles()
  const s = useProcStyles()
  const share = t.columns.indexOf('Share')
  return (
    <div className={c.table}>
      <Text weight="semibold" block>{t.title}</Text>
      <Table size="small" aria-label={t.title}>
        <TableHeader><TableRow>{t.columns.map((h) => <TableHeaderCell key={h}>{h}</TableHeaderCell>)}</TableRow></TableHeader>
        <TableBody>
          {t.rows.length === 0 && <TableRow><TableCell colSpan={t.columns.length}><Text className={s.muted}>Nothing to show.</Text></TableCell></TableRow>}
          {t.rows.map((row, i) => (
            <TableRow key={i}>{row.map((v, j) => (
              <TableCell key={j} className={c.cell}>
                {j === share && <span className={c.bar} style={{ width: `${Math.min(100, parseFloat(String(v)) || 0)}%` }} />}
                <span className={c.value}>{v ?? ''}</span>
              </TableCell>
            ))}</TableRow>
          ))}
        </TableBody>
      </Table>
      {t.note && <Text size={200} block className={s.muted}>{t.note}</Text>}
    </div>
  )
}

/**
 * Insights → Ask your business (part 29): questions answered from the reports the person may already see, showing the
 * figures they came from. The quick questions always work, even without AI; a typed question goes to the AI with the
 * chat so far. New chat starts again.
 */
export function AskPage() {
  const page = usePageStyles()
  const c = useStyles()
  const s = useProcStyles()
  const [d, setD] = useState<AskData | null>(null)
  const [error, setError] = useState('')
  const [chat, setChat] = useState<Msg[]>([])
  const [question, setQuestion] = useState('')
  const [busy, setBusy] = useState(false)
  const end = useRef<HTMLDivElement>(null)
  useEffect(() => { api.askBusiness().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  useEffect(() => { end.current?.scrollIntoView?.({ behavior: 'smooth', block: 'end' }) }, [chat, busy])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening…" /></div>
  const run = (label: string, call: Promise<{ content: string; tables: AnswerTable[]; notice: string | null }>) => {
    setChat((o) => [...o, { role: 'user', content: label }])
    setBusy(true)
    call.then((r) => setChat((o) => [...o, { role: 'assistant', content: r.content, tables: r.tables, notice: r.notice }]))
      .catch((e: unknown) => setChat((o) => [...o, { role: 'assistant', content: '', notice: e instanceof Error ? e.message : String(e) }]))
      .finally(() => setBusy(false))
  }
  const ask = (e: FormEvent) => {
    e.preventDefault()
    const q = question.trim()
    if (!q || busy) return
    const history = chat.filter((m) => m.content !== '').map((m) => ({ role: m.role, content: m.content }))
    setQuestion('')
    run(q, api.askQuestion(q, history))
  }
  return (
    <div className={page.page}>
      <PageHead title="Ask your business" hint="Answers use only what you are allowed to see, and show the figures they came from." classic="/admin/copilot" />
      {d.status && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{d.status} The quick questions below always work.</MessageBarBody></MessageBar>}
      <div className={c.quick} role="group" aria-label="Quick questions">
        {d.quick.map((q) => <Button key={q.id} size="small" disabled={busy} onClick={() => run(q.label, api.askQuick(q.id))}>{q.label}</Button>)}
      </div>
      <div className={c.chat} aria-live="polite">
        {chat.length === 0 && <Text className={s.muted}>Ask about sales, stock, customers, suppliers or cash — for example “Which branch sold most this month?” or “What will run out at HQ this week?”.</Text>}
        {chat.map((m, i) => m.role === 'user'
          ? <div key={i} className={c.user}>{m.content}</div>
          : (
            <div key={i} className={c.assistant}>
              {m.content !== '' && <div className={c.text}>{m.content}</div>}
              {m.notice && <span className={c.notice}>{m.notice}</span>}
              {(m.tables ?? []).map((t, j) => <Answer key={j} t={t} />)}
            </div>
          ))}
        {busy && <Spinner size="tiny" label="Working…" labelPosition="after" />}
        <div ref={end} />
      </div>
      <form className={c.ask} onSubmit={ask}>
        <Input className={c.input} aria-label="Your question" placeholder="Ask a question about the business…" maxLength={1000} value={question} onChange={(_, e) => setQuestion(e.value)} />
        <Button type="submit" appearance="primary" icon={<SendRegular />} disabled={busy || question.trim() === ''}>Ask</Button>
        {chat.length > 0 && <Button icon={<ArrowResetRegular />} disabled={busy} onClick={() => setChat([])}>New chat</Button>}
      </form>
    </div>
  )
}

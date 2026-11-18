import {
  Badge, Button, CounterBadge, Field, makeStyles, mergeClasses,
  MessageBar, MessageBarBody, Popover, PopoverSurface, PopoverTrigger, Spinner, Tab, TabList, Text, Textarea, tokens,
} from '@fluentui/react-components'
import { ArrowClockwiseRegular, CheckmarkRegular, DismissRegular, OpenRegular, TaskListSquareLtrRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, type Todo, type TodoGroup, type TodoItem } from '../api'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { money } from '../home/format'
import { linkClick, navigate } from '../nav'
import { appPathFor } from '../paths'

const useStyles = makeStyles({
  page: { flex: '1 1 auto', overflowY: 'auto', padding: '16px 20px 32px', '@media (max-width: 760px)': { padding: '12px 10px 24px' } },
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  tabs: { marginBottom: '14px', overflowX: 'auto' },
  group: {
    backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, boxShadow: tokens.shadow4,
    border: `1px solid ${tokens.colorNeutralStroke2}`, marginBottom: '16px', overflow: 'hidden',
  },
  groupHead: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', padding: '12px 16px', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, flexWrap: 'wrap' },
  groupTitle: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: 0 },
  row: {
    display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', gap: '6px 16px', alignItems: 'center', padding: '12px 16px',
    borderBottom: `1px solid ${tokens.colorNeutralStroke3}`, ':last-child': { borderBottom: 'none' },
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover },
    '@media (max-width: 760px)': { gridTemplateColumns: '1fr' },
  },
  urgent: { borderLeft: '4px solid var(--zx-s2)' },
  itemTitle: { fontWeight: tokens.fontWeightSemibold, color: tokens.colorNeutralForeground1, textDecorationLine: 'none' },
  meta: { display: 'flex', flexWrap: 'wrap', gap: '4px 12px', color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200, marginTop: '2px' },
  lines: { margin: '6px 0 0', paddingLeft: '18px', fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground2 },
  actions: { display: 'flex', gap: '8px', alignItems: 'center', justifyContent: 'flex-end', flexWrap: 'wrap' },
  amount: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums', color: tokens.colorNeutralForeground1 },
  empty: { display: 'grid', placeItems: 'center', gap: '8px', padding: '48px 16px', textAlign: 'center', color: tokens.colorNeutralForeground3 },
  emptyIcon: { fontSize: '48px', color: 'var(--zx-good)' },
  more: { padding: '10px 16px', borderTop: `1px solid ${tokens.colorNeutralStroke3}` },
})

function ago(iso: string): string {
  const t = Date.parse(iso)
  if (!Number.isFinite(t)) return ''
  const m = Math.round((Date.now() - t) / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return `${m} min ago`
  const h = Math.round(m / 60)
  if (h < 48) return `${h} h ago`
  return `${Math.round(h / 24)} days ago`
}

function left(iso: string | null): string | null {
  if (!iso) return null
  const m = Math.ceil((Date.parse(iso) - Date.now()) / 60000)
  return m > 0 ? `answer within ${m} min` : 'running out'
}

/**
 * To-do (release 1 of the new back office): everything waiting for this person's decision in one list. Till requests
 * come first and are answered right here (a cashier is waiting); every other item opens its page to decide there.
 * It refreshes by itself; the menu shows how many are waiting.
 */
export function TodoPage({ onCount }: { onCount: (n: number) => void }) {
  const s = useStyles()
  const [todo, setTodo] = useState<Todo | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState('all')
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const [retry, setRetry] = useState<{ id: string } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState('')

  const load = useCallback(() => {
    api.todo().then((t) => { setTodo(t); setError(''); onCount(t.total) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [onCount])

  useEffect(() => {
    load()
    const t = setInterval(load, 15000) // a till request waits for an answer: keep the list fresh
    return () => clearInterval(t)
  }, [load])

  async function decide(item: TodoItem, yes: boolean, note?: string) {
    setBusy(item.id)
    setMessage('')
    try {
      const r = await api.decideTill(item.id, yes, note)
      setMessage(yes ? `Approved: ${item.title}. The till goes on.` : `Declined: ${item.title}.`)
      if (r) load()
    } catch (e) {
      if (e instanceof ApiError && e.code === 'reconfirm' && e.url) {
        setConfirmUrl(e.url)
        setRetry({ id: item.id })
      } else {
        setMessage(e instanceof Error ? e.message : String(e))
        load()
      }
    } finally {
      setBusy(null)
    }
  }

  if (error && !todo) return <div className={s.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!todo) return <div className={s.page}><Spinner label="Gathering what waits for you…" /></div>

  const groups = tab === 'all' ? todo.groups : todo.groups.filter((g) => g.key === tab)
  const money2 = (m: number) => money(m, todo.currency, todo.exponent)

  return (
    <div className={s.page}>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>To-do</h1>
          <Text className={s.muted}>{todo.total === 0 ? 'Nothing waits for your decision.' : `${todo.total} waiting for your decision.`}</Text>
        </div>
        <Button appearance="subtle" icon={<ArrowClockwiseRegular />} onClick={load}>Refresh</Button>
      </div>

      {todo.groups.length > 1 && (
        <TabList className={s.tabs} selectedValue={tab} onTabSelect={(_, d) => setTab(String(d.value))} size="small">
          <Tab value="all">All <CounterBadge count={todo.total} size="small" appearance="ghost" /></Tab>
          {todo.groups.map((g) => <Tab key={g.key} value={g.key}>{g.label} <CounterBadge count={g.count} size="small" appearance="ghost" /></Tab>)}
        </TabList>
      )}

      {message && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{message}</MessageBarBody></MessageBar>}

      {todo.groups.length === 0 && (
        <div className={mergeClasses(s.group, s.empty)}>
          <TaskListSquareLtrRegular className={s.emptyIcon} />
          <Text size={500} weight="semibold">All clear</Text>
          <Text>When an order, a return, a claim or a till waits for your yes, it shows here.</Text>
        </div>
      )}

      {groups.map((g) => <Group key={g.key} g={g} money={money2} busy={busy} onDecide={decide} />)}

      {confirmUrl && (
        <ConfirmItsYou url={confirmUrl} why="Approving at the till is protected." go="Approve now"
          onCancel={() => { setConfirmUrl(null); setRetry(null) }}
          onGo={() => {
            const item = todo.groups.flatMap((x) => x.items).find((i) => i.id === retry?.id)
            setConfirmUrl(null)
            setRetry(null)
            if (item) void decide(item, true)
          }} />
      )}
    </div>
  )
}

function Group({ g, money, busy, onDecide }: { g: TodoGroup; money: (m: number) => string; busy: string | null; onDecide: (i: TodoItem, yes: boolean, note?: string) => void }) {
  const s = useStyles()
  return (
    <section className={s.group} aria-labelledby={`todo-${g.key}`}>
      <div className={s.groupHead}>
        <div>
          <h2 id={`todo-${g.key}`} className={s.groupTitle}>{g.label} <CounterBadge count={g.count} color={g.inline ? 'danger' : 'brand'} /></h2>
          <Text size={200} className={s.muted}>{g.hint}</Text>
        </div>
        {g.url && <Button appearance="subtle" icon={<OpenRegular />} onClick={() => navigate(g.url!)}>Open the list</Button>}
      </div>
      {g.items.length === 0 && g.url && (
        <div className={s.more}><Button appearance="primary" onClick={() => navigate(g.url!)}>Open {g.label.toLowerCase()} ({g.count})</Button></div>
      )}
      {g.items.map((i) => (
        <div key={i.id} className={mergeClasses(s.row, g.inline && s.urgent)}>
          <div>
            <a className={s.itemTitle} href={appPathFor(i.url)} onClick={(e) => linkClick(e, i.url)}>{i.title}</a>
            {i.detail && <Text block size={300}>{i.detail}</Text>}
            {i.lines.length > 0 && <ul className={s.lines}>{i.lines.map((l, n) => <li key={n}>{l}</li>)}</ul>}
            <div className={s.meta}>
              {i.who && <span>{i.who}</span>}
              {i.where && <span>{i.where}</span>}
              {i.at && <span>{ago(i.at)}</span>}
              {g.inline && left(i.expires_at) && <Badge appearance="tint" color="warning" size="small">{left(i.expires_at)}</Badge>}
            </div>
          </div>
          <div className={s.actions}>
            {i.amount !== null && <span className={s.amount}>{money(i.amount)}</span>}
            {i.percent !== null && <span className={s.amount}>{i.percent.toFixed(2)} %</span>}
            {g.inline ? (
              <>
                <Decline busy={busy === i.id} onDecline={(note) => onDecide(i, false, note)} />
                <Button appearance="primary" icon={<CheckmarkRegular />} disabled={busy === i.id} onClick={() => onDecide(i, true)}>
                  {busy === i.id ? 'Saving…' : 'Approve'}
                </Button>
              </>
            ) : <Button icon={<OpenRegular />} onClick={() => navigate(i.url)}>Open</Button>}
          </div>
        </div>
      ))}
      {g.items.length > 0 && g.count > g.items.length && g.url && (
        <div className={s.more}><Button appearance="transparent" onClick={() => navigate(g.url!)}>See all {g.count}</Button></div>
      )}
    </section>
  )
}

/** Decline asks why: the cashier sees it on the till. */
function Decline({ busy, onDecline }: { busy: boolean; onDecline: (note: string) => void }) {
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState('')
  return (
    <Popover open={open} onOpenChange={(_, d) => setOpen(d.open)} trapFocus positioning="below-end">
      <PopoverTrigger disableButtonEnhancement><Button icon={<DismissRegular />} disabled={busy}>Decline</Button></PopoverTrigger>
      <PopoverSurface style={{ width: 300 }}>
        <Field label="Why (the cashier sees it)" required>
          <Textarea value={note} onChange={(_, d) => setNote(d.value)} maxLength={255} />
        </Field>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
          <Button onClick={() => setOpen(false)}>Cancel</Button>
          <Button appearance="primary" disabled={!note.trim()} onClick={() => { setOpen(false); onDecline(note.trim()); setNote('') }}>Decline</Button>
        </div>
      </PopoverSurface>
    </Popover>
  )
}

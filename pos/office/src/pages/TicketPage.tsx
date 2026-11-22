import { Button, Checkbox, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowUndoRegular, ChatRegular, CheckmarkCircleRegular, EditRegular, PersonAddRegular, WrenchRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type TicketClock, type TicketDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  thread: { display: 'grid', gap: '10px' },
  msg: { maxWidth: '78%', padding: '8px 12px', borderRadius: tokens.borderRadiusLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' },
  in: { justifySelf: 'start', backgroundColor: tokens.colorNeutralBackground2 },
  out: { justifySelf: 'end', backgroundColor: tokens.colorBrandBackground2 },
  note: { justifySelf: 'stretch', maxWidth: 'none', backgroundColor: tokens.colorPaletteYellowBackground1 },
  head: { display: 'block', fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground3, marginBottom: '4px' },
})

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

function Clock({ c, done, waiting }: { c: TicketClock; done: string; waiting?: string }) {
  if (c.done) return <>{done} {when(c.at)}</>
  if (c.waiting && waiting) return <>{waiting}</>
  return <span style={c.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{when(c.at)}{c.late ? ' (late)' : ''}</span>
}

type Dialog = 'reply' | 'note' | 'assign' | 'work_order' | 'resolve' | 'reopen'

/**
 * One ticket (part 28), as the current page: status, priority, kind, how it came, who it is from, who has it, the time to
 * answer and to fix it (the clock stops while waiting for them), receipt and warranty, equipment, resolution and their
 * rating; the conversation (theirs on the left, ours on the right, staff notes across). Reply (WhatsApp, SMS or e-mail,
 * maybe waiting for their answer), Note, Pass on, Work order, Resolve (maybe telling them) and Reopen.
 */
export function TicketPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const c = useStyles()
  const [d, setD] = useState<TicketDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [f, setF] = useState({ channel: '', body: '', wait: false, assigned_to: '', priority: '', asset_id: '', resolution: '', tell: '' })
  const load = useCallback(() => {
    api.ticket(id).then((x) => { setD(x); onTitle(`${x.number} · ${x.subject}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the ticket…" /></div>
  const a = d.actions
  const show = (x: Dialog) => {
    setDone('')
    setF({ channel: d.channels[0]?.id ?? '', body: '', wait: false, assigned_to: d.assigned_to ?? d.me, priority: d.priority, asset_id: d.asset_id ?? '', resolution: '', tell: '' })
    setDialog(x)
  }
  const run = (action: Dialog, body: Record<string, unknown> = {}) => api.ticketAction(d.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    setDialog(null); setDone(x.message); load()
  })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/tickets'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Tickets</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.subject}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.reply && <Button icon={<ChatRegular />} onClick={() => show('reply')}>Reply</Button>}
          {a.note && <Button icon={<EditRegular />} onClick={() => show('note')}>Note</Button>}
          {a.assign && <Button icon={<PersonAddRegular />} onClick={() => show('assign')}>Pass on</Button>}
          {a.work_order && <Button icon={<WrenchRegular />} onClick={() => show('work_order')}>Work order</Button>}
          {a.resolve && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => show('resolve')}>Resolve</Button>}
          {a.reopen && <Button icon={<ArrowUndoRegular />} onClick={() => show('reopen')}>Reopen</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Ticket">
        <div className={s.facts}>
          <Fact label="Priority">{d.priority_label}</Fact>
          <Fact label="Kind">{d.kind}</Fact>
          <Fact label="Came by">{d.source}</Fact>
          <Fact label="From">{d.who ?? '—'}{d.contact && <Text size={200} block className={s.muted}>{d.contact}</Text>}</Fact>
          <Fact label="With">{d.with ?? 'Nobody yet'}</Fact>
          <Fact label="Answer by"><Clock c={d.answer} done="Answered" /></Fact>
          <Fact label="Fix by"><Clock c={d.fix} done="Resolved" waiting="Clock stopped: waiting for them" /></Fact>
          <Fact label="Receipt">{d.receipt ?? '—'}{d.warranty && <Text size={200} block className={s.muted}>{d.warranty}</Text>}</Fact>
          <Fact label="Equipment">{d.asset ?? '—'}</Fact>
          <Fact label="Their rating">{d.rating ? `${d.rating} / 5` : '—'}</Fact>
        </div>
        {d.resolution && <Text block style={{ marginTop: 10 }}><b>Resolution:</b> {d.resolution}</Text>}
      </Panel>
      <Panel title="Conversation">
        {d.messages.length === 0 ? <Text className={s.muted}>No messages yet.</Text> : (
          <div className={c.thread} role="list" aria-label="Conversation">
            {d.messages.map((m) => (
              <div key={m.id} role="listitem" className={`${c.msg} ${c[m.direction]}`}>
                <span className={c.head}><b>{m.direction === 'note' ? 'Note' : m.who}</b>{m.direction === 'note' && m.who ? ` · ${m.who}` : ''}{m.channel ? ` · ${m.channel}` : ''} · {when(m.at)}
                  {m.delivery ? ` · ${m.delivery}` : ''}</span>
                {m.body}
              </div>
            ))}
          </div>
        )}
      </Panel>

      {dialog === 'reply' && (
        <Ask title="Reply" go="Send" width={600} ready={!!f.channel && f.body.trim() !== ''} onClose={() => setDialog(null)} run={() => run('reply', { channel: f.channel, body: f.body, wait: f.wait })}>
          <div className={s.one}>
            <Text size={200} className={s.muted}>WhatsApp: a free message reaches them only within 24 hours of their last message; otherwise use SMS or e-mail.</Text>
            <Choose label="By" required options={d.channels} value={f.channel} onChange={(x) => setF({ ...f, channel: x })} />
            <LongText label="Message" maxLength={5000} value={f.body} onChange={(x) => setF({ ...f, body: x })} />
            <Checkbox label="Wait for their answer (the clock stops until they reply)" checked={f.wait} onChange={(_, e) => setF({ ...f, wait: !!e.checked })} />
          </div>
        </Ask>
      )}
      {dialog === 'note' && (
        <Ask title="Note" go="Save" ready={f.body.trim() !== ''} onClose={() => setDialog(null)} run={() => run('note', { body: f.body })}>
          <LongText label="Note (staff only)" maxLength={5000} value={f.body} onChange={(x) => setF({ ...f, body: x })} />
        </Ask>
      )}
      {dialog === 'assign' && (
        <Ask title="Pass on" go="Pass on" ready={!!f.assigned_to && !!f.priority} onClose={() => setDialog(null)} run={() => run('assign', { assigned_to: f.assigned_to, priority: f.priority })}>
          <div className={s.one}>
            <Choose label="Give it to" required options={d.people} value={f.assigned_to} onChange={(x) => setF({ ...f, assigned_to: x })} />
            <Choose label="Priority" required options={d.priorities} value={f.priority} onChange={(x) => setF({ ...f, priority: x })} hint="Deadlines follow the priority, from when it was opened." />
          </div>
        </Ask>
      )}
      {dialog === 'work_order' && (
        <Ask title="Open a work order" go="Open work order" ready={!!f.asset_id} onClose={() => setDialog(null)} run={() => run('work_order', { asset_id: f.asset_id })}>
          <Choose label="Equipment" required options={d.assets} value={f.asset_id} onChange={(x) => setF({ ...f, asset_id: x })} />
        </Ask>
      )}
      {dialog === 'resolve' && (
        <Ask title="Resolve" go="Resolve" width={600} ready={f.resolution.trim() !== ''} onClose={() => setDialog(null)} run={() => run('resolve', { resolution: f.resolution, tell: f.tell || null })}>
          <div className={s.one}>
            <LongText label="How it was resolved" maxLength={5000} value={f.resolution} onChange={(x) => setF({ ...f, resolution: x })} />
            {a.tell && <Choose label="Tell them (with “How did we do?”)" options={d.channels} value={f.tell} onChange={(x) => setF({ ...f, tell: x })} placeholder="Do not send" clearable />}
          </div>
        </Ask>
      )}
      {dialog === 'reopen' && (
        <Ask title="Reopen this ticket?" go="Reopen" ready onClose={() => setDialog(null)} run={() => run('reopen')}>
          <Text>It is open again, with its deadlines.</Text>
        </Ask>
      )}
    </div>
  )
}

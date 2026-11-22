import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, PersonRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type CrmPipelineData } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Choose } from '../form/Fields'
import { useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  sum: { display: 'flex', gap: '18px', flexWrap: 'wrap', marginBottom: '10px' },
  ends: { display: 'flex', gap: '10px', marginBottom: '10px' },
  end: { flex: '1 1 0', textAlign: 'center', padding: '10px', borderRadius: tokens.borderRadiusLarge, border: `2px dashed ${tokens.colorNeutralStroke2}`, fontWeight: tokens.fontWeightSemibold },
  board: { display: 'grid', gridAutoFlow: 'column', gridAutoColumns: 'minmax(240px, 1fr)', gap: '12px', overflowX: 'auto', paddingBottom: '8px' },
  col: { backgroundColor: tokens.colorNeutralBackground3, borderRadius: tokens.borderRadiusXLarge, padding: '10px', display: 'grid', gap: '8px', alignContent: 'start', minHeight: '160px' },
  over: { outline: `2px solid ${tokens.colorBrandStroke1}` },
  card: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, padding: '8px 10px', display: 'grid',
    gap: '2px', cursor: 'pointer', textAlign: 'left', boxShadow: tokens.shadow2 },
  meta: { display: 'flex', justifyContent: 'space-between', gap: '8px', fontSize: tokens.fontSizeBase200 },
})

/**
 * CRM → Pipeline (part 25): the open deals as cards in a column per stage, with each column's total and weighted value
 * (value × the stage's chance). Drag a card to another stage, or onto Won / Lost; on a phone, open the card and change
 * its stage — or use "Move to". "My deals only" shows the person's own.
 */
export function CrmPipelinePage() {
  const page = usePageStyles()
  const p = useProcStyles()
  const s = useStyles()
  const [mine, setMine] = useState(false)
  const [d, setD] = useState<CrmPipelineData | null>(null)
  const [error, setError] = useState('')
  const [note, setNote] = useState('')
  const [drag, setDrag] = useState<string | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const [lose, setLose] = useState<{ id: string; title: string } | null>(null)
  const [moving, setMoving] = useState<{ id: string; title: string; to: string } | null>(null)
  const [reason, setReason] = useState('')
  const load = useCallback(() => { api.crmPipeline(mine).then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [mine])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the pipeline…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const all = d.columns.flatMap((c) => c.deals)
  const move = (id: string, to: string) => {
    setNote('')
    if (to === 'lost') { setReason(''); setLose({ id, title: all.find((x) => x.id === id)?.title ?? '' }); return }
    const go = to === 'won' ? api.crmDealAction(id, 'win') : api.crmDealAction(id, 'move', { stage_id: to })
    go.then((x) => { if (x.message) setNote(x.message); load() }).catch((e: unknown) => { setNote(e instanceof Error ? e.message : String(e)); load() })
  }
  const drop = (to: string) => ({
    onDragOver: (e: React.DragEvent) => { if (d.can_move && drag) { e.preventDefault(); setOver(to) } },
    onDragLeave: () => setOver(null),
    onDrop: (e: React.DragEvent) => { e.preventDefault(); if (drag) move(drag, to); setDrag(null); setOver(null) },
  })
  const targets = [...d.columns.map((c) => ({ id: c.id, label: c.name })), { id: 'won', label: 'Won' }, { id: 'lost', label: 'Lost' }]
  return (
    <div className={page.page}>
      <PageHead title="Pipeline" hint="Drag a deal to its new stage, or onto Won or Lost. A deal is also won by itself when its quotation is confirmed." classic="/admin/crm-pipeline" />
      <div className={p.bar}>
        <span className={p.grow} />
        <Button icon={<PersonRegular />} onClick={() => setMine(!mine)}>{mine ? 'Everyone’s deals' : 'My deals only'}</Button>
        {d.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/crm/deals/new')}>New deal</Button>}
      </div>
      {note && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{note}</MessageBarBody></MessageBar>}
      <section aria-label="Pipeline">
        <div className={s.sum}>
          <Text>Open: <b>{m(d.open)}</b></Text><Text>Weighted: <b>{m(d.weighted)}</b></Text>
          <Text>Won this month: <b>{d.won}</b> ({m(d.won_value)})</Text><Text>Lost this month: <b>{d.lost}</b></Text>
        </div>
        {d.can_move && (
          <div className={s.ends}>
            <div className={`${s.end} ${over === 'won' ? s.over : ''}`} style={{ color: 'var(--zx-good)' }} {...drop('won')}>Won</div>
            <div className={`${s.end} ${over === 'lost' ? s.over : ''}`} style={{ color: 'var(--zx-bad)' }} {...drop('lost')}>Lost</div>
          </div>
        )}
        <div className={s.board}>
          {d.columns.map((c) => (
            <section key={c.id} className={`${s.col} ${over === c.id ? s.over : ''}`} aria-label={c.name} {...drop(c.id)}>
              <div>
                <Text weight="semibold">{c.name}</Text> <Text size={200} className={p.muted}>{c.deals.length}</Text>
                <Text size={200} block className={p.muted}>{m(c.total)} · {c.probability} % → {m(c.weighted)}</Text>
              </div>
              {c.deals.length === 0 && <Text size={200} className={p.muted}>No deals here.</Text>}
              {c.deals.map((x) => (
                <div key={x.id} className={s.card} role="button" tabIndex={0} draggable={d.can_move} onDragStart={() => setDrag(x.id)} onDragEnd={() => { setDrag(null); setOver(null) }}
                  onClick={() => navigate(`/office/crm/deals/${x.id}`)} onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/office/crm/deals/${x.id}`) }}>
                  <Text weight="semibold">{x.title}</Text>
                  <Text size={200} className={p.muted}>{x.who}</Text>
                  <div className={s.meta}><b>{m(x.value)}</b>{x.closes && <span style={x.late ? { color: 'var(--zx-bad)' } : undefined}>{x.closes}</span>}</div>
                  <div className={s.meta}><span className={p.muted}>{x.number}{x.quote ? ` · ${x.quote}` : ''}</span><span className={p.muted}>{x.owner ?? ''}</span></div>
                  {d.can_move && <Button size="small" appearance="subtle" onClick={(e) => { e.stopPropagation(); setMoving({ id: x.id, title: x.title, to: c.id }) }}>Move to…</Button>}
                </div>
              ))}
            </section>
          ))}
        </div>
      </section>

      {moving && (
        <Ask title={`Move: ${moving.title}`} go="Move" ready={!!moving.to} onClose={() => setMoving(null)} run={async () => { const t = moving; setMoving(null); move(t.id, t.to) }}>
          <Choose label="To" required options={targets} value={moving.to} onChange={(x) => setMoving({ ...moving, to: x })} />
        </Ask>
      )}
      {lose && (
        <Ask title={`Lost: ${lose.title}`} go="Lost" ready={reason.trim() !== ''} onClose={() => setLose(null)}
          run={() => api.crmDealAction(lose.id, 'lose', { reason }).then((x) => { setLose(null); setNote(x.message); load() })}>
          <TextField label="Why" required maxLength={255} placeholder="e.g. Price, went to a competitor, no longer needed" value={reason} onChange={setReason} />
        </Ask>
      )}
    </div>
  )
}

import { Avatar, Badge, Button, Dropdown, Field, makeStyles, MessageBar, MessageBarBody, Option, Radio, RadioGroup, SearchBox, Text, tokens } from '@fluentui/react-components'
import { PrintRegular, ProhibitedRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CardRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  who: { display: 'flex', alignItems: 'center', gap: '8px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const CARD = [['', 'Everyone'], ['none', 'No card yet'], ['has', 'Has a card']]

/**
 * Staff cards (settings and admin): tick the people, then Print new cards. The till scans the card instead of the
 * username. A new card stops the old one at once; Card lost stops the ticked people's cards.
 */
export function StaffCardsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [doing, setDoing] = useState<'print' | 'stop' | null>(null)
  const [done, setDone] = useState<UserDone | null>(null)
  const { data, loading, error } = useList(api.staffCards, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const card = str(params, 'card')
  const columns: Column<CardRow>[] = [
    { key: 'name', label: 'Name', render: (r) => <div className={s.who}><Avatar size={32} name={r.name} image={r.photo ? { src: r.photo } : undefined} />
      <div><Text weight="semibold">{r.name}</Text><Text block size={200} className={s.muted}>{r.username}</Text></div></div> },
    { key: 'roles', label: 'Role and where', render: (r) => (r.roles.length ? r.roles.map((x) => <div key={x}>{x}</div>) : <Text className={s.muted}>No role</Text>) },
    { key: 'pin', label: 'Till PIN', render: (r) => <Badge appearance="tint" color={r.pin ? 'success' : 'warning'}>{r.pin ? 'Set' : 'Not set'}</Badge> },
    { key: 'card', label: 'Card', render: (r) => (r.card ? <>Printed<Text block size={200} className={s.muted}>{r.card_on}</Text></> : <Text className={s.muted}>No card</Text>) },
  ]
  const select = (ids: string[], on: boolean) => setTicked((t) => { const n = new Set(t); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n })
  const finish = (r: UserDone) => { setDoing(null); setDone(r); setTicked(new Set()); setNonce((n) => n + 1) }

  return (
    <div className={page.page}>
      <PageHead title="Staff cards" hint="Tick the people, then Print new cards. The till scans the card instead of the username, so signing in is quicker and nobody sees a username typed." classic="/admin/staff-cards" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name or username" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search people" />
        <Dropdown aria-label="Card" style={{ minWidth: 150 }} value={CARD.find((x) => x[0] === card)?.[1] ?? ''} selectedOptions={[card]} onOptionSelect={(_, d) => set({ card: d.optionValue ?? '' })}>
          {CARD.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        <Button icon={<ProhibitedRegular />} disabled={ticked.size === 0} onClick={() => { setDone(null); setDoing('stop') }}>Card lost: stop it</Button>
        <Button appearance="primary" icon={<PrintRegular />} disabled={ticked.size === 0} onClick={() => { setDone(null); setDoing('print') }}>Print new cards{ticked.size ? ` (${ticked.size})` : ''}</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      <DataTable label="Staff cards" columns={columns} rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} selected={ticked} onSelect={select}
        empty="Nobody here yet. Add people under Users first." onSort={() => undefined} onOpen={(r) => navigate(`/office/users/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing && data && <CardsDialog stop={doing === 'stop'} ids={[...ticked]} layouts={data.layouts} onClose={() => setDoing(null)} onDone={finish} />}
    </div>
  )
}

function CardsDialog({ stop, ids, layouts, onClose, onDone }: { stop: boolean; ids: string[]; layouts: { id: string; label: string }[]; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [layout, setLayout] = useState(layouts[0]?.id ?? 'a4')
  const { guard, dialog } = useReconfirm('Changing how people sign in is protected.')
  if (dialog) return dialog
  return stop ? (
    <Ask title="Stop these cards?" go="Stop the cards" ready onClose={onClose} run={guard(async () => onDone(await api.cardsAction('stop', { ids })))}>
      <Text>The cards stop working at once. These people sign in with their username and PIN until new cards are printed.</Text>
    </Ask>
  ) : (
    <Ask title={`Print ${ids.length} new staff card${ids.length === 1 ? '' : 's'}`} go="Print" ready onClose={onClose}
      run={guard(async () => { const r = await api.cardsAction('print', { ids, layout }); if (r.print) window.open(r.print, '_blank'); onDone(r) })}>
      <Text block style={{ marginBottom: 10 }}>Each person ticked gets a new card; a card they already have stops working at once. The cards open in a new tab, ready to print; that page works once (the codes are not kept).</Text>
      <Field label="Print on" required><RadioGroup value={layout} onChange={(_, d) => setLayout(d.value)}>{layouts.map((l) => <Radio key={l.id} value={l.id} label={l.label} />)}</RadioGroup></Field>
    </Ask>
  )
}

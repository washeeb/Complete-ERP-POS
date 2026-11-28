import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowDownRegular, ArrowUpRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type TillFavourite, type TillFavourites } from '../api'
import { Choose } from '../form/Fields'
import { SaveBar } from '../form/SaveBar'
import { SearchPicker } from '../form/SearchPicker'
import { PageHead, usePageStyles } from '../list/ListParts'
import { confirmLeave } from '../unsaved'
import { Panel } from './ProcurementParts'

const useStyles = makeStyles({
  list: { listStyle: 'none', margin: '10px 0 0', padding: 0, display: 'grid', gap: '6px' },
  row: { display: 'grid', gridTemplateColumns: '32px minmax(0, 1fr) auto auto', alignItems: 'center', gap: '10px', padding: '8px 10px',
    border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusMedium, backgroundColor: tokens.colorNeutralBackground1 },
  pos: { color: tokens.colorNeutralForeground3, fontVariantNumeric: 'tabular-nums', textAlign: 'right' },
  muted: { color: tokens.colorNeutralForeground3 },
  price: { fontVariantNumeric: 'tabular-nums' },
  tools: { display: 'flex', gap: '2px' },
  top: { display: 'grid', gap: '12px', maxWidth: '520px', marginBottom: '12px' },
})

/**
 * Sales → Till favourites (till new look, part 1b): the products a branch's tills show first, as large tiles on the
 * Favourites tab, in this order. Add by name, SKU or barcode; move up or down; take off. For whoever may change the
 * branch's till settings (e.g. the branch manager).
 */
export function TillFavouritesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [d, setD] = useState<TillFavourites | null>(null)
  const [branch, setBranch] = useState('')
  const [list, setList] = useState<TillFavourite[]>([])
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.tillFavourites(branch).then((r) => { setD(r); setBranch(r.branch ?? ''); setList(r.favourites) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [branch])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Loading…" /></div>

  const move = (i: number, by: number) => setList((l) => { const n = [...l]; const [x] = n.splice(i, 1); n.splice(i + by, 0, x); return n })
  const save = () => {
    setBusy(true); setError(''); setDone('')
    api.saveTillFavourites(branch, list.map((p) => p.id)).then((r) => { setList(r.favourites); setDone(r.message) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  const full = list.length >= d.max

  return (
    <div className={page.page}>
      <PageHead title="Till favourites" hint="The products a branch's tills show first, as large tiles to tap, in this order. Why: the items sold most are one tap away, even without a barcode." />
      <div className={s.top}>
        {d.branches.length > 1 && <Choose label="Branch" required options={d.branches} value={branch}
          onChange={(v) => confirmLeave(() => { setDone(''); setBranch(v) })} />}
        {d.branches.length === 0 && <Text className={s.muted}>You may not change the tills of any branch.</Text>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {branch && (
        <Panel title="Favourite tiles" about={`Up to ${d.max}. The tills open on these; the categories follow as tabs.`}>
          {!full && <SearchPicker label="Add a product" value="" text={null} placeholder="Type a name, SKU or barcode" search={api.tillFavouriteProducts(branch)}
            onChange={(x) => { if (x && !list.some((p) => p.id === x.id)) setList([...list, { id: x.id, name: x.label, sku: x.detail ?? '', price: null }]) }} />}
          {full && <Text className={s.muted}>A till shows at most {d.max} favourites: take one off to add another.</Text>}
          {list.length === 0 ? <Text block className={s.muted} style={{ marginTop: 10 }}>No favourites yet: the tills open on the first category.</Text> : (
            <ol className={s.list} aria-label="Favourite tiles in order">
              {list.map((p, i) => (
                <li key={p.id} className={s.row}>
                  <span className={s.pos}>{i + 1}</span>
                  <span><Text weight="semibold">{p.name}</Text><Text block size={200} className={s.muted}>{p.sku}</Text></span>
                  <Text className={s.price}>{p.price ?? ''}</Text>
                  <span className={s.tools}>
                    <Button appearance="subtle" icon={<ArrowUpRegular />} disabled={i === 0} onClick={() => move(i, -1)} aria-label={`Move ${p.name} up`} />
                    <Button appearance="subtle" icon={<ArrowDownRegular />} disabled={i === list.length - 1} onClick={() => move(i, 1)} aria-label={`Move ${p.name} down`} />
                    <Button appearance="subtle" icon={<DeleteRegular />} onClick={() => setList(list.filter((x) => x.id !== p.id))} aria-label={`Take ${p.name} off`} />
                  </span>
                </li>
              ))}
            </ol>
          )}
        </Panel>
      )}
      {branch && (
        <SaveBar value={list.map((p) => p.id)}>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
        </SaveBar>
      )}
    </div>
  )
}

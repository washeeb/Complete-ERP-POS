import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, EditRegular, GridRegular, TagRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type StyleDetail } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Status } from './CompanyParts'
import { Fact, Panel, useProcStyles } from './ProcurementParts'

type Action = 'generate' | 'price' | 'archive' | 'restore'

/**
 * One style (part 36), as the current page: code, name, season and collection, price, and the size × colour grid — on
 * hand now (all warehouses) and sold in the last 30 days, each cell its own product. Change, Create sizes and colours,
 * One price for all, Archive and Restore.
 */
export function StylePage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<StyleDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<Action | null>(null)
  const [f, setF] = useState({ price: '', why: '' })
  const load = useCallback(() => {
    api.style(id).then((x) => { setD(x); onTitle(`${x.code} · ${x.name}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the style…" /></div>
  const m = (x: number | null) => (x === null ? '—' : money(x, d.currency, d.exponent))
  const a = d.actions
  const show = (x: Action) => { setDone(''); setF({ price: '', why: '' }); setAsk(x) }
  const finish = (x: { message: string }) => { setAsk(null); setError(''); setDone(x.message); load() }
  const back = () => (history.length > 1 ? history.back() : navigate('/office/fashion/styles'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Styles</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.code}</span>{d.name}<Status r={d} /></h1>
        <div className={s.actions}>
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/fashion/styles/${d.id}/edit`)}>Change</Button>}
          {a.generate && <Button appearance={d.missing > 0 ? 'primary' : 'secondary'} icon={<GridRegular />} onClick={() => show('generate')}>Create sizes and colours</Button>}
          {a.price && <Button icon={<TagRegular />} onClick={() => show('price')}>One price for all</Button>}
          {d.can_archive && <Button icon={<ArchiveRegular />} onClick={() => show('archive')}>Archive</Button>}
          {d.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => show('restore')}>Restore</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Style">
        <div className={s.facts}>
          <Fact label="Season">{d.season ?? '—'}{d.collection && <Text size={200} block className={s.muted}>{d.collection}</Text>}</Fact>
          <Fact label="Price">{m(d.price)}</Fact>
          <Fact label="Category">{d.category ?? '—'}</Fact>
          <Fact label="Brand">{d.brand ?? '—'}</Fact>
          <Fact label="Tax">{d.tax ?? '—'}</Fact>
          <Fact label="For sale">{d.for_sale ? 'Yes' : 'No'}</Fact>
        </div>
        {d.description && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{d.description}</Text>}
      </Panel>
      <Panel title="Sizes and colours" about="On hand now (all warehouses) and sold in the last 30 days; each cell is its own product.">
        {d.missing > 0 && <Text block className={s.muted} style={{ marginBottom: 8 }}>{d.missing} size and colour pair(s) are not created yet: press Create sizes and colours.</Text>}
        <Table size="small" aria-label="Sizes and colours">
          <TableHeader><TableRow><TableHeaderCell>Colour</TableHeaderCell>{d.sizes.map((z) => <TableHeaderCell key={z}>{z}</TableHeaderCell>)}</TableRow></TableHeader>
          <TableBody>{d.colours.map((c) => (
            <TableRow key={c}><TableCell><Text weight="semibold">{c}</Text></TableCell>{d.sizes.map((z) => {
              const cell = d.cells[c]?.[z]
              return <TableCell key={z}>{cell
                ? <><Text>{cell.on_hand}</Text> <Text size={200} className={s.muted}>({cell.sold} sold)</Text><Text size={100} block className={s.muted}>{cell.sku}</Text></>
                : <Text size={200} className={s.muted}>not created</Text>}</TableCell>
            })}</TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {ask === 'generate' && (
        <Ask title="Create sizes and colours?" go="Create" ready onClose={() => setAsk(null)} run={() => api.styleAction(d.id, 'generate').then(finish)}>
          <Text>Creates the products missing from the grid (with SKU, barcode and the style price) and updates the name, category, brand and tax of the others.</Text>
        </Ask>
      )}
      {ask === 'price' && (
        <Ask title="One price for all" go="Set price" ready={f.price !== '' && Number(f.price) >= 0} onClose={() => setAsk(null)}
          run={() => api.styleAction(d.id, 'price', { price: f.price }).then(finish)}>
          <div className={s.one}>
            <Text>Sets this price on every size and colour, on the default price list.</Text>
            <TextField label="Price" required type="number" min={0} step="any" prefix={d.currency} value={f.price} onChange={(x) => setF({ ...f, price: x })} />
          </div>
        </Ask>
      )}
      {ask === 'archive' && (
        <Ask title={`Archive ${d.name}?`} go="Archive" ready={f.why.trim().length >= 3 && d.archive_blockers.length === 0} onClose={() => setAsk(null)}
          run={() => api.styleArchive(d.id, false, f.why).then(finish)}>
          <div className={s.one}>
            {d.archive_blockers.length > 0 && <Text>It cannot be archived yet. {d.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists. Its products, stock and sales stay.</Text>
            <TextField label="Why" required maxLength={255} value={f.why} onChange={(x) => setF({ ...f, why: x })} />
          </div>
        </Ask>
      )}
      {ask === 'restore' && (
        <Ask title={`Restore ${d.name}?`} go="Restore" ready onClose={() => setAsk(null)} run={() => api.styleArchive(d.id, true, '').then(finish)}>
          <Text>It comes back into the lists.</Text>
        </Ask>
      )}
    </div>
  )
}

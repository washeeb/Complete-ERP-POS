import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, ArrowSyncRegular, ArrowUndoRegular, PauseRegular, PlayRegular, SaveRegular, ScalesRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type ChannelDetail, type ChannelValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'activate' | 'pause' | 'reconcile' | 'retry' | 'archive' | 'restore'

/**
 * One channel (part 33), as the current form: kind (fixed once saved), name, the branch that fills its orders and the
 * warehouse its stock comes from, price list and safety stock; the shop page's address and texts, or the store's address
 * and its secrets (stored encrypted and never shown again: a blank field keeps the saved one). Activate, Pause, Compare
 * with store, Retry failed updates, archive and restore.
 */
export function ChannelPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<ChannelDetail | null>(null)
  const [v, setV] = useState<ChannelValues | null>(null)
  const [secrets, setSecrets] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [done, setDone] = useState<{ message: string; details?: string[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [why, setWhy] = useState('')
  const load = useCallback(() => {
    api.channel(id).then((x) => { setD(x); setV(x.values); setSecrets({}); onTitle(x.id ? x.values.name : 'New channel') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the channel…" /></div>
  const put = <K extends keyof ChannelValues>(k: K) => (x: ChannelValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const shop = v.type === 'storefront'
  const a = d.actions
  const back = () => (history.length > 1 ? history.back() : navigate('/office/online/channels'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const after = (x: { message: string; details?: string[] }) => { setDialog(null); setError(''); setDone(x); load() }
  const save = () => {
    setBusy(true)
    setError('')
    api.saveChannel(d.id, { ...v, secrets }).then((x) => { if (d.id) after(x); else navigate(`/office/online/channels/${x.id}`, true) }).catch(fail).finally(() => setBusy(false))
  }
  const fields = d.secrets[v.type] ?? []
  const ready = v.name.trim() !== '' && !!v.branch_id && !!v.warehouse_id && !!v.price_list_id && (shop || v.base_url.trim() !== '' || fields.length === 0)
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Channels</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id ? d.values.name : 'New channel'}{d.status && <ToneBadge tone={d.status === 'active' ? 'success' : d.status === 'paused' ? 'warning' : 'subtle'} label={d.status} />}</h1>
        {a && <div className={s.actions}>
          {a.activate && <Button appearance="primary" icon={<PlayRegular />} onClick={() => setDialog('activate')}>Activate</Button>}
          {a.pause && <Button icon={<PauseRegular />} onClick={() => { setWhy(''); setDialog('pause') }}>Pause</Button>}
          {a.reconcile && <Button icon={<ScalesRegular />} onClick={() => setDialog('reconcile')}>Compare with store</Button>}
          {a.retry && <Button icon={<ArrowSyncRegular />} onClick={() => setDialog('retry')}>Retry failed updates</Button>}
          {d.archive?.can_archive && <Button icon={<ArchiveRegular />} onClick={() => { setWhy(''); setDialog('archive') }}>Archive</Button>}
          {d.archive?.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('restore')}>Restore</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done.message}{done.details?.map((x) => <div key={x}>{x}</div>)}</MessageBarBody></MessageBar>}
      {d.archive?.archived && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{d.archive.archived_note}</MessageBarBody></MessageBar>}
      {d.address && <Text block style={{ marginBottom: 10, overflowWrap: 'anywhere' }}>{shop ? 'Shop address' : 'Webhook address'}: <b>{d.address}</b></Text>}

      <Panel title="Channel">
        <div className={f.grid}>
          {d.id ? <TextField label="Kind" disabled value={d.type_label ?? v.type} onChange={() => undefined} />
            : <Choose label="Kind" required options={d.types} value={v.type} onChange={put('type')} hint="Marketplaces (Jumia, Glovo, Bolt Food, WhatsApp) appear here once their connectors exist." />}
          <TextField label="Name" required maxLength={80} value={v.name} onChange={put('name')} />
          <Choose label="Fulfilling branch" required options={d.branches} value={v.branch_id} onChange={(x) => setV({ ...v, branch_id: x, warehouse_id: '' })} />
          <Choose label="Stock from warehouse" required options={d.warehouses.filter((w) => w.branch_id === v.branch_id)} value={v.warehouse_id} onChange={put('warehouse_id')} />
          <Choose label="Price list" required options={d.price_lists} value={v.price_list_id} onChange={put('price_list_id')} />
          <TextField label="Safety stock (per product)" type="number" min={0} step="any" value={v.safety_stock} onChange={put('safety_stock')}
            hint="Held back from what the channel may sell, so in-store and offline sales do not oversell online." />
          {!shop && <TextField label="Store address" className={f.spanAll} maxLength={255} placeholder="https://shop.example.com" value={v.base_url} onChange={put('base_url')} />}
        </div>
      </Panel>
      {shop && (
        <Panel title="Shop page">
          <div className={f.grid}>
            <TextField label="Shop address" maxLength={60} prefix={d.shop_base} hint="Lowercase letters, digits and dashes. Leave blank to use the name." value={v.slug} onChange={put('slug')} />
            <TextField label="Headline" maxLength={120} placeholder={d.defaults.headline} value={v.headline} onChange={put('headline')} />
            <LongText label="Introduction" maxLength={500} value={v.intro} onChange={put('intro')} hint={`Blank shows: ${d.defaults.intro ?? ''}`} />
            <LongText label="Pickup note" maxLength={500} value={v.pickup_note} onChange={put('pickup_note')} hint={`Blank shows: ${d.defaults.pickup_note ?? ''}`} />
          </div>
        </Panel>
      )}
      {!shop && fields.length > 0 && (
        <Panel title="Connection" about="Secrets are stored encrypted and never shown again.">
          <div className={f.grid}>
            {fields.map((x) => (
              <TextField key={x.id} label={x.label} type="password" maxLength={500} value={secrets[x.id] ?? ''} onChange={(val) => setSecrets({ ...secrets, [x.id]: val })}
                hint={[x.saved ? 'Saved. Leave blank to keep it.' : null, x.hint].filter(Boolean).join(' ') || undefined} />
            ))}
          </div>
        </Panel>
      )}
      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add channel'}</Button>
      </div>

      {dialog === 'activate' && (
        <Ask title="Activate this channel?" go="Activate" ready onClose={() => setDialog(null)} run={() => api.channelAction(d.id!, 'activate').then(after)}>
          <Text>{shop ? 'The shop page goes live at its address.' : 'Sends every product, price and stock level to the store now, then keeps it up to date.'}</Text>
        </Ask>
      )}
      {dialog === 'pause' && (
        <Ask title="Pause this channel?" go="Pause" ready={why.trim() !== ''} onClose={() => setDialog(null)} run={() => api.channelAction(d.id!, 'pause', { reason: why }).then(after)}>
          <div className={s.one}>
            <Text>Stops sending updates and accepting new orders from this channel. Nothing is deleted.</Text>
            <TextField label="Reason" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {dialog === 'reconcile' && (
        <Ask title="Compare with the store?" go="Compare" ready onClose={() => setDialog(null)} run={() => api.channelAction(d.id!, 'reconcile').then(after)}>
          <Text>Reads the store's products, prices and stock and queues a fix for every difference.</Text>
        </Ask>
      )}
      {dialog === 'retry' && (
        <Ask title="Retry failed updates?" go="Retry" ready onClose={() => setDialog(null)} run={() => api.channelAction(d.id!, 'retry').then(after)}>
          <Text>Last error: {a?.last_error ?? '—'}</Text>
        </Ask>
      )}
      {dialog === 'archive' && (
        <Ask title={`Archive ${d.values.name}?`} go="Archive" ready={why.trim().length >= 3 && (d.archive?.archive_blockers.length ?? 0) === 0} onClose={() => setDialog(null)}
          run={() => api.archiveChannel(d.id!, false, why).then(after)}>
          <div className={s.one}>
            {(d.archive?.archive_blockers.length ?? 0) > 0 && <Text>It cannot be archived yet. {d.archive!.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists. Its orders and history stay.</Text>
            <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {dialog === 'restore' && (
        <Ask title={`Restore ${d.values.name}?`} go="Restore" ready onClose={() => setDialog(null)} run={() => api.archiveChannel(d.id!, true, '').then(after)}>
          <Text>It comes back into the lists. Activate it again to go live.</Text>
        </Ask>
      )}
    </div>
  )
}

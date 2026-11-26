import { Badge, Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, EditRegular, SaveRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type BranchDetail, type OwnSetting } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { ArchiveAsk, type ArchiveKind, Status } from './CompanyParts'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Archiving = { kind: ArchiveKind; id: string; name: string; restore: boolean } | null
type Editing = { what: 'store' | 'till'; id: string | null; name: string; warehouse_id: string } | null

/** One setting the branch may set for itself: empty follows the company (shown as the placeholder). */
function Own({ f, value, onChange }: { f: OwnSetting; value: string; onChange: (v: string) => void }) {
  const hint = f.who ? `${f.help} (${f.who})` : f.help
  return f.options
    ? <Choose label={f.label} hint={hint} disabled={!f.may_change} options={f.options} value={value} onChange={onChange} placeholder={f.company} clearable />
    : <TextField label={f.label} hint={hint} disabled={!f.may_change} type="number" min={f.min ?? undefined} max={f.max ?? undefined} placeholder={f.company} value={value} onChange={onChange} />
}

/**
 * One branch (part 20), as the current page: a new branch (name, legal entity, phone, address, how many tills to start
 * with), or an existing one — its details, the settings it may set for itself (Pharmacy only where it runs Pharmacy;
 * Tills; Remote connections), its stores and its tills (add, rename, archive, restore), and Archive or Restore.
 */
export function BranchPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [b, setB] = useState<BranchDetail | null>(null)
  const [v, setV] = useState<Record<string, string>>({})
  const [own, setOwn] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [archiving, setArchiving] = useState<Archiving>(null)
  const [editing, setEditing] = useState<Editing>(null)
  const take = useCallback((x: BranchDetail) => {
    setB(x)
    setV({ ...x.values, reason: '' } as Record<string, string>)
    setOwn(Object.fromEntries((x.sections ?? []).flatMap((sec) => sec.fields.map((fl) => [fl.key, fl.value]))))
    onTitle(x.id ? `${x.code} · ${x.values.name}` : 'New branch')
  }, [onTitle])
  const load = useCallback(() => {
    api.branch(id).then(take).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, take])
  useEffect(load, [load])

  if (error && !b) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!b) return <div className={page.page}><Spinner label="Opening the branch…" /></div>
  const put = (k: string) => (x: string) => setV((o) => ({ ...o, [k]: x }))
  const back = () => (history.length > 1 ? history.back() : navigate('/office/branches'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const edit = !b.id || !!b.can_edit
  const save = () => {
    setBusy(true)
    setError('')
    setDone('')
    const body = { name: v.name, legal_entity_id: v.legal_entity_id || null, phone: v.phone || null, address: v.address || null }
    const go = b.id
      ? api.saveBranch(b.id, { ...body, industry_id: v.industry_id || null, reason: v.reason || null, settings: own }).then((x) => { take(x.branch); setDone(x.message) })
      : api.createBranch({ ...body, tills: Number(v.tills || 0) }).then((x) => navigate(`/office/branches/${x.id}`, true))
    go.catch(fail).finally(() => setBusy(false))
  }
  const stores = b.stores ?? []
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Branches</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          {b.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{b.code}</span>}{b.id ? b.values.name : 'New branch'}
          {b.hq && <Badge appearance="outline">HQ</Badge>}{b.id && <Status r={{ archived: !!b.archived, archived_note: b.archived_note }} />}
        </h1>
        <div className={s.actions}>
          {b.can_archive && <Button icon={<ArchiveRegular />} onClick={() => setArchiving({ kind: 'branch', id: b.id!, name: b.values.name, restore: false })}>Archive</Button>}
          {b.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => setArchiving({ kind: 'branch', id: b.id!, name: b.values.name, restore: true })}>Restore</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <Panel title="Branch">
        <div className={f.grid}>
          {!b.id && <Text className={f.spanAll}>The code is given from the name when saved (Pharmacy Osu → PHAOSU). It appears in receipt and invoice numbers, so it never changes.</Text>}
          <TextField label="Name" required maxLength={120} disabled={!edit} value={v.name ?? ''} onChange={put('name')} />
          <Choose label="Legal entity" options={b.legal_entities} disabled={!edit} value={v.legal_entity_id ?? ''} onChange={put('legal_entity_id')} clearable />
          {b.id && <Choose label="Industry" options={b.industries} disabled={!edit} value={v.industry_id ?? ''} onChange={put('industry_id')} clearable
            placeholder="Company-level (head office, central warehouse)" hint="One industry per branch: switching its industry module on (Company → Modules) sets it too." />}
          <TextField label="Phone" maxLength={30} disabled={!edit} value={v.phone ?? ''} onChange={put('phone')} />
          <TextField label="Address" className={f.spanAll} maxLength={255} disabled={!edit} value={v.address ?? ''} onChange={put('address')} />
          {!b.id && <TextField label="Tills to create" required type="number" min={0} max={50} value={v.tills ?? '1'} onChange={put('tills')} />}
          {b.id && <TextField label="Reason for change" maxLength={255} disabled={!edit} value={v.reason ?? ''} onChange={put('reason')} />}
        </div>
      </Panel>
      {(b.sections ?? []).map((sec) => (
        <Panel key={sec.prefix} title={sec.title} about={sec.about}>
          <div className={f.grid}>{sec.fields.map((fl) => <Own key={fl.key} f={fl} value={own[fl.key] ?? ''} onChange={(x) => setOwn((o) => ({ ...o, [fl.key]: x }))} />)}</div>
        </Panel>
      ))}
      {edit && (
        <SaveBar value={[v, own]}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !(v.name ?? '').trim()} onClick={save}>{busy ? 'Saving…' : b.id ? 'Save' : 'Create branch'}</Button>
        </SaveBar>
      )}

      {b.id && (<>
        <Panel title="Stores" about="Where the branch keeps stock. A new branch starts with its main store."
          actions={edit ? <Button icon={<AddRegular />} onClick={() => setEditing({ what: 'store', id: null, name: '', warehouse_id: '' })}>Add a store</Button> : undefined}>
          <Table size="small" aria-label="Stores">
            <TableHeader><TableRow><TableHeaderCell>Code</TableHeaderCell><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>{stores.map((w) => (
              <TableRow key={w.id}><TableCell><Text font="monospace">{w.code}</Text></TableCell><TableCell>{w.name}</TableCell><TableCell><Status r={w} /></TableCell>
                <TableCell><div className={s.actions}>
                  {edit && <Button size="small" appearance="subtle" icon={<EditRegular />} onClick={() => setEditing({ what: 'store', id: w.id, name: w.name, warehouse_id: '' })}>Rename</Button>}
                  {w.can_archive && <Button size="small" appearance="subtle" onClick={() => setArchiving({ kind: 'store', id: w.id, name: w.name, restore: false })}>Archive</Button>}
                  {w.can_restore && <Button size="small" appearance="subtle" onClick={() => setArchiving({ kind: 'store', id: w.id, name: w.name, restore: true })}>Restore</Button>}
                </div></TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
        <Panel title="Tills" about="The code appears in receipt numbers, e.g. T01. Each till sells stock from one of the branch's stores."
          actions={edit && stores.length ? <Button icon={<AddRegular />} onClick={() => setEditing({ what: 'till', id: null, name: '', warehouse_id: stores.find((w) => !w.archived)?.id ?? '' })}>Add a till</Button> : undefined}>
          <Table size="small" aria-label="Tills">
            <TableHeader><TableRow><TableHeaderCell>Code</TableHeaderCell><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Stock from</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>{(b.tills ?? []).map((t) => (
              <TableRow key={t.id}><TableCell><Text font="monospace">{t.code}</Text></TableCell><TableCell>{t.name}</TableCell><TableCell>{t.store ?? '—'}</TableCell><TableCell><Status r={t} /></TableCell>
                <TableCell><div className={s.actions}>
                  {edit && <Button size="small" appearance="subtle" icon={<EditRegular />} onClick={() => setEditing({ what: 'till', id: t.id, name: t.name, warehouse_id: t.warehouse_id })}>Change</Button>}
                  {t.can_archive && <Button size="small" appearance="subtle" onClick={() => setArchiving({ kind: 'till', id: t.id, name: t.name, restore: false })}>Archive</Button>}
                  {t.can_restore && <Button size="small" appearance="subtle" onClick={() => setArchiving({ kind: 'till', id: t.id, name: t.name, restore: true })}>Restore</Button>}
                </div></TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
      </>)}

      {editing && b.id && (
        <Ask title={`${editing.id ? 'Change' : 'Add'} a ${editing.what}`} go="Save" ready={editing.name.trim() !== '' && (editing.what === 'store' || !!editing.warehouse_id)} onClose={() => setEditing(null)}
          run={() => (editing.what === 'store' ? api.saveStore(b.id!, { id: editing.id, name: editing.name }) : api.saveTill(b.id!, { id: editing.id, name: editing.name, warehouse_id: editing.warehouse_id }))
            .then((x) => { take(x.branch); setEditing(null); setDone(x.message) })}>
          <div className={s.one}>
            {!editing.id && <Text>The code is given when saved{editing.what === 'till' ? ' (the next T number)' : ''}, and never changes.</Text>}
            <TextField label="Name" required maxLength={120} value={editing.name} onChange={(x) => setEditing({ ...editing, name: x })} />
            {editing.what === 'till' && <Choose label="Sells stock from" required options={stores.filter((w) => !w.archived || w.id === editing.warehouse_id).map((w) => ({ id: w.id, label: w.name }))}
              value={editing.warehouse_id} onChange={(x) => setEditing({ ...editing, warehouse_id: x })} />}
          </div>
        </Ask>
      )}
      {archiving && (() => {
        const row = archiving.kind === 'branch' ? { ...b, id: b.id! } as never : (archiving.kind === 'store' ? stores : b.tills ?? []).find((x) => x.id === archiving.id)!
        return <ArchiveAsk kind={archiving.kind} row={row} name={archiving.name} restore={archiving.restore} onClose={() => setArchiving(null)}
          onDone={(m) => { setArchiving(null); setDone(m); load() }} />
      })()}
    </div>
  )
}

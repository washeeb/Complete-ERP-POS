import { Button, Field, Link, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow,
  Text } from '@fluentui/react-components'
import { AddRegular, ArrowSyncRegular, ArrowUploadRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ShownOnce, type SyncData, type SyncPeerRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { ShownOnceDialog } from './ShownOnce'

type Doing = { kind: 'review' | 'modes' | 'toggle' | 'resync'; peer: SyncPeerRow } | { kind: 'branch' } | { kind: 'import'; what: 'sync' | 'till' } | null

/**
 * System → Sync Center (part 23): each server this one syncs with — last sync, status, what is waiting and the last
 * error. Sync now, Review incoming (apply or reject a group kept for preview), Choose what syncs, Export sync file (USB),
 * Disable / Enable, Full resync; Add branch server (its join command and secret shown once), Import sync file and
 * Import till file (a till's unsent sales, from a till being reinstalled).
 */
export function SyncCenterPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<SyncData | null>(null)
  const [error, setError] = useState('')
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [doing, setDoing] = useState<Doing>(null)
  const [busy, setBusy] = useState('')
  const [v, setV] = useState<Record<string, string>>({})
  const [file, setFile] = useState<File | null>(null)
  const [secret, setSecret] = useState<ShownOnce | null>(null)
  useEffect(() => { api.syncCenter().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the Sync Center…" /></div>
  const take = (x: { message: string; good?: boolean; sync: SyncData; secret?: ShownOnce }) => { setD(x.sync); setDoing(null); setNote({ good: x.good ?? true, text: x.message }); if (x.secret) setSecret(x.secret) }
  const syncNow = (p: SyncPeerRow) => {
    setBusy(p.id)
    setNote(null)
    api.syncAction(p.id, 'sync').then(take).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) })).finally(() => setBusy(''))
  }
  const start = (x: Doing) => {
    setNote(null)
    setFile(null)
    setV(x && 'peer' in x && x.kind === 'modes' ? Object.fromEntries(x.peer.groups.map((g) => [g.key, g.mode])) : x && 'peer' in x && x.kind === 'review' ? { group: x.peer.review?.groups[0]?.id ?? '', accept: '1' } : {})
    setDoing(x)
  }
  return (
    <div className={page.page}>
      <PageHead title="Sync Center" hint={`This server is node ${d.node}. Each server it syncs with, what is waiting, and the controls.`} />
      <div className={s.bar}>
        <span className={s.grow} />
        <Button icon={<ArrowUploadRegular />} onClick={() => start({ kind: 'import', what: 'till' })}>Import till file</Button>
        <Button icon={<ArrowUploadRegular />} onClick={() => start({ kind: 'import', what: 'sync' })}>Import sync file</Button>
        {!d.is_branch && <Button appearance="primary" icon={<AddRegular />} onClick={() => start({ kind: 'branch' })}>Add branch server</Button>}
      </div>
      {note && <MessageBar intent={note.good ? 'success' : 'error'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <Panel title="Servers">
        {d.rows.length === 0 ? <><Text weight="semibold" block>Not connected to any other server</Text><Text className={s.muted}>{d.empty}</Text></> : (
          <Table size="small" aria-label="Sync peers" style={{ minWidth: 720 }}>
            <TableHeader><TableRow><TableHeaderCell>Server</TableHeaderCell><TableHeaderCell>Last sync</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Waiting</TableHeaderCell>
              <TableHeaderCell>Last error</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>{d.rows.map((p) => (
              <TableRow key={p.id}>
                <TableCell>{p.name}<Text size={200} block className={s.muted}>{p.about}</Text></TableCell>
                <TableCell>{p.last_sync ?? 'never'}</TableCell><TableCell><ToneBadge tone={p.tone} label={p.status} /></TableCell><TableCell>{p.waiting}</TableCell>
                <TableCell>{p.error ? <Text style={{ color: 'var(--zx-bad)' }}>{p.error.length > 120 ? `${p.error.slice(0, 120)}…` : p.error}</Text> : '—'}</TableCell>
                <TableCell><div className={s.actions}>
                  {p.can_sync && <Button size="small" icon={<ArrowSyncRegular />} disabled={!!busy} onClick={() => syncNow(p)}>{busy === p.id ? 'Syncing…' : 'Sync now'}</Button>}
                  {p.review && <Button size="small" onClick={() => start({ kind: 'review', peer: p })}>Review incoming</Button>}
                  <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${p.name}`} /></MenuTrigger>
                    <MenuPopover><MenuList>
                      {p.groups.length > 0 && <MenuItem onClick={() => start({ kind: 'modes', peer: p })}>Choose what syncs</MenuItem>}
                      <MenuItem onClick={() => { window.location.href = `/office-api/system/sync/${p.id}/export` }}>Export sync file (USB)</MenuItem>
                      <MenuItem onClick={() => start({ kind: 'toggle', peer: p })}>{p.active ? 'Disable this peer' : 'Enable this peer'}</MenuItem>
                      {p.can_resync && <MenuItem onClick={() => start({ kind: 'resync', peer: p })}>Full resync from this peer</MenuItem>}
                    </MenuList></MenuPopover></Menu>
                </div></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {doing?.kind === 'review' && doing.peer.review && (
        <Ask title="Changes waiting to be applied" go={v.accept === '1' ? 'Apply these changes' : 'Reject them'} width={620} ready={!!v.group} onClose={() => setDoing(null)}
          run={() => api.syncAction(doing.peer.id, 'decide', { group: v.group, accept: v.accept === '1' }).then(take)}>
          <div className={s.one}>
            <Text>Changes for groups set to Manual (or Off) are kept here until you decide. Accepting applies them in the order they happened.</Text>
            <ul style={{ margin: 0 }}>{doing.peer.review.items.map((i, k) => <li key={k}><b>{i.group}</b>: {i.n} × {i.event}</li>)}</ul>
            <Choose label="Group" required options={doing.peer.review.groups} value={v.group ?? ''} onChange={(x) => setV({ ...v, group: x })} />
            <Choose label="Decision" required options={[{ id: '1', label: 'Apply these changes' }, { id: '0', label: 'Reject (keep the record, never apply)' }]} value={v.accept ?? '1'} onChange={(x) => setV({ ...v, accept: x })} />
          </div>
        </Ask>
      )}
      {doing?.kind === 'modes' && (
        <Ask title={`What syncs from ${doing.peer.name}`} go="Save" width={620} ready onClose={() => setDoing(null)} run={() => api.syncAction(doing.peer.id, 'modes', { groups: v }).then(take)}>
          <div className={s.one}>
            <Text>Automatic: applied at each sync. Manual: kept for you to preview and accept. Off: kept but not applied.</Text>
            {doing.peer.groups.map((g) => <Choose key={g.key} label={g.label} required options={d.modes} value={v[g.key] ?? 'auto'} onChange={(x) => setV({ ...v, [g.key]: x })} />)}
          </div>
        </Ask>
      )}
      {doing?.kind === 'toggle' && (
        <Ask title={doing.peer.active ? `Disable ${doing.peer.name}?` : `Enable ${doing.peer.name}?`} go={doing.peer.active ? 'Disable' : 'Enable'} ready onClose={() => setDoing(null)}
          run={() => api.syncAction(doing.peer.id, 'toggle').then(take)}>
          <Text>{doing.peer.active ? 'Stops all sync with this server immediately (its requests are refused). Nothing is deleted; enable it again to resume where it stopped.' : 'Resumes sync from where it stopped.'}</Text>
        </Ask>
      )}
      {doing?.kind === 'resync' && (
        <Ask title="Full resync from this peer?" go="Full resync" ready onClose={() => setDoing(null)} run={() => api.syncAction(doing.peer.id, 'resync').then(take)}>
          <Text>Receives everything again from the beginning. Safe (nothing is applied twice) but may take a while on a slow link.</Text>
        </Ask>
      )}
      {doing?.kind === 'branch' && (
        <Ask title="Add branch server" go="Register" ready={!!v.node_id?.trim() && !!v.name?.trim()} onClose={() => setDoing(null)}
          run={() => api.addBranchServer({ node_id: v.node_id, name: v.name }).then(take)}>
          <div className={s.one}>
            <TextField label="Branch server id" required maxLength={40} hint="Must match ERP_NODE_ID on that server, e.g. B01" value={v.node_id ?? ''} onChange={(x) => setV({ ...v, node_id: x })} />
            <TextField label="Name" required maxLength={80} placeholder="Branch A server" value={v.name ?? ''} onChange={(x) => setV({ ...v, name: x })} />
          </div>
        </Ask>
      )}
      {doing?.kind === 'import' && (
        <Ask title={doing.what === 'sync' ? 'Import sync file' : 'Import till file'} go="Import" ready={!!file} onClose={() => setDoing(null)} run={() => api.syncImport(doing.what, file!).then(take)}>
          <div className={s.one}>
            {doing.what === 'till' && <Text>For a till that is being reinstalled or replaced before it could send its sales: on the till, use "Export unsent sales", then load the file here.</Text>}
            <Field label={doing.what === 'sync' ? '.erpsync file from another server' : 'Till queue file (.json)'} required>
              <input type="file" accept={doing.what === 'sync' ? '.erpsync' : '.json,application/json'} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </Field>
            {doing.what === 'till' && <Text size={200} className={s.muted}>Refused items go to the <Link href="/office/review-queue">Review queue</Link>.</Text>}
          </div>
        </Ask>
      )}
      {secret && <ShownOnceDialog secret={secret} onClose={() => setSecret(null)} />}
    </div>
  )
}

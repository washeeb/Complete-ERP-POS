import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { AddRegular, PlayRegular, ShieldCheckmarkRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BackupDestinationRow, type BackupsData } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, Toggle } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, useProcStyles } from './ProcurementParts'

type Dest = { id: string | null; name: string; type: string; keep: string; is_active: boolean; config: Record<string, string> }

/**
 * System → Backups (part 22): automatic encrypted backups — where they go (destinations, each tested with a copy when
 * saved; secrets are never shown again, an empty box keeps them), how the last ones went, Back up now, Check the
 * newest backup (decrypted, read and restored into a scratch database) and Download.
 */
export function BackupsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<BackupsData | null>(null)
  const [error, setError] = useState('')
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState<'run' | 'verify' | null>(null)
  const [dest, setDest] = useState<Dest | null>(null)
  const [confirm, setConfirm] = useState(false)
  useEffect(() => { api.backups().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening backups…" /></div>
  const go = (action: 'run' | 'verify') => {
    setBusy(action)
    setNote(null)
    setError('')
    api.backupAction(action).then((x) => { setD(x.backups); setNote({ good: x.good, text: x.message }) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(null))
  }
  const open = (r: BackupDestinationRow | null) => setDest(r ? { id: r.id, name: r.name, type: r.type, keep: String(r.keep), is_active: r.active, config: { ...r.config } }
    : { id: null, name: '', type: 'folder', keep: '14', is_active: true, config: {} })
  const fields = dest ? d.fields[dest.type] ?? [] : []
  return (
    <div className={page.page}>
      <PageHead title="Backups" hint="Automatic encrypted backups: where they go, how the last ones went, and checks that they restore." classic="/admin/backups" />
      {!d.passphrase_set && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>
        There is no backup passphrase yet, so backups cannot run. On the server, in the <code>server</code> folder, run <code>php artisan erp:backup:key</code>, write the passphrase down and keep it away from the server.
      </MessageBarBody></MessageBar>}
      <div className={s.bar}>
        <span className={s.grow} />
        <Button icon={<AddRegular />} onClick={() => open(null)}>Add destination</Button>
        <Button icon={<ShieldCheckmarkRegular />} disabled={!!busy} onClick={() => go('verify')}>{busy === 'verify' ? 'Checking…' : 'Check the newest backup'}</Button>
        <Button appearance="primary" icon={<PlayRegular />} disabled={!!busy} onClick={() => setConfirm(true)}>{busy === 'run' ? 'Backing up…' : 'Back up now'}</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {note && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <Panel title="Destinations" about="Every backup is copied to each destination in use. Use at least one that is not inside the server (a USB drive you take home, cloud storage, another server).">
        <Table size="small" aria-label="Destinations" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Type</TableHeaderCell><TableHeaderCell>Keeps</TableHeaderCell><TableHeaderCell>Last copy</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.destinations.length === 0 && <TableRow><TableCell colSpan={5}><Text className={s.muted}>No destination yet: backups stay only on this server. Add one.</Text></TableCell></TableRow>}
            {d.destinations.map((x) => (
              <TableRow key={x.id}><TableCell>{x.name}{!x.active && <Text className={s.muted}> (off)</Text>}</TableCell><TableCell>{x.type_label}</TableCell><TableCell>{x.keep}</TableCell>
                <TableCell>{x.error ? <Text style={{ color: 'var(--zx-bad)' }}>{x.error}</Text> : x.last_ok ?? 'Not yet'}</TableCell>
                <TableCell><Button size="small" appearance="subtle" onClick={() => open(x)}>Change</Button></TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      <Panel title="Recent backups" about="Made every night by the scheduler; checked (decrypted, read and restored into a scratch database) every Sunday.">
        <Table size="small" aria-label="Recent backups" style={{ minWidth: 640 }}>
          <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Size</TableHeaderCell><TableHeaderCell>Copies</TableHeaderCell>
            <TableHeaderCell>Check</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.runs.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No backup yet.</Text></TableCell></TableRow>}
            {d.runs.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.when}<Text size={200} block className={s.muted}>{r.file}</Text></TableCell>
                <TableCell><Text weight="semibold" style={{ color: r.status === 'ok' ? 'var(--zx-good)' : 'var(--zx-bad)' }}>{r.status}</Text>{r.error && <Text size={200} block className={s.muted}>{r.error}</Text>}</TableCell>
                <TableCell>{r.size}</TableCell>
                <TableCell>{r.copies.map((c) => <div key={c.name}>{c.ok ? '✓' : '✗'} {c.name}</div>)}</TableCell>
                <TableCell>{r.check ?? '—'}</TableCell>
                <TableCell>{r.download && <Link href={r.download}>Download</Link>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {confirm && (
        <Ask title="Back up now?" go="Back up now" ready onClose={() => setConfirm(false)} run={async () => { setConfirm(false); go('run') }}>
          <Text>Makes an encrypted backup and copies it to every destination. It can take a few minutes on a large database.</Text>
        </Ask>
      )}
      {dest && (
        <Ask title={dest.id ? `Change ${dest.name}` : 'Add destination'} go="Test and save" width={600} ready={dest.name.trim() !== '' && !!dest.type} onClose={() => setDest(null)}
          run={() => api.saveDestination(dest.id, { name: dest.name, type: dest.type, keep: Number(dest.keep || 14), is_active: dest.is_active, config: dest.config })
            .then((x) => { setD(x.backups); setDest(null); setNote({ good: true, text: x.message }) })}>
          <div className={s.one}>
            <TextField label="Name" required maxLength={80} hint='e.g. "USB drive A", "Backblaze"' value={dest.name} onChange={(x) => setDest({ ...dest, name: x })} />
            <Choose label="Type" required options={d.types} value={dest.type} onChange={(x) => setDest({ ...dest, type: x, config: {} })}
              hint="Cloud storage and another server are the off-site backup add-on (ask SyncLytics). A folder, such as a USB drive you take home, is always included." />
            {fields.map((f) => f.key === 'private_key'
              ? <LongText key={f.key} label={f.label} value={dest.config[f.key] ?? ''} onChange={(x) => setDest({ ...dest, config: { ...dest.config, [f.key]: x } })} />
              : <TextField key={f.key} label={f.label} type={f.secret ? 'password' : 'text'} value={dest.config[f.key] ?? ''}
                hint={f.secret && dest.id ? 'Leave empty to keep the saved one.' : undefined} onChange={(x) => setDest({ ...dest, config: { ...dest.config, [f.key]: x } })} />)}
            <TextField label="Keep the newest … backups there" type="number" min={1} max={365} value={dest.keep} onChange={(x) => setDest({ ...dest, keep: x })} />
            <Toggle label="In use" value={dest.is_active} onChange={(x) => setDest({ ...dest, is_active: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}

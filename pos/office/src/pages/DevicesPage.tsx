import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Link, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar,
  MessageBarBody, Option, SearchBox, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, Tooltip, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArchiveRegular, ArrowSyncRegular, ArrowUndoRegular, CursorClickRegular, DesktopRegular, EyeRegular, HistoryRegular, MoreHorizontalRegular, ProhibitedRegular, WrenchRegular,
} from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type DeviceFix, type DeviceOptions, type DeviceRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { navigate } from '../nav'
import { Choose, TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  bad: { color: tokens.colorPaletteRedForeground1 },
  good: { color: tokens.colorPaletteGreenForeground1 },
  code: { fontFamily: tokens.fontFamilyMonospace, fontSize: tokens.fontSizeHero800, letterSpacing: '4px', display: 'block', margin: '10px 0' },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]
const TONE: Record<string, 'success' | 'warning' | 'danger'> = { active: 'success', pending: 'warning', revoked: 'danger' }
const ago = (d: string | null) => {
  if (!d) return null
  const m = Math.round((Date.now() - new Date(d).getTime()) / 60000)
  return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} days ago`
}
type Doing = { kind: 'register' } | { kind: 'code'; name: string; code: string; expires: string | null } | { kind: 'revoke' | 'fix' | 'fixes' | 'archive' | 'restore' | 'new-code'; row: DeviceRow } | { kind: 'agent' } | null

/**
 * Devices (settings and admin): the tills, handhelds, kiosks and kitchen displays, with status, when the enrolment code
 * runs out, when each was last seen, its health in plain words, and where it was last used from. Register a device
 * (its code is shown once), give a new code, revoke a lost one, send a fix, or open its screen through remote support.
 */
export function DevicesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<Doing>(str(params, 'register') === '1' ? { kind: 'register' } : null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.devices, serialize({ ...params, register: '' }), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const show = str(params, 'show')
  const finish = (r: UserDone & { code?: string; expires?: string | null }, name?: string) => {
    setNonce((n) => n + 1)
    if (r.code) setDoing({ kind: 'code', name: name ?? '', code: r.code, expires: r.expires ?? null }); else { setDoing(null); setDone(r.message) }
  }
  const columns: Column<DeviceRow>[] = [
    { key: 'name', label: 'Name', render: (r) => <><Text weight="semibold">{r.name}</Text><Text block size={200} className={s.muted}>{r.kind}</Text></> },
    { key: 'where', label: 'Branch · till', render: (r) => `${r.branch ?? '—'}${r.till ? ` · ${r.till}` : ''}` },
    { key: 'status', label: 'Status', render: (r) => <><Badge appearance="tint" color={TONE[r.status] ?? 'subtle'}>{r.status}</Badge>
      {r.open_shift && <div><Link onClick={(e) => { e.stopPropagation(); navigate(`/office/shifts/${r.open_shift!.id}`) }}>{r.open_shift.opened_by ?? 'A'}’s shift open</Link></div>}
      {r.code_expires && <Text block size={200} className={s.muted}>code until {new Date(r.code_expires).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</Text>}</> },
    { key: 'seen', label: 'Last seen', render: (r) => ago(r.last_seen) ?? <Text className={s.muted}>never</Text> },
    { key: 'health', label: 'Health', render: (r) => (r.health === null ? <Text className={s.muted}>—</Text> : (
      <Tooltip content={r.health_details ?? ''} relationship="description"><Text className={r.health === 'OK' ? s.good : s.bad}>{r.health}</Text></Tooltip>)) },
    { key: 'from', label: 'Last used from', render: (r) => <>{r.used_from ?? '—'}{r.mac && <Text block size={200} className={s.muted}>{r.mac}</Text>}</> },
    { key: 'acts', label: '', render: (r) => <span onClick={(e) => e.stopPropagation()}><Menu>
      <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Actions for ${r.name}`} /></MenuTrigger>
      <MenuPopover><MenuList>
        {r.view_url && <MenuItem icon={<EyeRegular />} onClick={() => window.open(r.view_url!, '_blank', 'noopener')}>View screen</MenuItem>}
        {r.control_url && <MenuItem icon={<CursorClickRegular />} onClick={() => window.open(r.control_url!, '_blank', 'noopener')}>Take control</MenuItem>}
        {r.can_fix && <MenuItem icon={<WrenchRegular />} onClick={() => setDoing({ kind: 'fix', row: r })}>Send a fix</MenuItem>}
        {r.has_fixes && <MenuItem icon={<HistoryRegular />} onClick={() => setDoing({ kind: 'fixes', row: r })}>Fixes</MenuItem>}
        {r.can_reissue && <MenuItem icon={<ArrowSyncRegular />} onClick={() => setDoing({ kind: 'new-code', row: r })}>New code</MenuItem>}
        {r.can_revoke && <MenuItem icon={<ProhibitedRegular />} onClick={() => setDoing({ kind: 'revoke', row: r })}>Revoke</MenuItem>}
        {r.can_archive && <MenuItem icon={<ArchiveRegular />} onClick={() => setDoing({ kind: 'archive', row: r })}>Archive</MenuItem>}
        {r.can_restore && <MenuItem icon={<ArrowUndoRegular />} onClick={() => setDoing({ kind: 'restore', row: r })}>Restore</MenuItem>}
      </MenuList></MenuPopover>
    </Menu></span> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Devices" hint="Tills, handhelds, kiosks and kitchen displays: whether each is working, and where it was last used. A device joins with a one-time code." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Device name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search devices" />
        <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Button icon={<ArrowSyncRegular />} onClick={() => setNonce((n) => n + 1)}>Refresh</Button>
        <span className={s.grow} />
        {data?.remote_available && <Button icon={<DesktopRegular />} onClick={() => setDoing({ kind: 'agent' })}>Install remote support</Button>}
        <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setDoing({ kind: 'register' }) }}>Register a device</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Devices" columns={columns} rows={data?.rows ?? []} sort="created" dir="desc" loading={loading} empty="No devices. Register a till, handheld or display to begin."
        onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      <DeviceDialogs doing={doing} remoteLink={data?.remote_link ?? null} onClose={() => setDoing(null)} onDone={finish} />
    </div>
  )
}

function DeviceDialogs({ doing, remoteLink, onClose, onDone }: { doing: Doing; remoteLink: string | null; onClose: () => void; onDone: (r: UserDone & { code?: string; expires?: string | null }, name?: string) => void }) {
  const s = useStyles()
  const [why, setWhy] = useState('')
  const [command, setCommand] = useState('')
  const [fixes, setFixes] = useState<DeviceFix[] | null>(null)
  const { guard, dialog } = useReconfirm('Changing devices is protected.')
  useEffect(() => { setWhy(''); setCommand(''); setFixes(null); if (doing?.kind === 'fixes') void api.deviceFixes(doing.row.id).then((r) => setFixes(r.rows)) }, [doing])
  if (!doing) return null
  if (dialog) return dialog
  if (doing.kind === 'register') return <RegisterDialog onClose={onClose} onDone={onDone} />
  if (doing.kind === 'code') {
    return (
      <Dialog open onOpenChange={(_, d) => !d.open && onClose()}><DialogSurface style={{ maxWidth: 480 }}><DialogBody>
        <DialogTitle>Enrolment code for {doing.name}</DialogTitle>
        <DialogContent>
          <span className={s.code}>{doing.code}</span>
          <Text block>Shown once. Enter it on the device{doing.expires ? ` before ${new Date(doing.expires).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}` : ''}. If it is lost, give a new code.</Text>
        </DialogContent>
        <DialogActions><Button appearance="primary" onClick={onClose}>Done</Button></DialogActions>
      </DialogBody></DialogSurface></Dialog>
    )
  }
  if (doing.kind === 'agent') {
    return (
      <Dialog open onOpenChange={(_, d) => !d.open && onClose()}><DialogSurface style={{ maxWidth: 600 }}><DialogBody>
        <DialogTitle>Install remote support</DialogTitle>
        <DialogContent>
          <Text block style={{ marginBottom: 8 }}>Install once on each till, back-office computer and handheld that support may need to see. The device then shows View screen and Take control here.</Text>
          {remoteLink ? <ol style={{ paddingLeft: 20, lineHeight: 1.7 }}>
            <li>On the device, open this link: <Link href={remoteLink} target="_blank" rel="noopener" style={{ wordBreak: 'break-all' }}>{remoteLink}</Link></li>
            <li><b>Windows</b> (tills, back-office PCs): download the agent and choose <i>Install</i>. It runs in the background and starts with the computer.</li>
            <li><b>Android</b> (handhelds, tablets): install <i>MeshAgent</i> from Google Play, then scan the QR code shown on the link page, and allow screen sharing when asked.</li>
            <li>Open the till once on the device, so this page learns its computer name.</li>
          </ol> : <Text block>No install link yet: support adds this company's device group link under Settings → Remote access (installation guide, chapter 20).</Text>}
          <Text block size={200} className={s.muted}>Whether the person at the device must accept each connection follows the branch's Remote connections setting. Every connection is in the audit log and recorded on the relay.</Text>
        </DialogContent>
        <DialogActions><Button onClick={onClose}>Close</Button></DialogActions>
      </DialogBody></DialogSurface></Dialog>
    )
  }
  const r = doing.row
  if (doing.kind === 'fixes') {
    return (
      <Dialog open onOpenChange={(_, d) => !d.open && onClose()}><DialogSurface style={{ maxWidth: 720 }}><DialogBody>
        <DialogTitle>Fixes sent to {r.name}</DialogTitle>
        <DialogContent>{fixes === null ? <Text>Reading…</Text> : (
          <Table size="small" aria-label="Fixes"><TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>By</TableHeaderCell><TableHeaderCell>Result</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{fixes.map((f, i) => <TableRow key={i}><TableCell>{f.when ? new Date(f.when).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</TableCell>
              <TableCell>{f.what}</TableCell><TableCell>{f.by ?? '—'}</TableCell><TableCell>{f.status}{f.result ? `: ${f.result.slice(0, 200)}` : ''}</TableCell></TableRow>)}</TableBody></Table>
        )}</DialogContent>
        <DialogActions><Button onClick={onClose}>Close</Button></DialogActions>
      </DialogBody></DialogSurface></Dialog>
    )
  }
  if (doing.kind === 'fix') return <FixDialog row={r} command={command} setCommand={setCommand} why={why} setWhy={setWhy} onClose={onClose} onDone={onDone} />
  const conf = {
    'new-code': { title: `New code for ${r.name}?`, go: 'Give a new code', text: 'The old code stops working.', needs: false },
    revoke: { title: `Revoke ${r.name}?`, go: 'Revoke', text: 'The device and everyone signed in on it are signed out immediately. A revoked device must be registered again.', needs: true },
    archive: { title: `Archive ${r.name}?`, go: 'Archive', text: r.archive_blockers.length ? `It cannot be archived yet. ${r.archive_blockers.join(' ')}` : 'It leaves this list. Its history stays. You can restore it at any time.', needs: true },
    restore: { title: `Restore ${r.name}?`, go: 'Restore', text: 'It comes back into this list.', needs: false },
  }[doing.kind]
  const blocked = doing.kind === 'archive' && r.archive_blockers.length > 0
  return (
    <Ask title={conf.title} go={conf.go} ready={!blocked && (!conf.needs || why.trim().length >= 3)} onClose={onClose}
      run={guard(async () => onDone(await api.deviceAction(r.id, doing.kind as 'new-code', conf.needs ? { reason: why } : {}), r.name))}>
      <Text block>{conf.text}</Text>
      {doing.kind === 'revoke' && r.open_shift && (
        <MessageBar intent="warning" style={{ marginTop: 10 }}><MessageBarBody>
          {r.open_shift.opened_by ?? 'A'}’s shift is still open on this till (since {new Date(r.open_shift.opened_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}).
          Revoking does not close it: the till added in its place can continue it with a manager’s PIN, or a manager{' '}
          <Link onClick={() => navigate(`/office/shifts/${r.open_shift!.id}`)}>closes it here</Link> with the cash counted.
        </MessageBarBody></MessageBar>
      )}
      {conf.needs && !blocked && <div style={{ marginTop: 10 }}><TextField label={doing.kind === 'revoke' ? 'Why (lost, stolen, replaced…)' : 'Why'} required value={why} maxLength={255} onChange={setWhy} /></div>}
    </Ask>
  )
}

function FixDialog({ row, command, setCommand, why, setWhy, onClose, onDone }: {
  row: DeviceRow; command: string; setCommand: (v: string) => void; why: string; setWhy: (v: string) => void; onClose: () => void; onDone: (r: UserDone) => void
}) {
  const [opts, setOpts] = useState<DeviceOptions | null>(null)
  useEffect(() => { void api.deviceOptions(null).then(setOpts) }, [])
  return (
    <Ask title={`Send a fix to ${row.name}`} go="Send the fix" ready={!!command} onClose={onClose} run={async () => onDone(await api.deviceAction(row.id, 'fix', { command, reason: why || null }))}>
      <Text block style={{ marginBottom: 10 }}>The till does it within about 5 minutes (at its next health check) and reports back. Unsent sales are never touched.</Text>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <Choose label="What to do" required options={opts?.commands ?? []} value={command} onChange={setCommand} />
        <TextField label="Why (optional)" value={why} maxLength={255} onChange={setWhy} />
      </div>
    </Ask>
  )
}

function RegisterDialog({ onClose, onDone }: { onClose: () => void; onDone: (r: UserDone & { code?: string; expires?: string | null }, name?: string) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ branch_id: '', till_id: '', name: '', kind: 'pos' })
  const [opts, setOpts] = useState<DeviceOptions | null>(null)
  const { guard, dialog } = useReconfirm('Registering devices is protected.')
  useEffect(() => { void api.deviceOptions(v.branch_id || null).then(setOpts) }, [v.branch_id])
  if (dialog) return dialog
  return (
    <Ask title="Register a device" go="Register" ready={!!v.branch_id && v.name.trim() !== '' && !!v.kind} onClose={onClose}
      run={guard(async () => onDone(await api.registerDevice({ ...v, till_id: v.till_id || null }), v.name))}>
      <div className={s.form}>
        <Choose label="Branch" required options={opts?.branches ?? []} value={v.branch_id} onChange={(x) => setV({ ...v, branch_id: x, till_id: '' })} />
        <Choose label="Till (for POS devices)" options={opts?.tills ?? []} value={v.till_id} onChange={(x) => setV({ ...v, till_id: x })} />
        <TextField label="Name" required hint={'e.g. "Front counter PC" or "Handheld 2"'} value={v.name} maxLength={80} onChange={(x) => setV({ ...v, name: x })} />
        <Choose label="Kind" required options={opts?.kinds ?? []} value={v.kind} onChange={(x) => setV({ ...v, kind: x })} />
      </div>
    </Ask>
  )
}

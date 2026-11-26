import { Badge, Button, Checkbox, makeStyles, MessageBar, MessageBarBody, SearchBox, Spinner, Switch, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import { api, ApiError, type RoleDetail, type RoleForm, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 16px', marginBottom: '12px' },
  fields: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 240px), 1fr))', gap: '10px 14px' },
  head: { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  area: { marginBottom: '10px' },
  perms: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 320px), 1fr))', gap: '0 12px', alignItems: 'start' },
  muted: { color: tokens.colorNeutralForeground3 },
  check: { alignItems: 'flex-start' },
})

/**
 * One role (settings and admin), or a new one: name, level, description, and its permissions by area. Change it on
 * the page and Save (with a reason). You can only add permissions you hold company-wide yourself; a technical (IT)
 * role holds technical permissions only, and that is chosen when the role is added. A change by IT is sent to the
 * owner or SyncLytics support to approve.
 */
export function RolePage({ id }: { id: string | null }) {
  const page = usePageStyles()
  const s = useStyles()
  const [role, setRole] = useState<RoleDetail | null>(null)
  const [form, setForm] = useState<RoleForm | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<UserDone | null>(null)
  const [nonce, setNonce] = useState(0)
  const [v, setV] = useState({ name: '', description: '', security_level: '2', is_technical: false, reason: '' })
  const [keys, setKeys] = useState<string[]>([])
  const [filter, setFilter] = useState('')
  const [onlyOn, setOnlyOn] = useState(false)
  const [doing, setDoing] = useState<'archive' | 'restore' | null>(null)
  const { guard, dialog } = useReconfirm('Changing what a role may do is protected.')
  useEffect(() => { api.roleForm().then(setForm).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  useEffect(() => {
    if (!id) return
    api.role(id).then((d) => {
      setRole(d.role); setError('')
      setV({ name: d.role.name, description: d.role.description ?? '', security_level: String(d.role.level), is_technical: d.role.technical, reason: '' })
      setKeys(d.role.keys)
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, nonce])

  const editable = id ? !!role?.can_edit : true
  const areas = useMemo(() => {
    const t = filter.trim().toLowerCase()
    return (form?.areas ?? []).map((a) => ({ ...a, permissions: a.permissions.filter((p) => (!t || p.label.toLowerCase().includes(t) || p.key.includes(t) || a.area.toLowerCase().includes(t))
      && (!onlyOn || keys.includes(p.key))) })).filter((a) => a.permissions.length > 0)
  }, [form, filter, onlyOn, keys])

  if (!form || (id && !role)) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the role…" />}</div>
  const levels = role && !form.levels.some((l) => l.id === String(role.level)) ? [...form.levels, { id: String(role.level), label: `L${role.level}` }] : form.levels
  const toggle = (k: string, on: boolean) => setKeys(on ? [...keys, k] : keys.filter((x) => x !== k))
  const save = guard(async () => {
    setDone(null); setError('')
    try {
      const r = await api.saveRole(id, { name: v.name, description: v.description || null, security_level: Number(v.security_level), permissions: keys, reason: v.reason || null,
        ...(id ? {} : { is_technical: v.is_technical }) })
      if (!id && r.role) { navigate(r.role.open); return }
      setDone(r); if (!r.pending) setNonce((n) => n + 1)
    } catch (e) {
      if (e instanceof ApiError && e.code === 'reconfirm') throw e
      setError(e instanceof Error ? e.message : String(e))
    }
  })

  return (
    <div className={page.page}>
      <PageHead title={role ? role.name : 'Add a role'} hint="What the role may do and its level. Only people cleared to this level or higher can hold it." />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/roles')}>Roles</Button>
        <span className={s.grow} />
        {role?.can_archive && <Button icon={<ArchiveRegular />} onClick={() => setDoing('archive')}>Archive</Button>}
        {role?.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => setDoing('restore')}>Restore</Button>}
        {editable && <Button appearance="primary" icon={<SaveRegular />} disabled={v.name.trim() === ''} onClick={() => void save()}>{id ? 'Save' : 'Add the role'}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent={done.pending ? 'info' : 'success'} style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      {role && !role.can_edit && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>You can see this role but not change it.</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="About the role">
        <div className={s.fields}>
          <TextField label="Name" required disabled={!editable} value={v.name} maxLength={80} onChange={(x) => setV({ ...v, name: x })} />
          <Choose label="Level" required disabled={!editable} options={levels} value={v.security_level} onChange={(x) => setV({ ...v, security_level: x })} />
          <TextField label="Description" disabled={!editable} value={v.description} maxLength={255} onChange={(x) => setV({ ...v, description: x })} />
          {id && editable && <TextField label="Reason for change" value={v.reason} maxLength={255} onChange={(x) => setV({ ...v, reason: x })} />}
        </div>
        <div style={{ marginTop: 10, display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'center' }}>
          {id ? <Text className={s.muted}>{role?.technical ? 'Technical role (IT): technical permissions only.' : 'Business role.'} {role?.seeded ? 'Seeded with the system.' : ''} Held by {role?.holders ?? 0} {role?.holders === 1 ? 'person' : 'people'} now.</Text>
            : <Switch label="Technical role (IT): may hold only technical permissions" checked={v.is_technical} onChange={(_, d) => setV({ ...v, is_technical: d.checked })} />}
          {role && <Badge appearance="tint" color={role.archived ? 'subtle' : 'success'}>{role.archived ? 'Archived' : 'In use'}</Badge>}
        </div>
      </section>
      <section className={s.panel} aria-label="Permissions">
        <div className={s.head}>
          <Text weight="semibold" size={400}>Permissions ({keys.length})</Text>
          <span className={s.grow} />
          <SearchBox placeholder="Find a permission" value={filter} onChange={(_, d) => setFilter(d.value)} aria-label="Find a permission" />
          <Switch label="Only the ones it has" checked={onlyOn} onChange={(_, d) => setOnlyOn(d.checked)} />
        </div>
        {editable && <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>You can only add permissions you hold company-wide yourself.</Text>}
        {areas.length === 0 && <Text className={s.muted}>No permission matches.</Text>}
        {areas.map((a) => (
          <div key={a.area} className={s.area}>
            <Text weight="semibold" block style={{ textTransform: 'capitalize' }}>{a.area.replaceAll('_', ' ')}</Text>
            <div className={s.perms}>{a.permissions.map((p) => (
              <Checkbox key={p.key} className={s.check} disabled={!editable} checked={keys.includes(p.key)} onChange={(_, d) => toggle(p.key, !!d.checked)}
                label={<span>{p.label}<Text block size={100} className={s.muted}>{p.key} · min L{p.min_level}{p.technical ? ' · technical' : ''}</Text></span>} />
            ))}</div>
          </div>
        ))}
      </section>
      {dialog}
      {doing && role && <RoleArchive role={role} restore={doing === 'restore'} onClose={() => setDoing(null)} onDone={(r) => { setDoing(null); setDone(r); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function RoleArchive({ role, restore, onClose, onDone }: { role: RoleDetail; restore: boolean; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [why, setWhy] = useState('')
  const blocked = !restore && role.archive_blockers.length > 0
  return (
    <Ask title={`${restore ? 'Restore' : 'Archive'} ${role.name}?`} go={restore ? 'Restore' : 'Archive'} ready={!blocked && (restore || why.trim().length >= 3)} onClose={onClose}
      run={async () => onDone(await api.roleAction(role.id, restore ? 'restore' : 'archive', restore ? {} : { reason: why }))}>
      <Text block>{restore ? 'It comes back into the lists people choose from.' : blocked ? `It cannot be archived yet. ${role.archive_blockers.join(' ')}`
        : 'It leaves every list people choose from, so nothing new is given this role. Its history stays: the audit log keeps showing it. You can restore it at any time.'}</Text>
      {!restore && !blocked && <div style={{ marginTop: 10 }}><TextField label="Why" required value={why} maxLength={255} onChange={setWhy} /></div>}
    </Ask>
  )
}

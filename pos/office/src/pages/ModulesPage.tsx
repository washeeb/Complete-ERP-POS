import { Badge, Button, Input, makeStyles, MessageBar, MessageBarBody, Spinner, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ModuleItem, type ModulesData, type UserDone } from '../api'
import { Choose } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  branch: { flex: '0 1 320px' },
  reason: { flex: '1 1 220px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4,
    padding: '12px 16px', marginBottom: '12px' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 380px), 1fr))', gap: '14px 20px', marginTop: '8px' },
  mod: { display: 'flex', flexDirection: 'column', gap: '4px' },
  toggles: { display: 'flex', gap: '4px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const LABEL: Record<string, string> = { enabled: 'Enabled', reserved: 'Reserved', disabled: 'Off' }

/**
 * Modules per branch (settings and admin): choose a branch, then switch each industry or department module on, off,
 * or reserved (a module not yet available can only be reserved). Only modules the licence covers show. A branch runs
 * one industry; the server says what must happen first when a change cannot be made.
 */
export function ModulesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [data, setData] = useState<ModulesData | null>(null)
  const [branch, setBranch] = useState<string | null>(null)
  const [changes, setChanges] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [done, setDone] = useState<UserDone | null>(null)
  const [busy, setBusy] = useState(false)
  const [nonce, setNonce] = useState(0)
  const { guard, dialog } = useReconfirm('Changing modules is protected.')
  useEffect(() => { // leaving with unsaved changes asks first, as on the current page
    if (Object.keys(changes).length === 0) return
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [Object.keys(changes).length > 0])
  useEffect(() => {
    api.modules(branch).then((d) => { setData(d); setChanges({}); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [branch, nonce])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the modules…" />}</div>
  const statusOf = (m: ModuleItem) => changes[m.key] ?? m.status
  const put = (m: ModuleItem, v: string) => setChanges((c) => { const n = { ...c }; if (v === m.status) delete n[m.key]; else n[m.key] = v; return n })
  const count = Object.keys(changes).length
  const save = guard(async () => {
    if (!data.branch_id) return
    setBusy(true); setError(''); setDone(null)
    try { setDone(await api.saveModules(data.branch_id, changes, reason || null)); setReason(''); setNonce((n) => n + 1) } catch (e) {
      if (e instanceof Error && 'code' in e && (e as { code: string }).code === 'reconfirm') throw e
      setError(e instanceof Error ? e.message : String(e))
    } finally { setBusy(false) }
  })

  return (
    <div className={page.page}>
      <PageHead title="Modules per branch" hint="Switch industry and department modules on or off for one branch at a time. Core (company, users, roles, devices, audit, settings) is always installed." />
      <div className={s.bar}>
        <div className={s.branch}><Choose label="Branch" required options={data.branches} value={data.branch_id ?? ''} onChange={(v) => { setDone(null); setBranch(v) }} /></div>
        <Input className={s.reason} placeholder="Reason for change (optional)" value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} aria-label="Reason for change" />
        <span className={s.grow} />
        {count > 0 && <Text className={s.muted}>{count} changed</Text>}
        <Button appearance="primary" icon={busy ? <Spinner size="tiny" /> : <SaveRegular />} disabled={busy || count === 0} onClick={() => void save()}>Save modules</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      {data.sections.map((sec) => (
        <section key={sec.label} className={s.panel} aria-label={sec.label}>
          <Text weight="semibold" size={400} block>{sec.label}</Text>
          {sec.modules.length === 0 ? <Text className={s.muted}>None in this licence.</Text> : (
            <div className={s.grid}>{sec.modules.map((m) => (
              <div key={m.key} className={s.mod}>
                <Text weight="semibold">{m.name} {m.key in changes && <Badge appearance="tint" color="warning">changed</Badge>}</Text>
                <div className={s.toggles} role="group" aria-label={m.name}>
                  {(m.available ? ['enabled', 'disabled'] : ['reserved', 'disabled']).map((v) => (
                    <ToggleButton key={v} size="small" checked={statusOf(m) === v} appearance={statusOf(m) === v ? 'primary' : 'secondary'} onClick={() => put(m, v)}>{LABEL[v]}</ToggleButton>
                  ))}
                </div>
                <Text size={200} className={s.muted}>{m.status_label} — {m.description}</Text>
              </div>
            ))}</div>
          )}
        </section>
      ))}
      {dialog}
    </div>
  )
}

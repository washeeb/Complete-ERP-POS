import {
  Accordion, AccordionHeader, AccordionItem, AccordionPanel, Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Switch, Text, Textarea, tokens,
} from '@fluentui/react-components'
import { SaveRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import { api, ApiError, type SettingDef, type SettingsData, type UserDone } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { position: 'sticky', top: 0, zIndex: 2, display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', padding: '8px 0', marginBottom: '8px',
    backgroundColor: tokens.colorNeutralBackground2 },
  search: { flex: '1 1 260px', maxWidth: '420px' },
  reason: { flex: '1 1 220px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4, padding: '4px 8px' },
  fields: { display: 'flex', flexDirection: 'column', gap: '14px', padding: '4px 4px 12px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Settings (settings and admin): every setting, by group. The groups open closed, as a short list; the search box shows
 * only the settings whose name or explanation has the words typed, across every group. Settings this person may not
 * change show greyed, with who changes them. Save sends only what changed.
 */
export function SettingsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [data, setData] = useState<SettingsData | null>(null)
  const [values, setValues] = useState<Record<string, unknown>>({})
  const [find, setFind] = useState('')
  const [reason, setReason] = useState('')
  const [open, setOpen] = useState<string[]>([])
  const [error, setError] = useState('')
  const [done, setDone] = useState<UserDone | null>(null)
  const [busy, setBusy] = useState(false)
  const [nonce, setNonce] = useState(0)
  const { guard, dialog } = useReconfirm('Changing when payments need "confirm it\'s you" is protected.')
  useEffect(() => { api.settings().then((d) => { setData(d); setValues({}); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [nonce])

  const words = find.trim().toLowerCase().split(/\s+/).filter(Boolean)
  const shown = useMemo(() => (data?.groups ?? []).map((g) => ({ ...g, settings: g.settings.filter((d) => {
    const text = `${g.group} ${d.label} ${d.help} ${(d.options ?? []).map((o) => o.label).join(' ')} ${d.key}`.toLowerCase()
    return words.every((w) => text.includes(w))
  }) })).filter((g) => g.settings.length > 0), [data, find]) // eslint-disable-line react-hooks/exhaustive-deps
  const changed = Object.keys(values).length
  useEffect(() => { // leaving with unsaved changes asks first, as on the current page
    if (!(changed > 0)) return
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [changed > 0])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the settings…" />}</div>
  const valueOf = (d: SettingDef) => (d.key in values ? values[d.key] : d.value)
  const put = (d: SettingDef, v: unknown) => setValues((x) => { const n = { ...x }; if (v === d.value) delete n[d.key]; else n[d.key] = v; return n })
  const save = guard(async () => {
    setBusy(true); setError(''); setDone(null)
    try { setDone(await api.saveSettings(values, reason || null)); setReason(''); setNonce((n) => n + 1) } catch (e) {
      if (e instanceof ApiError && e.code === 'reconfirm') throw e
      setError(e instanceof Error ? e.message : String(e))
    } finally { setBusy(false) }
  })

  const input = (d: SettingDef) => {
    const v = valueOf(d)
    const off = !d.may_change
    const hint = <>{d.help}{d.who && <Badge appearance="tint" color="informative" style={{ marginLeft: 6 }}>{d.who}</Badge>}</>
    if (d.type === 'bool') return <Field key={d.key} hint={hint}><Switch label={d.label} checked={!!v} disabled={off} onChange={(_, x) => put(d, x.checked)} /></Field>
    if (d.type === 'choice') {
      return (
        <Field key={d.key} label={d.label} hint={hint}>
          <Dropdown disabled={off} value={d.options?.find((o) => o.id === String(v))?.label ?? ''} selectedOptions={[String(v)]} onOptionSelect={(_, x) => put(d, x.optionValue ?? '')}>
            {(d.options ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          </Dropdown>
        </Field>
      )
    }
    if (d.type === 'string') {
      return (
        <Field key={d.key} label={d.label} hint={hint}>
          {d.rows ? <Textarea disabled={off} rows={d.rows} maxLength={d.max ?? undefined} value={String(v ?? '')} onChange={(_, x) => put(d, x.value)} />
            : <Input disabled={off} maxLength={d.max ?? undefined} value={String(v ?? '')} onChange={(_, x) => put(d, x.value)} />}
        </Field>
      )
    }
    return (
      <Field key={d.key} label={d.label} hint={hint}>
        <Input type="number" disabled={off} min={d.min ?? undefined} max={d.max ?? undefined} value={String(v ?? '')} style={{ maxWidth: 220 }}
          onChange={(_, x) => put(d, x.value === '' ? '' : Number(x.value))} />
      </Field>
    )
  }

  return (
    <div className={page.page}>
      <PageHead title="Settings" hint="Open a group, or type to find a setting. Settings someone else looks after show greyed, with who changes them." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Find a setting — e.g. receipt, momo, expiry, backup" value={find} onChange={(_, d) => setFind(d.value)} aria-label="Find a setting" />
        <Input className={s.reason} placeholder="Reason for change (optional)" value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} aria-label="Reason for change" />
        <span className={s.grow} />
        {changed > 0 && <Text className={s.muted}>{changed} changed</Text>}
        <Button appearance="primary" icon={busy ? <Spinner size="tiny" /> : <SaveRegular />} disabled={busy || changed === 0} onClick={() => void save()}>Save settings</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      {words.length > 0 && <Text block className={s.muted} style={{ marginBottom: 8 }}>Showing the settings with “{find.trim()}”. Clear the box to see every group.</Text>}
      <section className={s.panel} aria-label="Settings">
        {shown.length === 0 ? <Text block style={{ padding: 12 }}>No setting has those words.</Text> : (
          <Accordion key={words.join(' ') || '(all)'} multiple collapsible defaultOpenItems={words.length ? shown.map((g) => g.group) : open}
            onToggle={(_, d) => { if (!words.length) setOpen(d.openItems as string[]) }}>
            {shown.map((g) => (
              <AccordionItem key={g.group} value={g.group}>
                <AccordionHeader>{g.group} <Text size={200} className={s.muted} style={{ marginLeft: 8 }}>{g.settings.length} setting(s)</Text></AccordionHeader>
                <AccordionPanel><div className={s.fields}>{g.settings.map(input)}</div></AccordionPanel>
              </AccordionItem>
            ))}
          </Accordion>
        )}
      </section>
      {dialog}
    </div>
  )
}

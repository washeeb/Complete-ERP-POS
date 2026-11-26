import {
  Avatar, Badge, Button, Field, Input, makeStyles, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Switch, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, EditRegular, ContactCardRegular, KeyRegular, ProhibitedRegular, ShieldErrorRegular,
} from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type UserDetail, type UserDone, type UserGrant, type UserOptions } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { UserForm, useReconfirm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  who: { display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '12px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px 16px', marginBottom: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 16px', marginBottom: '12px', overflowX: 'auto' },
  head: { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap', marginBottom: '6px' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 14px', '@media (max-width: 640px)': { gridTemplateColumns: '1fr' } },
})

const TONE: Record<string, 'success' | 'warning' | 'subtle'> = { Active: 'success', Temporary: 'warning' }
type Doing = 'change' | 'pin' | 'card' | 'lost' | 'mfa' | 'archive' | 'restore' | 'grant' | { revoke: UserGrant } | null

/**
 * One user (settings and admin): their details, how they sign in, and their access (roles and where they apply).
 * Change, Set till PIN, Staff card, Card lost, Reset two-step sign-in, Archive or Restore; Grant access and Revoke.
 * A change made by IT is sent to the owner or SyncLytics support to approve, and the page says so.
 */
export function UserPage({ id }: { id: string }) {
  const page = usePageStyles()
  const s = useStyles()
  const [u, setU] = useState<UserDetail | null>(null)
  const [options, setOptions] = useState<UserOptions | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<UserDone | null>(null)
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<Doing>(null)
  const [all, setAll] = useState(false)
  useEffect(() => { api.userOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => { api.user(id).then((d) => { setU(d.user); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id, nonce])

  if (!u) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the user…" />}</div>
  const finish = (r: UserDone) => { setDoing(null); setDone(r); setNonce((n) => n + 1) }
  const fact = (label: string, value: React.ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text>{value}</Text></div>
  const grants = all ? u.grants : u.grants.filter((g) => g.current)
  const go = (d: Doing) => () => { setDone(null); setDoing(d) }

  return (
    <div className={page.page}>
      <PageHead title={u.name} hint="Their details, how they sign in, and their access: which roles they have and where those apply." />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/users')}>Users</Button>
        <span className={s.grow} />
        {u.can_edit && <>
          <Button appearance="primary" icon={<EditRegular />} onClick={go('change')}>Change</Button>
          <Button icon={<KeyRegular />} onClick={go('pin')}>Set till PIN</Button>
          <Button icon={<ContactCardRegular />} onClick={go('card')}>{u.card ? 'New staff card' : 'Staff card'}</Button>
          {u.card && <Button icon={<ProhibitedRegular />} style={{ color: 'var(--zx-bad)' }} onClick={go('lost')}>Card lost</Button>}
        </>}
        {u.can_reset_mfa && <Button icon={<ShieldErrorRegular />} style={{ color: 'var(--zx-bad)' }} onClick={go('mfa')}>Reset two-step sign-in</Button>}
        {u.can_archive && <Button icon={<ArchiveRegular />} onClick={go('archive')}>Archive</Button>}
        {u.can_restore && <Button icon={<ArrowUndoRegular />} onClick={go('restore')}>Restore</Button>}
      </div>
      {done && <MessageBar intent={done.pending ? 'info' : 'success'} style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      <div className={s.who}>
        <Avatar size={48} name={u.name} image={{ src: u.photo }} />
        <div><Text weight="semibold" size={500} block>{u.name}</Text><Text className={s.muted}>{u.support ? 'SyncLytics support (Superadmin)' : u.username}</Text></div>
      </div>
      <div className={s.facts}>
        {fact('Username', <Text font="monospace">{u.username}</Text>)}
        {fact('E-mail', u.email)}
        {fact('Mobile (WhatsApp)', u.phone ?? '—')}
        {fact('Security level', `L${u.level}`)}
        {fact('Signs in with', u.signs_in)}
        {fact('Two-step sign-in', u.mfa ? 'Set up' : 'Not set up')}
        {fact('Till PIN', u.pin ? 'Set' : 'None')}
        {fact('Staff card', u.card ? 'Printed' : 'None')}
        {fact('Last till sign-in', u.last_till ? new Date(u.last_till).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')}
        {fact('Status', <Badge appearance="tint" color={u.archived ? 'subtle' : 'success'}>{u.archived ? 'Archived' : 'In use'}</Badge>)}
      </div>

      <section className={s.panel} aria-label="Access">
        <div className={s.head}>
          <Text weight="semibold" size={400}>Access (roles and where they apply)</Text>
          <span className={s.grow} />
          <Switch label="Show ended ones too" checked={all} onChange={(_, d) => setAll(d.checked)} />
          {options?.can_grant && <Button appearance="primary" icon={<AddRegular />} onClick={go('grant')}>Grant access</Button>}
        </div>
        {grants.length === 0 ? <Text className={s.muted}>{u.grants.length ? 'No current access. Switch on "Show ended ones too" for the past.' : 'No access yet. Grant a role so they can work.'}</Text> : (
          <Table size="small" aria-label="Access" style={{ minWidth: 820 }}>
            <TableHeader><TableRow><TableHeaderCell>Role</TableHeaderCell><TableHeaderCell>Applies to</TableHeaderCell><TableHeaderCell>Limits</TableHeaderCell>
              <TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Granted by</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>{grants.map((g) => (
              <TableRow key={g.id}>
                <TableCell>{g.role ?? '—'}</TableCell>
                <TableCell>{g.applies_to}</TableCell>
                <TableCell>{g.limits}</TableCell>
                <TableCell><Badge appearance="tint" color={TONE[g.status] ?? 'subtle'}>{g.status}</Badge>
                  {g.expires && <Text block size={200} className={s.muted}>until {new Date(g.expires).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</Text>}</TableCell>
                <TableCell>{g.by ?? '—'}{g.reason && <Text block size={200} className={s.muted}>{g.reason}</Text>}</TableCell>
                <TableCell>{g.can_revoke && <Button size="small" appearance="subtle" icon={<ProhibitedRegular />} onClick={go({ revoke: g })}>Revoke</Button>}</TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </section>

      <section className={s.panel} aria-label="Name history">
        <Text weight="semibold" size={400} block>Name history</Text>
        {u.history.length === 0 ? <Text className={s.muted}>This account has never been renamed.</Text> : (
          <Table size="small" aria-label="Name history">
            <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Changed by</TableHeaderCell><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Username</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{u.history.map((h, i) => (
              <TableRow key={i}><TableCell>{h.at}</TableCell><TableCell>{h.by}</TableCell><TableCell>{h.name ? `${h.name[0]} → ${h.name[1]}` : '—'}</TableCell>
                <TableCell>{h.username ? `${h.username[0]} → ${h.username[1]}` : '—'}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        )}
      </section>

      {doing === 'change' && options && <UserForm options={options} user={u} save={(b) => api.saveUser(u.id, b)} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'pin' && <PinDialog user={u} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'card' && options && <CardDialog user={u} layouts={options.card_layouts} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'lost' && <Simple title={`${u.name}'s card is lost?`} go="Stop the card" text="The card stops working at once. They sign in with their username and PIN until a new card is printed."
        run={() => api.userAction(u.id, 'card-lost')} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'mfa' && <Simple title={`Reset ${u.name}'s two-step sign-in?`} go="Reset it" reason="Why (kept in the audit log)"
        text="For someone who lost their phone or deleted the app. Their authenticator and recovery codes are cleared and they are signed out; at their next sign-in they scan a new code. Check it is really them first, by phone or in person."
        run={(reason) => api.userAction(u.id, 'reset-two-step', { reason })} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'archive' && (u.archive_blockers.length ? <Simple title={`Archive ${u.name}?`} go="Archive" text={`It cannot be archived yet. ${u.archive_blockers.join(' ')}`} blocked
        run={async () => ({ message: '' })} onClose={() => setDoing(null)} onDone={finish} /> : <Simple title={`Archive ${u.name}?`} go="Archive" reason="Why"
        text="They leave every list people choose from and can no longer sign in. Their history stays: past sales and the audit log keep showing them. You can restore them at any time."
        run={(reason) => api.userAction(u.id, 'archive', { reason })} onClose={() => setDoing(null)} onDone={finish} />)}
      {doing === 'restore' && <Simple title={`Restore ${u.name}?`} go="Restore" text="They come back into the lists people choose from."
        run={() => api.userAction(u.id, 'restore')} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'grant' && options && <GrantDialog user={u} options={options} onClose={() => setDoing(null)} onDone={finish} />}
      {doing && typeof doing === 'object' && <Simple title={`Revoke ${doing.revoke.role ?? 'this access'}?`} go="Revoke" reason="Why"
        text={`${u.name} loses ${doing.revoke.role ?? 'this role'} (${doing.revoke.applies_to}) at once.`}
        run={(reason) => api.revokeAccess(u.id, doing.revoke.id, reason)} onClose={() => setDoing(null)} onDone={finish} />}
    </div>
  )
}

/** A yes/no with an optional reason; confirms it is you first when the server asks. */
function Simple({ title, go, text, reason, blocked, run, onClose, onDone }: {
  title: string; go: string; text: string; reason?: string; blocked?: boolean; run: (reason: string) => Promise<UserDone>; onClose: () => void; onDone: (r: UserDone) => void
}) {
  const [why, setWhy] = useState('')
  const { guard, dialog } = useReconfirm('Changing who can sign in is protected.')
  if (dialog) return dialog
  return (
    <Ask title={title} go={go} ready={!blocked && (!reason || why.trim().length >= 3)} onClose={onClose} run={guard(async () => onDone(await run(why)))}>
      <Text block>{text}</Text>
      {reason && !blocked && <div style={{ marginTop: 10 }}><TextField label={reason} required value={why} maxLength={255} onChange={setWhy} /></div>}
    </Ask>
  )
}

function PinDialog({ user, onClose, onDone }: { user: UserDetail; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [pin, setPin] = useState('')
  const { guard, dialog } = useReconfirm('Changing who can sign in is protected.')
  if (dialog) return dialog
  return (
    <Ask title={`Set ${user.name}'s till PIN`} go="Set the PIN" ready={/^\d{4,8}$/.test(pin)} onClose={onClose} run={guard(async () => onDone(await api.userAction(user.id, 'pin', { pin })))}>
      <Text block style={{ marginBottom: 10 }}>4 to 8 digits. Tell the person privately; they sign in on a till with their username (or card) and this PIN. Setting a PIN also clears a PIN lockout.</Text>
      <Field label="New PIN" required><Input type="password" inputMode="numeric" autoComplete="new-password" maxLength={8} value={pin} onChange={(_, d) => setPin(d.value.replace(/\D/g, ''))} /></Field>
    </Ask>
  )
}

function CardDialog({ user, layouts, onClose, onDone }: { user: UserDetail; layouts: { id: string; label: string }[]; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [layout, setLayout] = useState(layouts[0]?.id ?? 'a4')
  const { guard, dialog } = useReconfirm('Changing who can sign in is protected.')
  if (dialog) return dialog
  return (
    <Ask title={user.card ? `New staff card for ${user.name}` : `Staff card for ${user.name}`} go="Make the card" ready onClose={onClose}
      run={guard(async () => { const r = await api.userAction(user.id, 'card', { layout }); if (r.print) window.open(r.print, '_blank'); onDone(r) })}>
      <Text block style={{ marginBottom: 10 }}>{user.card ? 'Their current card stops working at once. ' : ''}The card is scanned on the till instead of typing the username. It opens in a new tab, ready to print; that page works once.</Text>
      <Field label="Print on" required>
        <RadioGroup value={layout} onChange={(_, d) => setLayout(d.value)}>{layouts.map((l) => <Radio key={l.id} value={l.id} label={l.label} />)}</RadioGroup>
      </Field>
    </Ask>
  )
}

/** Grant a role: company-wide or one place, optional limits in money and percent, optionally temporary (with a reason). */
function GrantDialog({ user, options, onClose, onDone }: { user: UserDetail; options: UserOptions; onClose: () => void; onDone: (r: UserDone) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ role_id: '', scope_type: '', scope_id: '', limit_po: '', limit_expense: '', limit_credit: '', limit_discount: '', expires_at: '', reason: '' })
  const put = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x, ...(k === 'scope_type' ? { scope_id: '' } : {}) })
  const { guard, dialog } = useReconfirm('Granting access is protected.')
  const place = v.scope_type && v.scope_type !== 'tenant'
  const ready = !!v.role_id && !!v.scope_type && (!place || !!v.scope_id) && (!v.expires_at || v.reason.trim() !== '')
  if (dialog) return dialog
  return (
    <Ask title={`Grant access to ${user.name}`} go="Grant" ready={ready} onClose={onClose} width={680}
      run={guard(async () => onDone(await api.userAction(user.id, 'access', { ...v, scope_id: place ? v.scope_id : null, expires_at: v.expires_at ? new Date(v.expires_at).toISOString() : null,
        reason: v.reason || null, limit_po: v.limit_po || null, limit_expense: v.limit_expense || null, limit_credit: v.limit_credit || null, limit_discount: v.limit_discount || null })))}>
      <div className={s.form}>
        <div className={s.two}>
          <Choose label="Role" required options={options.roles} value={v.role_id} onChange={put('role_id')} />
          <Choose label="Applies to" required options={options.scopes} value={v.scope_type} onChange={put('scope_type')} />
          {place && <Choose label="Which one" required options={options.places[v.scope_type] ?? []} value={v.scope_id} onChange={put('scope_id')} />}
        </div>
        <Text weight="semibold">Limits (optional: empty uses the role's usual limits)</Text>
        <div className={s.two}>
          <TextField label="Approves orders up to" type="number" min={0} prefix={options.currency} value={v.limit_po} onChange={put('limit_po')} />
          <TextField label="Approves expenses up to" type="number" min={0} prefix={options.currency} value={v.limit_expense} onChange={put('limit_expense')} />
          <TextField label="Lets a customer go over their credit by up to" type="number" min={0} prefix={options.currency} value={v.limit_credit} onChange={put('limit_credit')} />
          <TextField label="Gives discounts up to (%)" type="number" min={0} max={100} value={v.limit_discount} onChange={put('limit_discount')} />
          <TextField label="Temporary until (optional)" type="datetime-local" value={v.expires_at} onChange={put('expires_at')} />
          <TextField label="Reason" required={!!v.expires_at} hint="Required for temporary access." value={v.reason} maxLength={255} onChange={put('reason')} />
        </div>
      </div>
    </Ask>
  )
}

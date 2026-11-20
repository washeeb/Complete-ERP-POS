import { Field, Input, makeStyles, Text, tokens } from '@fluentui/react-components'
import { useState } from 'react'
import { ApiError, type UserDetail, type UserDone, type UserOptions } from '../api'
import { Ask } from '../form/Ask'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { Choose, TextField } from '../form/Fields'

const useStyles = makeStyles({
  two: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 14px', '@media (max-width: 640px)': { gridTemplateColumns: '1fr' } },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Run a user change; when the server asks the person to confirm it is them first, show that and run it again after.
 * Returns the guarded function and the confirmation to draw (null when none is waiting).
 */
export function useReconfirm(why: string) {
  const [pending, setPending] = useState<{ url: string; again: () => Promise<unknown> } | null>(null)
  const guard = (fn: () => Promise<unknown>) => async () => {
    try {
      await fn()
    } catch (e) {
      if (e instanceof ApiError && e.code === 'reconfirm' && e.url) { setPending({ url: e.url, again: fn }); return }
      throw e
    }
  }
  const dialog = pending ? <ConfirmItsYou url={pending.url} why={why} go="Do it now" onCancel={() => setPending(null)}
    onGo={() => { const again = pending.again; setPending(null); void again().catch(() => undefined) }} /> : null
  return { guard, dialog }
}

/** Add a user, or change one: name, username, e-mail, WhatsApp number, how they sign in, level, password. */
export function UserForm({ options, user, onClose, onDone, save }: {
  options: UserOptions; user: UserDetail | null; onClose: () => void; onDone: (r: UserDone) => void; save: (body: Record<string, unknown>) => Promise<UserDone>
}) {
  const s = useStyles()
  const [v, setV] = useState({ name: user?.name ?? '', username: user?.username ?? '', email: user?.email ?? '', phone: user?.phone ?? '', signin_method: user?.signin_method ?? 'default',
    security_level: String(user?.level ?? 2), password: '', reason: '' })
  const put = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x })
  const { guard, dialog } = useReconfirm('Changing who can sign in is protected.')
  const levels = user && !options.levels.some((l) => l.id === String(user.level)) ? [...options.levels, { id: String(user.level), label: `L${user.level}` }] : options.levels
  const ready = v.name.trim() !== '' && /^[a-z0-9._-]+$/i.test(v.username) && v.email.includes('@') && (user ? v.password === '' || v.password.length >= 12 : v.password.length >= 12)
  if (dialog) return dialog
  return (
    <Ask title={user ? `Change ${user.name}` : 'Add a user'} go={user ? 'Save' : 'Add'} ready={ready} onClose={onClose} width={680}
      run={guard(async () => onDone(await save({ ...v, security_level: Number(v.security_level), phone: v.phone || null, password: v.password || null, reason: v.reason || null })))}>
      <div className={s.two}>
        <TextField label="Full name" required value={v.name} maxLength={120} onChange={put('name')} />
        <TextField label="Username" required hint="Used to sign in on a till and in the back office." value={v.username} maxLength={60} onChange={put('username')} />
        <TextField label="E-mail" required value={v.email} maxLength={255} onChange={put('email')} />
        <TextField label="Mobile (WhatsApp) number" hint="Where sign-in codes go by WhatsApp. Without it, codes go by e-mail." value={v.phone} maxLength={30} onChange={put('phone')} />
        <Choose label="How they sign in to the back office" required options={options.signin_methods} value={v.signin_method} onChange={put('signin_method')}
          hint={`Password only is allowed up to supervisors (L${options.password_only_max}). Tills use the PIN.`} />
        <Choose label="Security level (ceiling)" required options={levels} value={v.security_level} onChange={put('security_level')} hint="Roles above this level never apply to them, even if granted." />
        <Field label={user ? 'New password (leave blank to keep)' : 'Password'} required={!user} hint="At least 12 characters.">
          <Input type="password" autoComplete="new-password" value={v.password} onChange={(_, d) => put('password')(d.value)} />
        </Field>
        {user && <TextField label="Reason for change" value={v.reason} maxLength={255} onChange={put('reason')} />}
      </div>
      <Text block size={200} className={s.muted} style={{ marginTop: 10 }}>{user ? 'Changes by IT are sent to the owner or SyncLytics support to approve first.' : 'Next, grant their access (roles and where they apply).'}</Text>
    </Ask>
  )
}

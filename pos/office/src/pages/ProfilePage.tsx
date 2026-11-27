import {
  Avatar, Badge, Button, Checkbox, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Table, TableBody, TableCell, TableHeader,
  TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArrowDownloadRegular, CopyRegular, DeleteRegular, FingerprintRegular, KeyRegular, LockClosedRegular, PersonRegular, SaveRegular, ShieldCheckmarkRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, type ProfileData, type ProfileSave } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

const useStyles = makeStyles({
  layout: { display: 'grid', gridTemplateColumns: 'minmax(0, 240px) minmax(0, 1fr)', gap: '20px', alignItems: 'start', '@media (max-width: 900px)': { gridTemplateColumns: '1fr' } },
  nav: { position: 'sticky', top: '12px', display: 'grid', gap: '4px', '@media (max-width: 900px)': { position: 'static' } },
  navLink: { display: 'grid', padding: '8px 10px', borderRadius: tokens.borderRadiusMedium, cursor: 'pointer', ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover } },
  help: { marginTop: '12px', padding: '12px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, display: 'grid', gap: '4px', minWidth: 0, overflowWrap: 'anywhere' },
  photo: { display: 'flex', alignItems: 'center', gap: '16px', flexWrap: 'wrap', marginBottom: '12px' },
  looks: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '8px' },
  strength: { height: '6px', borderRadius: '3px', backgroundColor: tokens.colorNeutralBackground5, overflow: 'hidden', marginTop: '6px' },
  codes: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '4px 12px', fontFamily: tokens.fontFamilyMonospace, padding: '10px',
    borderRadius: tokens.borderRadiusMedium, backgroundColor: tokens.colorNeutralBackground3 },
  qr: { display: 'block', width: '192px', height: '192px', margin: '0 auto', backgroundColor: '#fff', padding: '8px', borderRadius: tokens.borderRadiusMedium },
  row: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', padding: '8px 0', borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

const PARTS = [['you', 'Your profile', 'Photo, name and e-mail address'], ['look', 'Look', 'How the back office looks for you'], ['prefs', 'Preferences', 'Business Portal, lists, light or dark, dates'],
  ['notify', 'Notifications', 'What reaches you, and how'], ['security', 'Security', 'Password, two-factor sign-in, passkeys']] as const

/** How hard the new password would be to guess, as it is typed (the server's own rules still decide), as the current page. */
function strength(p: string): number {
  if (!p) return 0
  let s = Math.min(4, Math.floor(p.length / 4))
  if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++
  if (/\d/.test(p)) s++
  if (/[^\w\s]/.test(p) || /\s/.test(p)) s++
  return Math.min(5, Math.max(1, s - (/(.)\1\1/.test(p) ? 1 : 0)))
}
const STRENGTH = ['—', 'Very weak', 'Weak', 'Fair', 'Good', 'Strong']
const STRENGTH_COLOUR = ['transparent', tokens.colorPaletteRedBackground3, tokens.colorPaletteDarkOrangeBackground3, tokens.colorPaletteYellowBackground3, tokens.colorPaletteLightGreenBackground3, tokens.colorPaletteGreenBackground3]

type Codes = { title: string; codes: string[] }
type TwoFa = { what: 'setup'; token: string; qr: string; secret: string } | { what: 'codes' } | { what: 'off' }

/** The browser part of adding a passkey (SimpleWebAuthn, shipped with the server for the current page too), loaded once. */
function webauthn(): Promise<{ startRegistration: (o: { optionsJSON: unknown }) => Promise<unknown> }> {
  const w = window as unknown as { SimpleWebAuthnBrowser?: { startRegistration: (o: { optionsJSON: unknown }) => Promise<unknown> } }
  if (w.SimpleWebAuthnBrowser) return Promise.resolve(w.SimpleWebAuthnBrowser)
  return new Promise((ok, fail) => {
    const s = document.createElement('script')
    s.src = '/vendor/simplewebauthn/browser-13.3.0.umd.min.js'
    s.onload = () => (w.SimpleWebAuthnBrowser ? ok(w.SimpleWebAuthnBrowser) : fail(new Error('The passkey helper did not load.')))
    s.onerror = () => fail(new Error('The passkey helper did not load. Check the connection.'))
    document.head.appendChild(s)
  })
}

/**
 * My profile (part 39), as the current page: a list of its parts with where to get help, and a card for each — photo,
 * name and e-mail; the look; preferences; notifications; password (with how strong it is, and the current password to
 * change it or the e-mail); two-factor sign-in with the authenticator app (set up with the QR code, new recovery codes,
 * turn off); and passkeys (add on this computer or phone, remove).
 */
export function ProfilePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const st = useStyles()
  const [d, setD] = useState<ProfileData | null>(null)
  const [v, setV] = useState<ProfileSave | null>(null)
  const [pw, setPw] = useState({ password: '', confirm: '', current: '' })
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [twofa, setTwofa] = useState<TwoFa | null>(null)
  const [tf, setTf] = useState({ code: '', password: '', recovery: '', useRecovery: false })
  const [codes, setCodes] = useState<Codes | null>(null)
  const [pkName, setPkName] = useState('')
  const [pkBusy, setPkBusy] = useState(false)
  const [remove, setRemove] = useState<{ id: string; name: string } | null>(null)
  const load = useCallback(() => {
    api.profile().then((x) => {
      setD(x)
      setV({ name: x.name, email: x.email, look: x.look, prefs: x.prefs, notify: Object.fromEntries(x.topics.map((t) => [t.id, t.on])) })
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening your profile…" /></div>
  const say = (e: unknown) => { setDone(''); setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const changing = pw.password !== '' || v.email.trim().toLowerCase() !== d.email.toLowerCase()
  const ready = v.name.trim() !== '' && v.email.trim() !== '' && (pw.password === '' || pw.password === pw.confirm) && (!changing || pw.current !== '')
  const save = () => {
    setBusy(true)
    setError('')
    api.saveProfile({ ...v, ...(pw.password ? { password: pw.password, password_confirmation: pw.confirm } : {}), ...(changing ? { current_password: pw.current } : {}) })
      .then(() => location.reload()) // the new look, theme and dates show at once, as on the current page
      .catch((e: unknown) => { setBusy(false); say(e) })
  }
  const photo = (file: File | null) => api.profilePhoto(file).then((x) => { setError(''); setDone(x.message); load() }).catch(say)
  const go = (id: string) => document.getElementById(`profile-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  const pkAdd = async () => {
    setPkBusy(true)
    setError('')
    try {
      const { options } = await api.passkeyOptions(pkName)
      const lib = await webauthn()
      const made = await lib.startRegistration({ optionsJSON: options })
      const x = await api.passkeyStore(JSON.stringify(made))
      setPkName(''); setDone(x.message); load()
    } catch (e) {
      say(e instanceof ApiError || e instanceof Error ? (e.name === 'NotAllowedError' ? new Error('Adding the passkey was cancelled.') : e) : e)
    } finally { setPkBusy(false) }
  }
  const codeFile = (list: string[]) => `data:text/plain;charset=utf-8,${encodeURIComponent(list.join('\r\n'))}`
  const twoFactorRun = async () => {
    if (!twofa) return
    if (twofa.what === 'setup') {
      const x = await api.twoFactorConfirm({ token: twofa.token, code: tf.code, password: tf.password })
      setTwofa(null); setDone(x.message); load()
      if (x.recovery_codes.length) setCodes({ title: 'Your recovery codes', codes: x.recovery_codes })
    } else if (twofa.what === 'codes') {
      const x = await api.twoFactorCodes({ code: tf.code, password: tf.password })
      setTwofa(null); setDone(x.message); load(); setCodes({ title: 'Your new recovery codes', codes: x.recovery_codes })
    } else {
      const x = await api.twoFactorOff(tf.useRecovery ? { recovery_code: tf.recovery, password: tf.password } : { code: tf.code, password: tf.password })
      setTwofa(null); setDone(x.message); load()
    }
  }
  const tfa = d.two_factor
  const n = strength(pw.password)
  return (
    <div className={page.page}>
      <h1 className={s.title} style={{ marginBottom: 4 }}>My profile</h1>
      <Text block className={s.muted} style={{ marginBottom: 12 }}>Your details, how the back office looks for you, and how you sign in.</Text>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <div className={st.layout}>
        <div className={st.nav}>
          <nav aria-label="Parts of your profile" style={{ display: 'grid', gap: 4 }}>
            {PARTS.map(([id, title, text]) => (
              <Link key={id} className={st.navLink} onClick={() => go(id)}><Text weight="semibold">{title}</Text><Text size={200} className={s.muted}>{text}</Text></Link>
            ))}
          </nav>
          {(d.help.phone || d.help.email) && (
            <div className={st.help}>
              <Text weight="semibold">Need help?</Text>
              <Text size={200}>{d.help.vendor} support can help with your account.</Text>
              {d.help.phone && <Link href={`tel:${d.help.phone.replace(/\s+/g, '')}`}>Call {d.help.phone}</Link>}
              {d.help.email && <Link href={`mailto:${d.help.email}`}>{d.help.email}</Link>}
            </div>
          )}
        </div>
        <div>
          <div id="profile-you"><Panel title="Personal information" about="Your photo, name and e-mail address, as colleagues see them.">
            <div className={st.photo}>
              <Avatar size={72} name={d.name} image={d.photo ? { src: d.photo } : undefined} icon={<PersonRegular />} />
              <Field label="Photo" hint="Optional. JPEG, PNG or WebP up to 10 MB; cut to a square. Kept on your company's server only.">
                <input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Choose a photo" onChange={(e) => { const x = e.target.files?.[0]; if (x) void photo(x); e.target.value = '' }} />
              </Field>
              {d.has_photo && <Button appearance="subtle" icon={<DeleteRegular />} onClick={() => void photo(null)}>Remove photo</Button>}
            </div>
            <div className={f.grid}>
              <TextField label="Name" required maxLength={255} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
              <TextField label="E-mail address" required maxLength={255} value={v.email} onChange={(x) => setV({ ...v, email: x })} />
            </div>
          </Panel></div>
          <div id="profile-look"><Panel title="Look" about="How the back office looks for you. Switch at any time; on a phone the side menu is always used.">
            <Field label="This back office (the new look)">
              <RadioGroup layout="horizontal" value={v.prefs.office_look} onChange={(_, x) => setV({ ...v, prefs: { ...v.prefs, office_look: x.value } })}>
                {d.office_looks.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}
              </RadioGroup>
            </Field>
          </Panel></div>
          <div id="profile-prefs"><Panel title="Preferences" about="Your own defaults. They follow you to any computer you sign in to.">
            <div className={f.grid}>
              <Choose label="Business Portal" hint="The page opened when you sign in and by the house icon: the Business Portal itself, Today, or one of your favourites (the star)." options={d.homes} value={v.prefs.home}
                onChange={(x) => setV({ ...v, prefs: { ...v.prefs, home: x } })} />
              <Choose label="Rows a list shows at first" options={d.rows} value={String(v.prefs.rows)} onChange={(x) => setV({ ...v, prefs: { ...v.prefs, rows: Number(x) } })} />
              <Choose label="How dates are written" options={d.dates} value={v.prefs.date} onChange={(x) => setV({ ...v, prefs: { ...v.prefs, date: x } })} />
              <Field label="Light or dark" hint="Set on each computer you sign in to; the switcher in your menu still changes it for that computer.">
                <RadioGroup layout="horizontal" value={v.prefs.theme} onChange={(_, x) => setV({ ...v, prefs: { ...v.prefs, theme: x.value } })}>
                  {d.themes.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}
                </RadioGroup>
              </Field>
              <Field label="Lists">
                <RadioGroup layout="horizontal" value={v.prefs.density} onChange={(_, x) => setV({ ...v, prefs: { ...v.prefs, density: x.value } })}>
                  {d.densities.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}
                </RadioGroup>
              </Field>
            </div>
          </Panel></div>
          <div id="profile-notify"><Panel title="Notifications" about="What reaches you, and how. The company decides which channels are used at all (System → Settings); here you can turn off what you do not need. Security notices always reach you.">
            <Table size="small" aria-label="Notifications">
              <TableHeader><TableRow><TableHeaderCell>What</TableHeaderCell>{d.channels.map((c) => <TableHeaderCell key={c.id}>{c.label}</TableHeaderCell>)}</TableRow></TableHeader>
              <TableBody>{d.topics.map((t) => (
                <TableRow key={t.id}>
                  <TableCell><Text weight="semibold">{t.label}</Text><Text size={200} block className={s.muted}>{t.detail}</Text></TableCell>
                  {d.channels.map((c) => (
                    <TableCell key={c.id}>{t.channels.includes(c.id) && <Checkbox aria-label={`${t.label}: ${c.label}`} checked={(v.notify[t.id] ?? []).includes(c.id)}
                      onChange={(_, x) => setV({ ...v, notify: { ...v.notify, [t.id]: x.checked ? [...(v.notify[t.id] ?? []), c.id] : (v.notify[t.id] ?? []).filter((k) => k !== c.id) } })} />}</TableCell>
                  ))}
                </TableRow>
              ))}</TableBody>
            </Table>
          </Panel></div>
          <div id="profile-security"><Panel title="Password" about="Leave empty to keep your password. A long phrase is safer than a short word with symbols.">
            <div className={f.grid}>
              <div>
                <TextField label="New password" type="password" value={pw.password} onChange={(x) => setPw({ ...pw, password: x })} />
                <div className={st.strength}><div style={{ width: `${n * 20}%`, height: '100%', backgroundColor: STRENGTH_COLOUR[n] }} /></div>
                <Text size={200}>Password strength: <b>{STRENGTH[n]}</b></Text>
              </div>
              {pw.password !== '' && <TextField label="New password again" required type="password" value={pw.confirm} onChange={(x) => setPw({ ...pw, confirm: x })} />}
              {changing && <TextField label="Current password" required hint="Needed to change your password or e-mail address." type="password" value={pw.current} onChange={(x) => setPw({ ...pw, current: x })} />}
            </div>
            {pw.password !== '' && pw.confirm !== '' && pw.password !== pw.confirm && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 6 }}>The two new passwords are not the same.</Text>}
          </Panel></div>
          <SaveBar value={[v, pw]}>
            <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save changes'}</Button>
          </SaveBar>

          <Panel title="Two-factor sign-in" about="A code from an app on your phone, asked for at sign-in, so a stolen password alone is not enough."
            actions={<Badge appearance="tint" color={tfa.enabled ? 'success' : 'subtle'}>{tfa.enabled ? 'On' : 'Off'}</Badge>}>
            {tfa.by_code && <Text block style={{ marginBottom: 8 }}>Your administrator set your sign-in to a code by WhatsApp or e-mail. The authenticator app can be set up too.</Text>}
            {tfa.required && !tfa.enabled && <MessageBar intent="warning" style={{ marginBottom: 8 }}><MessageBarBody>Your account needs two-factor sign-in.</MessageBarBody></MessageBar>}
            {tfa.enabled && tfa.recoverable && <Text block className={s.muted} style={{ marginBottom: 8 }}>{tfa.codes_left} recovery code(s) left.</Text>}
            <div className={s.actions}>
              {!tfa.enabled && <Button appearance="primary" icon={<ShieldCheckmarkRegular />}
                onClick={() => { setDone(''); setTf({ code: '', password: '', recovery: '', useRecovery: false }); api.twoFactorStart().then((x) => setTwofa({ what: 'setup', ...x })).catch(say) }}>Set up</Button>}
              {tfa.enabled && tfa.can_regenerate && <Button icon={<KeyRegular />} onClick={() => { setDone(''); setTf({ code: '', password: '', recovery: '', useRecovery: false }); setTwofa({ what: 'codes' }) }}>New recovery codes</Button>}
              {tfa.enabled && <Button icon={<LockClosedRegular />} onClick={() => { setDone(''); setTf({ code: '', password: '', recovery: '', useRecovery: false }); setTwofa({ what: 'off' }) }}>Turn off</Button>}
            </div>
          </Panel>
          <Panel title="Passkeys" about="Sign in with your phone, fingerprint or face instead of a password. A passkey also counts as two-factor sign-in.">
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 8 }}>
              <Field label="Name for this passkey" style={{ flex: '1 1 220px' }}>
                <Input value={pkName} placeholder="e.g. Work laptop" maxLength={255} onChange={(_, x) => setPkName(x.value)} />
              </Field>
              <Button icon={<AddRegular />} disabled={pkBusy || pkName.trim() === ''} onClick={() => void pkAdd()}>{pkBusy ? 'Follow your browser…' : 'Add passkey'}</Button>
            </div>
            {d.passkeys.length === 0 ? <Text className={s.muted}>No passkey yet on this account.</Text> : d.passkeys.map((p) => (
              <div key={p.id} className={st.row}>
                <div><Text weight="semibold"><FingerprintRegular /> {p.name}</Text><Text size={200} block className={s.muted}>Last used: {p.last_used}</Text></div>
                <Button size="small" appearance="outline" icon={<DeleteRegular />} onClick={() => { setDone(''); setRemove(p) }}>Remove</Button>
              </div>
            ))}
          </Panel>
        </div>
      </div>

      {twofa && (
        <Ask title={twofa.what === 'setup' ? 'Set up the authenticator app' : twofa.what === 'codes' ? 'New recovery codes' : 'Turn off two-factor sign-in'}
          go={twofa.what === 'setup' ? 'Turn on' : twofa.what === 'codes' ? 'Make new codes' : 'Turn off'} width={520} onClose={() => setTwofa(null)}
          ready={tf.password !== '' && (twofa.what === 'off' && tf.useRecovery ? tf.recovery.trim() !== '' : /^\d{6}$/.test(tf.code.trim()))} run={twoFactorRun}>
          <div className={s.one}>
            {twofa.what === 'setup' && <>
              <Text>Scan this with an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…), then type the code it shows.</Text>
              <img src={twofa.qr} alt="QR code to scan with the authenticator app" className={st.qr} />
              <Text size={200}>Or type this key in the app: <b style={{ fontFamily: tokens.fontFamilyMonospace }}>{twofa.secret}</b></Text>
            </>}
            {twofa.what === 'codes' && <Text>The recovery codes you have now stop working. Keep the new ones somewhere safe.</Text>}
            {twofa.what === 'off' && <Text>Without it, a stolen password is enough to sign in as you.{tfa.required ? ' Your account needs it: you will be asked to set it up again.' : ''}</Text>}
            {!(twofa.what === 'off' && tf.useRecovery) && <TextField label="Code from the app" required maxLength={6} value={tf.code} onChange={(x) => setTf({ ...tf, code: x.replace(/\D/g, '') })} />}
            {twofa.what === 'off' && tf.useRecovery && <TextField label="Recovery code" required maxLength={40} value={tf.recovery} onChange={(x) => setTf({ ...tf, recovery: x })} />}
            {twofa.what === 'off' && tfa.recoverable && <div><Link onClick={() => setTf({ ...tf, useRecovery: !tf.useRecovery })}>{tf.useRecovery ? 'Use a code from the app instead' : 'Use a recovery code instead'}</Link></div>}
            <TextField label="Your password" required type="password" value={tf.password} onChange={(x) => setTf({ ...tf, password: x })} />
          </div>
        </Ask>
      )}
      {codes && (
        <Ask title={codes.title} go="I have kept them" ready onClose={() => setCodes(null)} run={async () => setCodes(null)}>
          <div className={s.one}>
            <Text weight="semibold">Keep these somewhere safe. Each one signs you in once if you lose your phone. They are not shown again.</Text>
            <div className={st.codes} aria-label="Recovery codes">{codes.codes.map((c) => <span key={c}>{c}</span>)}</div>
            <div className={s.actions}>
              <Button icon={<CopyRegular />} onClick={() => { void navigator.clipboard?.writeText(codes.codes.join('\n')); setDone('Recovery codes copied.') }}>Copy</Button>
              <Button icon={<ArrowDownloadRegular />} as="a" href={codeFile(codes.codes)} download="recovery-codes.txt">Download</Button>
            </div>
          </div>
        </Ask>
      )}
      {remove && (
        <Ask title={`Remove the passkey “${remove.name}”?`} go="Remove" ready onClose={() => setRemove(null)}
          run={() => api.passkeyRemove(remove.id).then((x) => { setRemove(null); setDone(x.message); load() })}>
          <Text>You can no longer sign in with it. Your other ways of signing in stay.</Text>
        </Ask>
      )}
    </div>
  )
}

import {
  Button, Checkbox, Field, FluentProvider, Input, Link, makeStyles, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Text, tokens,
} from '@fluentui/react-components'
import { ArrowDownloadRegular, CopyRegular, FingerprintRegular, ShieldCheckmarkRegular, SignOutRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, xsrf, type AuthChallenge, type AuthState } from '../api'
import { themes } from '../theme'

const useStyles = makeStyles({
  page: { minHeight: '100vh', display: 'grid', placeItems: 'center', padding: '24px 16px', backgroundColor: tokens.colorNeutralBackground2, boxSizing: 'border-box' },
  card: { width: '100%', maxWidth: '420px', backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, boxShadow: tokens.shadow16,
    padding: '28px 24px', display: 'grid', gap: '14px', boxSizing: 'border-box' },
  logo: { maxHeight: '48px', maxWidth: '220px', objectFit: 'contain', justifySelf: 'center' },
  title: { fontSize: tokens.fontSizeBase500, fontWeight: tokens.fontWeightSemibold, margin: 0, textAlign: 'center' },
  tagline: { textAlign: 'center', color: tokens.colorNeutralForeground3 },
  or: { textAlign: 'center', color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200 },
  qr: { display: 'block', width: '192px', height: '192px', margin: '0 auto', backgroundColor: '#fff', padding: '8px', borderRadius: tokens.borderRadiusMedium },
  codes: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '4px 12px', fontFamily: tokens.fontFamilyMonospace, padding: '10px', borderRadius: tokens.borderRadiusMedium,
    backgroundColor: tokens.colorNeutralBackground3, fontSize: tokens.fontSizeBase200 },
})

/** Light or dark as the computer is set (nobody's preference is known before signing in). */
function useSystemDark(): boolean {
  const q = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null
  const [dark, setDark] = useState(() => q?.matches ?? false)
  useEffect(() => {
    if (!q) return
    const on = () => setDark(q.matches)
    q.addEventListener('change', on)
    return () => q.removeEventListener('change', on)
  }, [q])
  return dark
}

function Frame({ color, children }: { color: string; children: ReactNode }) {
  const dark = useSystemDark()
  const theme = useMemo(() => themes(color), [color])
  const s = useStyles()
  useEffect(() => { document.documentElement.style.colorScheme = dark ? 'dark' : 'light' }, [dark])
  return <FluentProvider theme={dark ? theme.dark : theme.light}><main className={s.page}><div className={s.card}>{children}</div></main></FluentProvider>
}

const message = (e: unknown) => (e instanceof Error ? e.message : String(e))

/** The browser part of a passkey (SimpleWebAuthn, shipped with the server), loaded once. */
function webauthn(): Promise<{ startAuthentication: (o: { optionsJSON: unknown }) => Promise<unknown> }> {
  const w = window as unknown as { SimpleWebAuthnBrowser?: { startAuthentication: (o: { optionsJSON: unknown }) => Promise<unknown> } }
  if (w.SimpleWebAuthnBrowser) return Promise.resolve(w.SimpleWebAuthnBrowser)
  return new Promise((ok, fail) => {
    const el = document.createElement('script')
    el.src = '/vendor/simplewebauthn/browser-13.3.0.umd.min.js'
    el.onload = () => (w.SimpleWebAuthnBrowser ? ok(w.SimpleWebAuthnBrowser) : fail(new Error('The passkey helper did not load.')))
    el.onerror = () => fail(new Error('The passkey helper did not load. Check the connection.'))
    document.head.appendChild(el)
  })
}

/**
 * Sign in (part 41), as the current sign-in page: the e-mail or the username and the password ("Keep me signed in"), or
 * a passkey; then, for someone with two-factor sign-in, the code from the authenticator app (or a recovery code), or the
 * code sent by WhatsApp or e-mail (send a new one, or by e-mail instead). The company's name and logo, and the tagline.
 */
export function SignInApp() {
  const s = useStyles()
  const dark = useSystemDark()
  const [st, setSt] = useState<AuthState | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [f, setF] = useState({ login: '', password: '', remember: false })
  const [challenge, setChallenge] = useState<AuthChallenge | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState<string | null>(null)
  const [sent, setSent] = useState<string | null>(null)
  const load = useCallback(() => {
    api.authState().then((x) => {
      if (x.signed_in) { location.assign('/office/'); return }
      setSt(x); setChallenge(x.challenge); if (x.passkey_message) setError(x.passkey_message)
    }).catch((e: unknown) => setError(message(e)))
  }, [])
  useEffect(load, [load])
  useEffect(() => { document.title = st ? `Sign in · ${st.company}` : 'Sign in' }, [st])

  const go = (open: string) => location.assign(open)
  const run = (p: Promise<void>) => { setBusy(true); setError(''); p.catch((e: unknown) => setError(message(e))).finally(() => setBusy(false)) }
  const signIn = () => run(api.authSignIn(f).then((x) => {
    if (x.challenge) { setChallenge(x.challenge); setCode(''); setRecovery(null); setSent(null); setF({ ...f, password: '' }); return }
    if (x.open) go(x.open)
  }))
  const verify = () => run(api.authVerify(recovery !== null ? { recovery_code: recovery } : { code }).then((x) => go(x.open)))
  const choose = (provider: string, email = false) => run(api.authChoose(provider, email).then((x) => { setChallenge(x.challenge); setCode(''); setRecovery(null); setSent(x.sent_to) }))
  const passkey = () => run((async () => {
    const res = await fetch('/passkeys/authentication-options', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error('Passkeys cannot be used just now. Sign in with your password.')
    const lib = await webauthn()
    let answer: unknown
    try {
      answer = await lib.startAuthentication({ optionsJSON: await res.json() })
    } catch (e) {
      throw new Error(e instanceof Error && e.name === 'NotAllowedError' ? 'Signing in with the passkey was cancelled.' : 'The passkey did not answer. Try again, or use your password.')
    }
    const body = new FormData()
    body.append('start_authentication_response', JSON.stringify(answer))
    if (f.remember) body.append('remember', '1')
    const done = await fetch('/passkeys/authenticate', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' } })
    const to = new URL(done.url)
    if (to.pathname.startsWith('/office/sign-in') || !done.ok) { load(); throw new Error('That passkey could not sign you in. Use your password, or another passkey.') }
    go(to.pathname + to.search) // signed in: on to the page asked for, or the person's home
  })())

  if (!st) return <Frame color="#0f5c8c">{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening…" />}</Frame>
  return (
    <Frame color={st.color || '#0f5c8c'}>
      {(dark ? st.logo_dark ?? st.logo : st.logo) && <img src={(dark ? st.logo_dark ?? st.logo : st.logo)!} alt={st.company} className={s.logo} />}
      <h1 className={s.title}>{challenge ? 'Two-step sign-in' : `Sign in to ${st.company}`}</h1>
      {st.tagline && !challenge && <Text className={s.tagline} size={200}>{st.tagline}</Text>}
      {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {!challenge ? (
        <form onSubmit={(e) => { e.preventDefault(); signIn() }} style={{ display: 'grid', gap: 12 }}>
          <Field label="E-mail or username" required>
            <Input autoFocus autoComplete="username" value={f.login} maxLength={255} onChange={(_, x) => setF({ ...f, login: x.value })} />
          </Field>
          <Field label="Password" required>
            <Input type="password" autoComplete="current-password" value={f.password} maxLength={255} onChange={(_, x) => setF({ ...f, password: x.value })} />
          </Field>
          <Checkbox label="Keep me signed in" checked={f.remember} onChange={(_, x) => setF({ ...f, remember: !!x.checked })} />
          <Button appearance="primary" type="submit" disabled={busy || f.login.trim() === '' || f.password === ''}>{busy ? 'Signing in…' : 'Sign in'}</Button>
          <Text className={s.or}>or</Text>
          <Button icon={<FingerprintRegular />} disabled={busy} onClick={passkey}>Sign in with a passkey</Button>
        </form>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); verify() }} style={{ display: 'grid', gap: 12 }}>
          <Text>{challenge.name}, prove it is you.</Text>
          {challenge.providers.length > 1 && (
            <Field label="How">
              <RadioGroup value={challenge.provider} onChange={(_, x) => choose(x.value)}>
                {challenge.providers.map((p) => <Radio key={p.id} value={p.id} label={p.label} />)}
              </RadioGroup>
            </Field>
          )}
          {recovery === null ? (
            <Field label={challenge.provider === 'app' ? 'The code from your authenticator app' : 'The code we sent you'} required
              hint={challenge.provider !== 'app' && sent ? `Sent to your ${sent}.` : undefined}>
              <Input autoFocus inputMode="numeric" autoComplete="one-time-code" maxLength={10} value={code} onChange={(_, x) => setCode(x.value.replace(/\s/g, ''))} />
            </Field>
          ) : (
            <Field label="Recovery code" required hint="One of the codes you kept when you set up the app. Each works once.">
              <Input autoFocus autoComplete="one-time-code" maxLength={40} value={recovery} onChange={(_, x) => setRecovery(x.value)} />
            </Field>
          )}
          <Button appearance="primary" type="submit" icon={<ShieldCheckmarkRegular />} disabled={busy || (recovery === null ? code === '' : recovery.trim() === '')}>{busy ? 'Checking…' : 'Sign in'}</Button>
          {challenge.provider === 'app' && challenge.recoverable && (
            <Link onClick={() => setRecovery(recovery === null ? '' : null)}>{recovery === null ? 'Use a recovery code instead' : 'Use the code from the app instead'}</Link>
          )}
          {challenge.provider !== 'app' && <>
            <Link onClick={() => choose(challenge.provider)}>Send a new code</Link>
            <Link onClick={() => choose(challenge.provider, true)}>Send it by e-mail instead</Link>
          </>}
          <Link onClick={() => run(api.authCancel().then(() => { setChallenge(null); setError('') }))}>Back</Link>
        </form>
      )}
    </Frame>
  )
}

/**
 * "Set up two-factor sign-in first" (part 41), for someone whose account needs it: the authenticator app, with the QR
 * code, a code from the app and the password; then the recovery codes, shown once; then on to the back office.
 */
export function TwoFactorSetupApp() {
  const s = useStyles()
  const [st, setSt] = useState<{ name: string; enabled: boolean; required: boolean; home: string } | null>(null)
  const [setup, setSetup] = useState<{ token: string; qr: string; secret: string } | null>(null)
  const [f, setF] = useState({ code: '', password: '' })
  const [codes, setCodes] = useState<string[] | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.twoFactorSetupState().then((x) => {
      if (x.enabled || !x.required) { location.assign(x.home); return }
      setSt(x)
    }).catch((e: unknown) => setError(message(e)))
  }, [])
  useEffect(() => { document.title = 'Set up two-factor sign-in' }, [])
  const run = (p: Promise<void>) => { setBusy(true); setError(''); p.catch((e: unknown) => setError(message(e))).finally(() => setBusy(false)) }

  if (!st) return <Frame color="#0f5c8c">{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening…" />}</Frame>
  return (
    <Frame color="#0f5c8c">
      <h1 className={s.title}>Set up two-factor sign-in</h1>
      {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {codes ? (
        <>
          <Text weight="semibold">Two-factor sign-in is on. Keep these recovery codes somewhere safe: each one signs you in once if you lose your phone. They are not shown again.</Text>
          <div className={s.codes} aria-label="Recovery codes">{codes.map((c) => <span key={c}>{c}</span>)}</div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Button icon={<CopyRegular />} onClick={() => void navigator.clipboard?.writeText(codes.join('\n'))}>Copy</Button>
            <Button icon={<ArrowDownloadRegular />} as="a" href={`data:text/plain;charset=utf-8,${encodeURIComponent(codes.join('\r\n'))}`} download="recovery-codes.txt">Download</Button>
          </div>
          <Button appearance="primary" onClick={() => location.assign(st.home)}>I have kept them — continue</Button>
        </>
      ) : !setup ? (
        <>
          <Text>{st.name}, your account needs two-factor sign-in: a code from an app on your phone, asked for at sign-in, so a stolen password alone is not enough.</Text>
          <Text>Install an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…), then press Start.</Text>
          <Button appearance="primary" icon={<ShieldCheckmarkRegular />} disabled={busy} onClick={() => run(api.twoFactorStart().then(setSetup))}>Start</Button>
        </>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); run(api.twoFactorConfirm({ token: setup.token, code: f.code, password: f.password }).then((x) => setCodes(x.recovery_codes.length ? x.recovery_codes : []))) }}
          style={{ display: 'grid', gap: 12 }}>
          <Text>Scan this with the app, then type the code it shows and your password.</Text>
          <img src={setup.qr} alt="QR code to scan with the authenticator app" className={s.qr} />
          <Text size={200}>Or type this key in the app: <b style={{ fontFamily: tokens.fontFamilyMonospace }}>{setup.secret}</b></Text>
          <Field label="Code from the app" required>
            <Input inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={f.code} onChange={(_, x) => setF({ ...f, code: x.value.replace(/\D/g, '') })} />
          </Field>
          <Field label="Your password" required>
            <Input type="password" autoComplete="current-password" value={f.password} onChange={(_, x) => setF({ ...f, password: x.value })} />
          </Field>
          <Button appearance="primary" type="submit" disabled={busy || f.code.length !== 6 || f.password === ''}>{busy ? 'Checking…' : 'Turn on'}</Button>
        </form>
      )}
      <Button appearance="subtle" icon={<SignOutRegular />} onClick={() => void api.signOut()}>Sign out</Button>
    </Frame>
  )
}

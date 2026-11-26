import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { CheckmarkCircleRegular, FingerprintRegular, ShieldCheckmarkRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type ReconfirmState } from '../api'
import { TextField } from './Fields'

/** The browser part of a passkey (SimpleWebAuthn, shipped with the server), loaded once. */
function webauthn(): Promise<{ startAuthentication: (o: { optionsJSON: unknown }) => Promise<unknown> }> {
  const w = window as unknown as { SimpleWebAuthnBrowser?: { startAuthentication: (o: { optionsJSON: unknown }) => Promise<unknown> } }
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
 * "Confirm it's you" (part 40), as the current page: the code from the authenticator app, a passkey (fingerprint, face or
 * the phone), a code sent by WhatsApp or e-mail, or — for someone with none of these — the password. Confirming lasts a
 * few minutes; then the step that asked for it is done again.
 */
export function ConfirmForm({ onConfirmed }: { onConfirmed?: () => void }) {
  const [d, setD] = useState<ReconfirmState | null>(null)
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api.reconfirm().then((x) => { setD(x); if (x.confirmed) onConfirmed?.() }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(load, [load])
  const done = (x: { minutes: number }) => { setD((o) => (o ? { ...o, confirmed: true, minutes: x.minutes } : o)); setError(''); onConfirmed?.() }
  const run = (p: Promise<{ minutes: number }>) => { setBusy(true); setError(''); p.then(done).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false)) }
  const passkey = async () => {
    const { options } = await api.reconfirmPasskeyOptions()
    const lib = await webauthn()
    let answer: unknown
    try {
      answer = await lib.startAuthentication({ optionsJSON: options })
    } catch (e) {
      throw new Error(e instanceof Error && e.name === 'NotAllowedError' ? 'Confirming with the passkey was cancelled.' : 'The passkey did not answer. Try again, or use a code.')
    }
    return api.reconfirmPasskey(JSON.stringify(answer))
  }

  if (error && !d) return <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>
  if (!d) return <Spinner size="small" label="Opening…" />
  if (d.confirmed) {
    return (
      <MessageBar intent="success"><MessageBarBody><CheckmarkCircleRegular /> <b>Confirmed.</b> For the next {d.minutes} minutes you can make payments and changes that ask for it.</MessageBarBody></MessageBar>
    )
  }
  const withCode = d.app || !!d.sent_to
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      <Text>Paying out a larger amount, writing off what a customer owes, or changing bank or MoMo details asks you to prove it is really you. <b>Why:</b> a computer left signed in, or a stolen sign-in, must not be able to send money away.</Text>
      {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {withCode && (
        <form onSubmit={(e) => { e.preventDefault(); run(api.reconfirmCode({ code })) }} style={{ display: 'grid', gap: 8 }}>
          <TextField label={d.sent_to ? `The code we sent to your ${d.sent_to}` : 'The 6-digit code from your authenticator app'} required maxLength={10} value={code} onChange={(x) => setCode(x.replace(/\s/g, ''))} />
          <div><Button appearance="primary" type="submit" icon={<ShieldCheckmarkRegular />} disabled={busy || code === ''}>Confirm</Button></div>
        </form>
      )}
      {d.password && !d.sent_to && (
        <form onSubmit={(e) => { e.preventDefault(); run(api.reconfirmCode({ password })) }} style={{ display: 'grid', gap: 8 }}>
          <TextField label="Your password" required type="password" value={password} onChange={setPassword} />
          <div><Button appearance="primary" type="submit" icon={<ShieldCheckmarkRegular />} disabled={busy || password === ''}>Confirm</Button></div>
        </form>
      )}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {d.passkey && <Button icon={<FingerprintRegular />} disabled={busy} onClick={() => run(passkey())}>Confirm with a passkey</Button>}
        {!d.sent_to && <Button disabled={busy} onClick={() => { setBusy(true); setError(''); api.reconfirmSend().then((x) => setD({ ...d, sent_to: x.sent_to })).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false)) }}>
          Send me a code by WhatsApp or e-mail instead</Button>}
      </div>
      {d.sent_to && <Text size={200}>Code sent to your {d.sent_to}.</Text>}
    </div>
  )
}

/**
 * "Confirm it's you" before a step the company protects — approving a till request, writing off what a customer owes…
 * The server answers such a step with ApiError code "reconfirm"; this opens, the person confirms here, then presses the
 * button to do the step again. (`url`, the current page's address, is kept for the pages that pass it.)
 */
export function ConfirmItsYou({ why, go, onCancel, onGo }: { url: string; why: string; go: string; onCancel: () => void; onGo: () => void }) {
  const [ok, setOk] = useState(false)
  return (
    <Dialog open onOpenChange={(_, d) => { if (!d.open) onCancel() }}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>Confirm it's you</DialogTitle>
          <DialogContent>
            <Text block style={{ marginBottom: 10 }}>{why} Prove it is really you, then press <strong>{go}</strong>.</Text>
            <ConfirmForm onConfirmed={() => setOk(true)} />
          </DialogContent>
          <DialogActions>
            <Button onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" icon={<ShieldCheckmarkRegular />} disabled={!ok} onClick={onGo}>{go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

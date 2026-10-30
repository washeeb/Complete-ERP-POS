import { useEffect, useRef, useState } from 'react'
import { grantFor, pinMatches } from '../offline'
import { personColor } from '../person'

const TRIES = 5

/**
 * After a few idle minutes (Settings → Tills and cash) the till locks with the signed-in person's name on it. She unlocks
 * with her own PIN (checked on the till, so it works offline) and the sale on screen stays; anyone else signs her out
 * and signs in as themselves. Five wrong PINs sign her out.
 */
export function IdleLock({ minutes, user, onSignOut }: { minutes: number; user: { id: string; name: string }; onSignOut: () => void }) {
  const last = useRef(Date.now())
  const [locked, setLocked] = useState(false)
  const [pin, setPin] = useState('')
  const [tries, setTries] = useState(0)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!minutes) return
    const touch = () => { last.current = Date.now() }
    const events = ['pointerdown', 'keydown', 'touchstart', 'wheel'] as const
    events.forEach((e) => window.addEventListener(e, touch, { passive: true }))
    const id = setInterval(() => { if (Date.now() - last.current >= minutes * 60_000) setLocked(true) }, 10_000)
    return () => {
      events.forEach((e) => window.removeEventListener(e, touch))
      clearInterval(id)
    }
  }, [minutes])

  if (!locked) return null

  async function unlock(e: React.FormEvent) {
    e.preventDefault()
    const grant = await grantFor(user.id)
    if (grant && (await pinMatches(grant, pin))) {
      last.current = Date.now()
      setLocked(false)
      setPin('')
      setTries(0)
      setError('')
      return
    }
    setPin('')
    if (tries + 1 >= TRIES) return onSignOut()
    setTries(tries + 1)
    setError(grant ? `Wrong PIN (${TRIES - tries - 1} tries left).` : 'This till cannot check your PIN: sign in again.')
  }

  return (
    <div className="modal idle-lock" role="dialog" aria-modal="true" aria-label="Till locked">
      <form className="card narrow" onSubmit={unlock}>
        <p className="who-band" style={{ background: personColor(user.id) }}>{user.name}</p>
        <h2>Till locked</h2>
        <p className="muted">Locked after {minutes} minute{minutes === 1 ? '' : 's'} without use. {user.name}: type your PIN to carry on.</p>
        <input className="big mono pin pin-mask" type="text" inputMode="numeric" autoComplete="off" autoFocus value={pin} aria-label="PIN"
          onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} />
        {error && <p className="error" role="alert">{error}</p>}
        <div className="row">
          <button type="button" onClick={onSignOut}>Not {user.name}? Sign out</button>
          <button className="primary big" disabled={pin.length < 4}>Unlock</button>
        </div>
      </form>
    </div>
  )
}

import { useEffect, useRef, useState } from 'react'
import { api, ApiError, type Approval, type ApprovalAsk, type TillApprovalState } from '../api'
import { waitForAnswer } from '../approve'
import { offlineSignIn } from '../offline'

/**
 * A manager's yes, two ways (owner request 2026-11-15). *Ask a manager*: the request goes to the managers' phones and the
 * back office (Sales → Till requests); the till waits and goes on by itself when one approves, so no manager PIN is typed
 * where others can see it. *Manager is here*: they scan their staff card (or type their username) and their PIN.
 *
 * `spend`: for things the till does not send to the server by themselves (removing items from a sale), the approval is
 * used up here; otherwise the caller sends `{request_id}` (or the typed username and PIN) with the action, once.
 */
export function ManagerApproval({ title, ask, online, who = 'manager', message, busy = false, spend = false, selfId, onApproved, onCancel }: {
  title: string; ask: ApprovalAsk; online: boolean; who?: 'manager' | 'supervisor'; message?: string; busy?: boolean; spend?: boolean
  selfId: string; onApproved: (approval: Approval, by: string | null) => void; onCancel: () => void
}) {
  const [mode, setMode] = useState<'choose' | 'waiting' | 'here'>(online ? 'choose' : 'here')
  const [request, setRequest] = useState<TillApprovalState | null>(null)
  const [answer, setAnswer] = useState<TillApprovalState | null>(null)
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState('')
  const [working, setWorking] = useState(false)
  const stop = useRef<AbortController | null>(null)
  const Who = who === 'manager' ? 'Manager' : 'Supervisor'

  useEffect(() => () => stop.current?.abort(), [])

  async function askRemote() {
    setError('')
    setAnswer(null)
    setWorking(true)
    try {
      const r = (await api.askApproval(ask)).approval
      setRequest(r)
      setMode('waiting')
      stop.current = new AbortController()
      const done = await waitForAnswer(r.id, async (id) => (await api.approvalStatus(id)).approval, { signal: stop.current.signal })
      if (done.status !== 'approved') {
        setAnswer(done)
        return
      }
      if (spend) await api.useApproval(done.id)
      onApproved({ request_id: done.id }, done.decided_by)
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') return
      setError(e instanceof Error ? e.message : String(e))
      setMode('choose')
    } finally {
      setWorking(false)
    }
  }

  async function cancelWait() {
    stop.current?.abort()
    if (request) await api.cancelApproval(request.id).catch(() => undefined)
    setRequest(null)
    setMode('choose')
  }

  async function approveHere(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    if (!spend) return onApproved({ username: username.trim(), pin }, null) // checked by the server with the action itself
    setWorking(true)
    try {
      if (online) {
        const r = (await api.askApproval(ask)).approval
        const done = (await api.approveHere(r.id, username.trim(), pin)).approval
        const used = await api.useApproval(done.id)
        onApproved({ request_id: done.id }, used.approved_by)
      } else {
        // No server: the PIN is checked on this till against the manager's offline sign-in.
        const g = await offlineSignIn(username.trim(), pin)
        if (g.user_id === selfId) throw new Error('Another person must approve this.')
        onApproved({ username: username.trim(), pin }, g.user_name)
      }
    } catch (err) {
      setError(err instanceof ApiError || err instanceof Error ? err.message : String(err))
    } finally {
      setWorking(false)
    }
  }

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={title} onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); if (!(working && mode !== 'waiting')) { void cancelWait(); onCancel() } } }}>
      <div className="card pay approval">
        <h2>{title}</h2>
        <p>{ask.summary}{ask.reason ? ` · ${ask.reason}` : ''}</p>
        {message && <p className="muted">{message}</p>}
        {!!ask.details?.length && (
          <ul className="small">{ask.details.slice(0, 8).map((d, i) => <li key={i}>{d.qty ? `${d.qty} × ` : ''}{d.name}</li>)}</ul>
        )}

        {mode === 'choose' && (
          <div className="stack">
            <button type="button" className="primary big" onClick={askRemote} disabled={working || busy}>
              {working ? 'Sending…' : `Ask a ${who} (they approve on their phone)`}
            </button>
            <button type="button" className="big" onClick={() => setMode('here')} disabled={working || busy}>{Who} is here: scan card or type PIN</button>
          </div>
        )}

        {mode === 'waiting' && (
          <div className="stack" aria-live="polite">
            {answer ? (
              <>
                <p className="error">
                  {answer.status === 'declined' ? `Declined by ${answer.decided_by ?? 'the manager'}${answer.note ? `: ${answer.note}` : '.'}`
                    : answer.status === 'expired' ? 'Nobody answered in 10 minutes.' : 'The request was closed.'}
                </p>
                <button type="button" className="primary" onClick={askRemote} disabled={working}>Ask again</button>
              </>
            ) : (
              <>
                <p><span className="spinner" aria-hidden="true" /> Sent. Waiting for a {who} to approve on their phone or in the back office…</p>
                <p className="muted small">The till goes on by itself as soon as they approve. It runs out after 10 minutes.</p>
                <button type="button" onClick={() => { void cancelWait().then(() => setMode('here')) }}>{Who} is here after all</button>
              </>
            )}
          </div>
        )}

        {mode === 'here' && (
          <form className="stack" onSubmit={approveHere}>
            {!online && <p className="muted small">Offline: the {who} approves here; their PIN is checked on this till.</p>}
            <label>Scan the {who}'s card, or type their username
              <input autoFocus autoComplete="off" autoCapitalize="none" value={username} onChange={(e) => setUsername(e.target.value)} />
            </label>
            <label>{Who} PIN<input type="text" className="pin-mask" autoComplete="off" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} /></label>
            <button type="submit" className="primary big" disabled={working || busy || !username.trim() || pin.length < 4}>{working || busy ? 'Checking…' : 'Approve'}</button>
            {online && <button type="button" className="link" onClick={() => setMode('choose')}>Ask from their phone instead</button>}
          </form>
        )}

        {error && <p className="error" role="alert">{error}</p>}
        <div className="row">
          <button type="button" onClick={() => { void cancelWait(); onCancel() }} disabled={working && mode !== 'waiting'}>Cancel</button>
        </div>
      </div>
    </div>
  )
}

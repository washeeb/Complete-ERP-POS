import { Button, Field, makeStyles, MessageBar, MessageBarActions, MessageBarBody, MessageBarTitle, Textarea } from '@fluentui/react-components'
import { DismissRegular, PersonKeyRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, NO_ACCESS } from '../api'

const useStyles = makeStyles({
  bar: { margin: '12px 20px 0', flex: '0 0 auto', '@media (max-width: 760px)': { margin: '8px 10px 0' } },
  ask: { display: 'grid', gap: '8px', margin: '8px 20px 0', maxWidth: '560px', '@media (max-width: 760px)': { margin: '8px 10px 0' } },
})

/**
 * "You do not have access" with Ask for access (as the current back office's no-access page, owner request 2026-10-22;
 * kept in the new look in part 46): shown when the page, or a part of it, is refused. Nothing is granted by itself:
 * the people who give roles are told on the bell, and the request is in the audit log. One per page (keyed by address).
 */
export function NoAccessBar() {
  const s = useStyles()
  const [why, setWhy] = useState<string | null>(null)
  const [asking, setAsking] = useState(false)
  const [note, setNote] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    const on = (e: Event) => setWhy((e as CustomEvent<string>).detail || 'You cannot see this.')
    window.addEventListener(NO_ACCESS, on)
    return () => window.removeEventListener(NO_ACCESS, on)
  }, [])
  if (why === null) return null // nothing refused here, or closed
  const send = () => {
    setBusy(true)
    api.requestAccess(location.pathname + location.search, note.trim())
      .then((r) => { setSent(r.told > 0 ? 'Sent. The people who give access were told on their bell; you get a notification when they decide.' : 'Sent, and kept in the audit log. Nobody who gives access could be told right now: ask your manager.'); setAsking(false) })
      .catch((e: unknown) => setSent(e instanceof Error ? e.message : 'Could not send. Try again.'))
      .finally(() => setBusy(false))
  }
  return (
    <>
      <MessageBar className={s.bar} intent={sent ? 'success' : 'warning'} layout="multiline">
        <MessageBarBody>
          <MessageBarTitle>{sent ? 'Access asked for' : 'You do not have access to this page, or to part of it'}</MessageBarTitle>
          {sent ?? `${why} Your role does not include it. If you need it for your work, ask for access.`}
        </MessageBarBody>
        <MessageBarActions containerAction={<Button appearance="transparent" aria-label="Close" icon={<DismissRegular />} onClick={() => setWhy(null)} />}>
          {!sent && !asking && <Button icon={<PersonKeyRegular />} onClick={() => setAsking(true)}>Ask for access</Button>}
        </MessageBarActions>
      </MessageBar>
      {asking && !sent && (
        <div className={s.ask}>
          <Field label="What do you need it for? (optional)" hint="The person who gives access sees this with the page you were on.">
            <Textarea value={note} maxLength={300} onChange={(_, d) => setNote(d.value)} />
          </Field>
          <div><Button appearance="primary" icon={<SendRegular />} disabled={busy} onClick={send}>{busy ? 'Sending…' : 'Send the request'}</Button>{' '}
            <Button appearance="subtle" onClick={() => setAsking(false)}>Cancel</Button></div>
        </div>
      )}
    </>
  )
}

import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getSession } from './db'
import type { Realtime } from './types'

export type LiveState = 'off' | 'connecting' | 'live'

/**
 * Live catalog/stock notices over Reverb. Messages carry ids only; on any notice the till re-reads its
 * catalog through the normal permission-checked API (cheap: ETag). If the socket is down the till keeps
 * working and falls back to polling — live updates are a convenience, never a dependency.
 */
export function connectLive(rt: Realtime, onChange: () => void, onState: (s: LiveState) => void): () => void {
  let timer: ReturnType<typeof setTimeout> | undefined
  const changed = () => {
    clearTimeout(timer)
    timer = setTimeout(onChange, 300) // a price change and its stock event arrive together: refresh once
  }
  const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: rt.key,
    wsHost: rt.host,
    wsPort: rt.port,
    wssPort: rt.port,
    forceTLS: rt.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        getSession()
          .then((s) => fetch('/api/v1/pos/broadcasting/auth', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${s?.token ?? ''}` },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          }))
          .then(async (r) => (r.ok ? callback(null, await r.json()) : callback(new Error(`auth ${r.status}`), null)))
          .catch((e: Error) => callback(e, null))
      },
    }),
  })
  onState('connecting')
  const pusher = (echo.connector as unknown as { pusher: Pusher }).pusher
  pusher.connection.bind('state_change', (s: { current: string }) => onState(s.current === 'connected' ? 'live' : 'connecting'))

  for (const name of rt.channels.filter((c): c is string => !!c)) {
    echo.private(name).listen('.catalog.changed', changed).listen('.stock.changed', changed)
  }

  return () => {
    clearTimeout(timer)
    echo.disconnect()
    onState('off')
  }
}

import { api } from '../api'

/**
 * Notifications on this phone or computer (Web Push, as the classic back office): asked for by a button, never on page
 * load. Registers the server's push worker, asks the browser, subscribes with the company's key and tells the server.
 */
export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

export const pushState = (): NotificationPermission | 'unsupported' => (pushSupported() ? Notification.permission : 'unsupported')

function key(b64: string): Uint8Array {
  const p = '='.repeat((4 - (b64.length % 4)) % 4)
  const raw = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

export async function enablePush(publicKey: string): Promise<'granted' | 'denied' | 'unsupported'> {
  if (!pushSupported()) return 'unsupported'
  const reg = await navigator.serviceWorker.register('/push-sw.js')
  if (await Notification.requestPermission() !== 'granted') return 'denied'
  const sub = (await reg.pushManager.getSubscription()) ?? await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(publicKey) as BufferSource })
  const j = sub.toJSON()
  await api.tillPush({ endpoint: j.endpoint ?? '', public_key: j.keys?.p256dh ?? '', auth_token: j.keys?.auth ?? '', device: navigator.userAgent.slice(0, 255) })
  return 'granted'
}

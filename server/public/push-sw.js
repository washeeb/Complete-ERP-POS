/*
 * Shows the back office's notifications on this phone or computer, even when the back office is closed (Web Push;
 * App\Domain\Messaging\PhonePush). Pressing one opens the page it is about (e.g. Till requests, to approve).
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let d = {}
  try { d = event.data ? event.data.json() : {} } catch { d = { title: event.data ? event.data.text() : 'Notification' } }
  event.waitUntil(self.registration.showNotification(d.title || 'Notification', {
    body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, requireInteraction: !!d.urgent,
    icon: '/pos/icon-192.png', badge: '/pos/icon-192.png', data: { url: d.url || '/admin' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : '/admin', self.location.origin).href
  event.waitUntil((async () => {
    const open = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const c of open) {
      if (c.url.startsWith(self.location.origin) && 'focus' in c) { await c.focus(); if ('navigate' in c) return c.navigate(url); return }
    }
    return self.clients.openWindow(url)
  })())
})

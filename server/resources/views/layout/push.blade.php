{{-- Notifications on this phone or computer (App\Domain\Messaging\PhonePush): asked for by a button, never on page load. --}}
<script>
    window.zxPush = window.zxPush || {
        supported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window },
        state() { return !this.supported() ? 'unsupported' : Notification.permission },
        key(b64) { const p = '='.repeat((4 - (b64.length % 4)) % 4); const raw = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...raw].map((c) => c.charCodeAt(0))) },
        async enable(publicKey, wire) {
            if (!this.supported()) return 'unsupported'
            const reg = await navigator.serviceWorker.register('/push-sw.js')
            if (await Notification.requestPermission() !== 'granted') return 'denied'
            const sub = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key(publicKey) })
            const j = sub.toJSON()
            await wire.call('savePush', j.endpoint, j.keys.p256dh, j.keys.auth, navigator.userAgent)
            return 'granted'
        },
    }
</script>

{{--
    A back-office page opened inside the new back office (/office), for pages not yet rebuilt there: its own menus are hidden
    (the new app has them), and the page tells the app its title and address so the app's header and address bar follow.
    Only when the frame around it is this same site (anything else may not frame these pages at all).
--}}
<script>
    (function () {
        var framed = false
        try { framed = window.top !== window && window.top.location.origin === window.location.origin } catch (e) { framed = false }
        if (!framed) return
        document.documentElement.classList.add('zx-embedded')
        var tell = function () {
            window.top.postMessage({ type: 'zx-page', title: document.title, path: location.pathname + location.search }, location.origin)
        }
        document.addEventListener('DOMContentLoaded', tell)
        document.addEventListener('livewire:navigated', tell)
    })()
</script>
<style>
    html.zx-embedded .fi-sidebar, html.zx-embedded .fi-topbar, html.zx-embedded .fi-sidebar-close-overlay,
    html.zx-embedded .zx-tabbar, html.zx-embedded .zx-band, html.zx-embedded .fi-footer { display: none !important; }
    html.zx-embedded .fi-main-ctn, html.zx-embedded .fi-layout { padding-inline-start: 0 !important; margin-inline-start: 0 !important; }
    html.zx-embedded body { background: transparent; }
</style>

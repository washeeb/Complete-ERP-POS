// One scrollbar up and down (owner request 2026-10-24). A wide list scrolls sideways in its own box, whose scrollbar
// sits under its last row. While that scrollbar is below the window but the list is on screen, a thin copy is pinned to
// the bottom of the window; the two move together. Works for every list and table of lines, after any page update.
(() => {
    const bars = new Map() // list box → its pinned copy

    function barFor(box) {
        let bar = bars.get(box)
        if (bar) return bar
        bar = document.createElement('div')
        bar.className = 'ses-hscroll'
        bar.setAttribute('aria-hidden', 'true')
        bar.appendChild(document.createElement('div'))
        document.body.appendChild(bar)
        let busy = false
        bar.addEventListener('scroll', () => { if (busy) return; busy = true; box.scrollLeft = bar.scrollLeft; busy = false })
        box.addEventListener('scroll', () => { if (busy) return; busy = true; bar.scrollLeft = box.scrollLeft; busy = false })
        bars.set(box, bar)
        return bar
    }

    function update() {
        for (const [box, bar] of bars) {
            if (!document.body.contains(box)) { bar.remove(); bars.delete(box) }
        }
        document.querySelectorAll('.fi-ta-content-ctn, .fi-fo-table-repeater').forEach((box) => {
            const wide = box.scrollWidth > box.clientWidth + 1
            if (!wide && !bars.has(box)) return
            const bar = barFor(box)
            const r = box.getBoundingClientRect()
            const show = wide && r.top < window.innerHeight - 40 && r.bottom > window.innerHeight // on screen, own scrollbar below
            bar.style.display = show ? 'block' : 'none'
            if (!show) return
            bar.style.left = `${r.left}px`
            bar.style.width = `${r.width}px`
            bar.firstChild.style.width = `${box.scrollWidth}px`
            bar.scrollLeft = box.scrollLeft
        })
    }

    let queued = false
    const soon = () => { if (!queued) { queued = true; setTimeout(() => { queued = false; update() }, 16) } }
    window.addEventListener('scroll', soon, { passive: true })
    window.addEventListener('resize', soon)
    document.addEventListener('DOMContentLoaded', soon)
    document.addEventListener('livewire:navigated', soon)
    new MutationObserver(soon).observe(document.documentElement, { childList: true, subtree: true })
})()

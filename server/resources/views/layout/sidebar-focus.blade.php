{{-- Keep the menu section you are working in at the top of the sidebar (owner request 2026-10-22): open Stock on hand and
     the Stock section scrolls to the top, so its other pages are one click away. --}}
<script>
    (() => {
        function focusGroup() {
            const nav = document.querySelector('.fi-sidebar-nav');
            const group = nav?.querySelector('.fi-sidebar-item.fi-active')?.closest('.fi-sidebar-group');
            if (!nav || !group) return;
            nav.scrollTop = Math.max(0, group.getBoundingClientRect().top - nav.getBoundingClientRect().top + nav.scrollTop - 8);
        }
        document.addEventListener('DOMContentLoaded', () => requestAnimationFrame(focusGroup));
        document.addEventListener('livewire:navigated', () => requestAnimationFrame(focusGroup));
    })();
</script>

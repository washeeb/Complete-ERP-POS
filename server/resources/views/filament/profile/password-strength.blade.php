{{-- Profile → Password: how hard the new password would be to guess, as it is typed (only on this screen; the
     server's own rules still decide). --}}
<div class="zx-pw" x-data="{ get s() { const p = $wire.data?.password ?? ''; if (! p) return 0; let s = Math.min(4, Math.floor(p.length / 4)); if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^\w\s]/.test(p) || /\s/.test(p)) s++; return Math.min(5, Math.max(1, s - (/(.)\1\1/.test(p) ? 1 : 0))) } }">
    <div class="zx-pw-bar"><span x-bind:style="`width:${s * 20}%`" x-bind:class="`zx-pw-${s}`"></span></div>
    <p class="zx-pw-label">Password strength:
        <strong x-text="['—', 'Very weak', 'Weak', 'Fair', 'Good', 'Strong'][s]" x-bind:class="`zx-pw-t${s}`"></strong></p>
</div>

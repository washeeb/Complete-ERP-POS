{{-- Profile → Look: the looks as cards with a small picture of each, drawn in the company's colours. '' = follow the
     company's choice (System → Settings → Appearance). --}}
@php
    $looks = ['' => ['As the company chose', \App\Support\Look::LOOKS[$company] ?? ''], 'top' => ['Tabs across the top', 'Like a desktop program'],
        'side' => ['Side menu', 'A dark menu that folds to icons'], 'classic' => ['Classic', 'The original layout']];
    $pic = $company === '' ? 'top' : $company;
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="zx-looks" role="radiogroup" aria-label="How the back office looks for you" x-data="{ state: $wire.$entangle(@js($getStatePath())) }">
        @foreach ($looks as $value => [$title, $text])
            @php($kind = $value === '' ? $pic : $value)
            <label class="zx-look" x-bind:class="{ 'zx-on': (state ?? '') === @js($value) }">
                <input type="radio" name="zx-look" value="{{ $value }}" x-model="state" class="zx-look-radio">
                <span class="zx-look-text"><strong>{{ $title }}</strong><small>{{ $text }}</small></span>
                <svg class="zx-look-pic" viewBox="0 0 120 64" aria-hidden="true">
                    <rect width="120" height="64" rx="5" fill="var(--zx-canvas, #eef1f5)"/>
                    @if ($kind === 'top')
                        <rect width="120" height="9" rx="3" fill="var(--zx-panel, #fff)"/><rect y="9" width="120" height="8" fill="var(--zx-menu, #1c2a3a)"/>
                        <rect x="8" y="11.5" width="14" height="3" rx="1.5" fill="var(--primary-400)"/><rect x="26" y="11.5" width="14" height="3" rx="1.5" fill="#fff" opacity=".6"/><rect x="44" y="11.5" width="14" height="3" rx="1.5" fill="#fff" opacity=".6"/>
                        <rect x="8" y="24" width="104" height="12" rx="3" fill="var(--zx-menu, #1c2a3a)" opacity=".85"/>
                        <rect x="8" y="40" width="50" height="18" rx="3" fill="var(--zx-panel, #fff)"/><rect x="62" y="40" width="50" height="18" rx="3" fill="var(--zx-panel, #fff)"/>
                    @elseif ($kind === 'side')
                        <rect width="30" height="64" rx="5" fill="var(--zx-menu, #1c2a3a)"/>
                        <rect x="5" y="8" width="20" height="3" rx="1.5" fill="#fff" opacity=".7"/><rect x="5" y="16" width="20" height="5" rx="2" fill="var(--primary-500)"/>
                        <rect x="5" y="25" width="20" height="3" rx="1.5" fill="#fff" opacity=".5"/><rect x="5" y="32" width="20" height="3" rx="1.5" fill="#fff" opacity=".5"/>
                        <rect x="34" y="4" width="82" height="8" rx="4" fill="var(--zx-panel, #fff)"/><rect x="34" y="16" width="82" height="12" rx="3" fill="var(--zx-menu, #1c2a3a)" opacity=".85"/>
                        <rect x="34" y="32" width="39" height="26" rx="3" fill="var(--zx-panel, #fff)"/><rect x="77" y="32" width="39" height="26" rx="3" fill="var(--zx-panel, #fff)"/>
                    @else
                        <rect width="30" height="64" rx="5" fill="var(--zx-panel, #fff)"/><rect x="5" y="16" width="20" height="5" rx="2" fill="color-mix(in oklab, var(--primary-500) 25%, #fff)"/>
                        <rect x="5" y="8" width="20" height="3" rx="1.5" fill="#cbd5e1"/><rect x="5" y="25" width="20" height="3" rx="1.5" fill="#cbd5e1"/><rect x="5" y="32" width="20" height="3" rx="1.5" fill="#cbd5e1"/>
                        <rect x="34" y="8" width="40" height="5" rx="2" fill="#94a3b8"/>
                        <rect x="34" y="20" width="82" height="38" rx="3" fill="var(--zx-panel, #fff)"/>
                    @endif
                </svg>
            </label>
        @endforeach
    </div>
</x-dynamic-component>

{{-- New look, top right (like NetSuite): who is signed in and as what, beside the photo that opens the user menu. --}}
@php($user = auth()->user())
@if ($user)
    <div class="zx-who" aria-hidden="true">
        <span class="zx-who-name">{{ $user->name }}</span>
        <span class="zx-who-role">{{ collect([\App\Support\Look::roleLine(), \App\Filament\Support\PanelBrand::name()])->filter()->join(' · ') }}</span>
    </div>
@endif

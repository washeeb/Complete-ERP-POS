{{-- A page that exists only in the new back office: its menu entry is a panel page, the page itself is at /office. --}}
<x-filament-panels::page>
    <p>This page is in the back office: <a class="underline" href="{{ \App\Support\OfficeUrl::from('/'.request()->path()) }}">open it</a>.</p>
</x-filament-panels::page>

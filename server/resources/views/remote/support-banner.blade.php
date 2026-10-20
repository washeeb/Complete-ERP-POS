{{-- While SyncLytics support may reach the devices, everyone signed in sees it; the owner can withdraw it from here. --}}
@php
    $grant = auth()->check()
        ? \App\Models\SupportAccessGrant::withoutGlobalScopes()->active()->where('tenant_id', auth()->user()->tenant_id)->with('branch', 'report')->latest('starts_at')->first()
        : null;
@endphp
@if ($grant)
    @php
        $tz = \App\Models\Tenant::find($grant->tenant_id)?->timezone ?: 'UTC';
        $until = $grant->support_report_id ? 'until problem '.$grant->report?->number.' is closed' : 'until '.$grant->ends_at?->timezone($tz)->format('j M H:i');
    @endphp
    <div role="status" style="padding:6px 16px;font-size:14px;font-weight:600;text-align:center;background:#0e7490;color:#fff">
        SyncLytics support can {{ $grant->level === 'control' ? 'see and control' : 'see' }} {{ $grant->branch?->name ?? 'all' }} devices {{ $until }} ({{ $grant->reason }}).
        @if (\App\Filament\Support\Erp::can('support.access.grant'))
            <a href="{{ \App\Filament\Resources\SupportAccessGrants\SupportAccessGrantResource::getUrl('index') }}" style="color:#fff;text-decoration:underline;margin-left:8px">Withdraw or change</a>
        @endif
    </div>
@endif

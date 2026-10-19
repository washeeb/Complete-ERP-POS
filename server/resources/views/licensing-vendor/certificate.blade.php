@extends('licensing-vendor.layout')
@php($p = $l->payload)
@php($lim = fn ($k) => ($v = $p['limits'][$k] ?? null) === null ? 'Unlimited' : $v)
@php($modules = \App\Domain\Licensing\Vendor\VendorLicensing::modules())
@section('title', 'Licence certificate '.$l->short())
@section('heading')
    <h1>LICENCE CERTIFICATE</h1>
    <div><strong>{{ $l->short() }}</strong></div>
    <div class="small">{{ \App\Models\Vendor\VendorLicense::STATUSES[$l->status()] }}</div>
@endsection
@section('content')
    <p>This certifies that the customer below is licensed to use <strong>SES Labs ERP &amp; POS</strong> on the installation named here, within the limits and for the period shown, under the SyncLytics licence agreement.</p>
    <h2>Licensee</h2>
    <table>
        <tr><th>Customer</th><td>{{ $l->client?->legal_name ?: ($l->client?->name ?? $p['customer']) }}</td></tr>
        @if ($l->client?->address)<tr><th>Address</th><td>{{ $l->client->address }}</td></tr>@endif
        @if ($l->client?->tin)<tr><th>TIN</th><td>{{ $l->client->tin }}</td></tr>@endif
        <tr><th>Site / server</th><td>{{ $l->site ?? '—' }}</td></tr>
        <tr><th>Installation</th><td class="small">{{ $l->installation_id }}</td></tr>
    </table>
    <h2>Licence</h2>
    <table>
        <tr><th>Type</th><td>{{ \App\Models\Vendor\VendorLicense::KINDS[$l->kind] ?? $l->kind }}{{ $l->plan ? ' · '.$l->plan->name.' plan' : '' }}</td></tr>
        <tr><th>Valid</th><td>{{ $l->starts_on?->format('j F Y') }} to {{ $l->expires_on?->format('j F Y') }} (then {{ $p['grace_days'] ?? 14 }} days' grace)</td></tr>
        <tr><th>Branches</th><td>{{ $lim('branches') }}</td></tr>
        <tr><th>Tills and devices</th><td>{{ $lim('devices') }}</td></tr>
        <tr><th>Active users</th><td>{{ $lim('users') }}</td></tr>
        <tr><th>Industry modules</th><td>{{ ($p['modules'] ?? null) === null ? 'All' : (collect($p['modules'])->map(fn ($m) => $modules[$m] ?? $m)->implode(', ') ?: 'None') }}</td></tr>
        <tr><th>Features</th><td>{{ ($p['features'] ?? null) === null ? 'All (Finance, HR & payroll)' : (collect($p['features'])->map(fn ($f) => \App\Domain\Licensing\Vendor\VendorLicensing::FEATURES[$f] ?? $f)->implode(', ') ?: 'Core only') }}</td></tr>
        @if ($l->addons)<tr><th>Add-ons</th><td>{{ collect($l->addons)->map(fn ($a) => "{$a['qty']} × {$a['name']}")->implode(', ') }}</td></tr>@endif
        <tr><th>Online check-in</th><td>{{ ($c = $p['checkin_days'] ?? 30) ? "At least every {$c} days" : 'Not required' }}</td></tr>
        <tr><th>Issued</th><td>{{ $l->created_at?->format('j F Y') }} by {{ $l->issued_by }}</td></tr>
    </table>
    <h2>Licence key</h2>
    <div class="key">{{ $l->license_key }}</div>
    <p class="small muted">Activate: sign in as the owner, open Settings → Licence, click <em>Enter licence key</em> and paste the key. Renewals reach the system by themselves at the daily check-in. Keep this key private: it works only on the installation above.</p>
    <p class="small" style="margin-top: 28px">For SyncLytics ______________________ &nbsp; Date ________ &nbsp;&nbsp;&nbsp; For the customer ______________________ &nbsp; Date ________</p>
@endsection

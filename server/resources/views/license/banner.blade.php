@php($s = app(\App\Domain\Licensing\LicenseManager::class)->status())
@if ($s['message'] && auth()->check())
    <div role="status" style="padding:8px 16px;font-size:14px;text-align:center;{{ $s['read_only'] ? 'background:#991b1b;color:#fff' : 'background:#fef3c7;color:#78350f' }}">
        {{ $s['read_only'] ? 'Read-only: ' : '' }}{{ $s['message'] }} <a href="/admin/licence" style="text-decoration:underline;color:inherit">Licence</a>
    </div>
@endif

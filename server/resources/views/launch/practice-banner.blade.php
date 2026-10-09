@if (auth()->check() && \App\Models\Tenant::isPractice(auth()->user()->tenant_id))
    <div role="status" style="padding:6px 16px;font-size:14px;font-weight:600;text-align:center;background:#7c3aed;color:#fff;letter-spacing:.02em">
        PRACTICE COMPANY — nothing here is real. Sales, stock and money are made up; nothing is sent to GRA, customers or online stores.
    </div>
@endif

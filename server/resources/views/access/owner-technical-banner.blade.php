{{-- The owner still holds technical access (users, devices, system settings) because nobody else can do that work yet:
     asked to have it handed over, so a stolen owner sign-in cannot reach it (owner decision 2026-11-13). --}}
@if (auth()->check() && \App\Domain\Access\OwnerSeparation::isOwnerAccount(auth()->user()) && \App\Domain\Access\OwnerSeparation::ownerStillTechnical(auth()->user()->tenant_id))
    <div role="status" style="padding:6px 16px;font-size:14px;font-weight:600;text-align:center;background:#92400e;color:#fff">
        Your Owner account still looks after users, devices and system settings, because no IT administrator is set up. Ask SyncLytics support to take this over (or name an IT person), so that someone who gets your password cannot change them.
    </div>
@endif

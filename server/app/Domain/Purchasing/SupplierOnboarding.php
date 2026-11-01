<?php

namespace App\Domain\Purchasing;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Settings;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supplier onboarding (procurement stage 2a). Best practice in supplier management, kept as rules here:
 *   - a new supplier is approved by someone other than the person who added it, before anyone orders from it;
 *   - the documents the company requires (Settings → Purchasing) are on file and in date, or orders stop;
 *   - a blocked supplier gets no orders (with the reason kept);
 *   - bank or mobile money details are checked by a second person, and a change sends them back for checking: no
 *     payment by transfer or MoMo goes out on details nobody verified (redirected payments are the commonest supplier fraud).
 */
final class SupplierOnboarding
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Settings $settings) {}

    public function approvalOn(string $tenantId): bool
    {
        return (bool) $this->settings->get('purchasing.supplier_approval', $tenantId);
    }

    /** @return list<string> document kinds the company requires */
    public function required(string $tenantId): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->settings->get('purchasing.supplier_documents', $tenantId))),
            fn ($k) => isset(SupplierDocument::KINDS[$k])));
    }

    /** @return list<string> what is missing or out of date, in words ("FDA licence (expired 3 Oct 2026)") */
    public function gaps(Supplier $s): array
    {
        $docs = $s->documents()->get()->groupBy('kind');
        $out = [];
        foreach ($this->required($s->tenant_id) as $kind) {
            $have = $docs->get($kind);
            if (! $have || $have->isEmpty()) {
                $out[] = SupplierDocument::KINDS[$kind].' (missing)';
            } elseif ($have->every(fn (SupplierDocument $d) => $d->expired())) {
                $out[] = SupplierDocument::KINDS[$kind].' (expired '.$have->max('expires_on')->format('j M Y').')';
            }
        }

        return $out;
    }

    /** Before an order to this supplier is saved, sent for approval or approved. */
    public function assertCanOrder(Supplier $s): void
    {
        if ($s->status === 'blocked') {
            throw new DomainException("{$s->name} is blocked: {$s->blocked_reason}", 'supplier_blocked');
        }
        if (! $this->approvalOn($s->tenant_id)) {
            return;
        }
        if ($s->status !== 'approved') {
            throw new DomainException("{$s->name} is waiting for approval (Purchasing → Suppliers). Orders go to approved suppliers only.", 'supplier_not_approved');
        }
        if ($gaps = $this->gaps($s)) {
            throw new DomainException("{$s->name}: ".implode(', ', $gaps).'. Add the document on the supplier before ordering.', 'supplier_documents');
        }
    }

    /** Before money goes to the supplier by transfer or mobile money. */
    public function assertBankVerified(Supplier $s, string $method): void
    {
        if ($this->approvalOn($s->tenant_id) && in_array($method, ['bank', 'momo'], true) && $s->hasBankDetails() && ! $s->bank_verified_at) {
            throw new DomainException("{$s->name}'s bank or MoMo details were added or changed and nobody has checked them yet. Someone else confirms them "
                .'with the supplier (by phone, on a number you already had) and presses Verify bank details.', 'bank_unverified');
        }
    }

    public function approve(Actor $actor, Supplier $s, ?string $note = null): Supplier
    {
        $this->require($actor);
        if ($s->status === 'approved') {
            throw new DomainException("{$s->name} is approved already.");
        }
        if ($s->created_by && $s->created_by === $actor->id) {
            throw new DomainException('You added this supplier, so someone else must approve it.', 'own_document');
        }
        if ($gaps = $this->gaps($s)) {
            throw new DomainException('Not yet: '.implode(', ', $gaps).'.', 'supplier_documents');
        }
        if (blank($s->tax_no)) {
            throw new DomainException("Enter {$s->name}'s TIN first (it goes on withholding tax certificates and the GRA returns).");
        }
        $s->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => now(), 'blocked_reason' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $s, 'supplier.approved', $note));
    }

    public function block(Actor $actor, Supplier $s, string $reason): Supplier
    {
        $this->require($actor);
        if (mb_strlen(trim($reason)) < 3) {
            throw new DomainException('Give the reason (it shows to buyers).');
        }
        $s->forceFill(['status' => 'blocked', 'blocked_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $s, 'supplier.blocked', $reason));
    }

    /** Back to approved (or waiting, when it was never approved). */
    public function unblock(Actor $actor, Supplier $s, string $reason): Supplier
    {
        $this->require($actor);
        $s->forceFill(['status' => $s->approved_at || ! $this->approvalOn($s->tenant_id) ? 'approved' : 'pending', 'blocked_reason' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $s, 'supplier.unblocked', $reason));
    }

    public function verifyBank(Actor $actor, Supplier $s, string $how): Supplier
    {
        $this->require($actor);
        if (! $s->hasBankDetails()) {
            throw new DomainException('There are no bank or MoMo details to verify.');
        }
        if ($this->lastBankChangeBy($s) === $actor->id) {
            throw new DomainException('You entered these bank details, so someone else must verify them.', 'own_document');
        }
        if (mb_strlen(trim($how)) < 5) {
            throw new DomainException('Say how you confirmed them, e.g. "Called Mr Mensah on 024… (number from our first order)".');
        }
        $s->forceFill(['bank_verified_by' => $actor->id, 'bank_verified_by_name' => $actor->name, 'bank_verified_at' => now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $s, 'supplier.bank_verified', $how));
    }

    /** Who last changed the bank details (from the audit log). */
    private function lastBankChangeBy(Supplier $s): ?string
    {
        return DB::table('audit_events')->where('subject_id', $s->id)->where('action', 'supplier.bank_changed')->orderByDesc('seq')->value('actor_id');
    }

    /**
     * Keep a document. $path is the uploaded file in the private disk (or null for a number only).
     *
     * @param  array{kind: string, number?: ?string, issued_on?: ?string, expires_on?: ?string}  $data
     */
    public function addDocument(Actor $actor, Supplier $s, array $data, ?string $path, ?string $fileName): SupplierDocument
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'suppliers.manage')) {
            throw new AccessDenied;
        }
        if (! isset(SupplierDocument::KINDS[$data['kind'] ?? ''])) {
            throw new DomainException('Choose the kind of document.');
        }
        if (! $path && blank($data['number'] ?? null)) {
            throw new DomainException('Attach the document or enter its number.');
        }
        $issued = filled($data['issued_on'] ?? null) ? Carbon::parse($data['issued_on']) : null;
        $expires = filled($data['expires_on'] ?? null) ? Carbon::parse($data['expires_on']) : null;
        if ($issued && $expires && $expires->lt($issued)) {
            throw new DomainException('It expires before it was issued.');
        }
        $d = new SupplierDocument;
        $d->forceFill(['tenant_id' => $s->tenant_id, 'supplier_id' => $s->id, 'kind' => $data['kind'], 'number' => filled($data['number'] ?? null) ? mb_substr(trim($data['number']), 0, 80) : null,
            'issued_on' => $issued?->toDateString(), 'expires_on' => $expires?->toDateString(), 'file_path' => $path, 'file_name' => $fileName ? mb_substr($fileName, 0, 255) : null,
            'uploaded_by' => $actor->id, 'uploaded_by_name' => $actor->name]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $d, 'supplier.document_added', $d->label()));
    }

    /** Someone other than the uploader checks it against the original. */
    public function verifyDocument(Actor $actor, SupplierDocument $d): SupplierDocument
    {
        $this->require($actor);
        if ($d->uploaded_by === $actor->id) {
            throw new DomainException('You added this document, so someone else must check it.', 'own_document');
        }
        $d->forceFill(['verified_by' => $actor->id, 'verified_by_name' => $actor->name, 'verified_at' => now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $d, 'supplier.document_verified', $d->label()));
    }

    public function removeDocument(Actor $actor, SupplierDocument $d, string $reason): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'suppliers.manage')) {
            throw new AccessDenied;
        }
        $this->recorder->transaction(function () use ($actor, $d, $reason) {
            $this->recorder->audit->record($actor, 'supplier.document_removed', $d->supplier, ['document' => [$d->label().($d->number ? " {$d->number}" : ''), null]], $reason);
            if ($d->file_path) {
                Storage::disk('local')->delete($d->file_path);
            }
            $d->delete();
        });
    }

    /**
     * Daily: tell the people who approve suppliers about documents expiring within the warning days, and expired ones;
     * each document once per stage. Returns how many alerts went out.
     */
    public function expiryAlerts(string $tenantId): int
    {
        $days = (int) $this->settings->get('purchasing.document_warning_days', $tenantId);
        $docs = SupplierDocument::with('supplier')->where('tenant_id', $tenantId)->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays($days)->toDateString())->get();
        $people = User::where('tenant_id', $tenantId)->where('is_active', true)->get()
            ->filter(fn (User $u) => $this->authz->allowsAnywhere($u, 'suppliers.approve') || $this->authz->allowsAnywhere($u, 'purchasing.manage'));
        $sent = 0;
        foreach ($docs as $d) {
            $stage = $d->expired() ? 'expired' : 'soon';
            if ($d->alerted === $stage || $people->isEmpty() || ! $d->supplier) {
                continue;
            }
            $required = in_array($d->kind, $this->required($tenantId), true);
            Notification::make()->title(($stage === 'expired' ? 'Expired: ' : 'Expires soon: ')."{$d->supplier->name}, {$d->label()}")
                ->body(($stage === 'expired' ? 'Expired on ' : 'Expires on ').$d->expires_on->format('j M Y').'.'.($required && $this->approvalOn($tenantId)
                    ? ' Orders to this supplier stop until a new one is on file.' : '').' Ask the supplier for the renewed document.')
                ->{$stage === 'expired' ? 'danger' : 'warning'}()->sendToDatabase($people);
            $d->forceFill(['alerted' => $stage])->saveQuietly();
            $sent++;
        }

        return $sent;
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'suppliers.approve')) {
            throw new AccessDenied;
        }
    }
}

<?php

namespace App\Domain\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Tamper-evident audit log: each row's hash = HMAC(key, prev_hash . canonical(row)).
 * Rows are written inside the caller's transaction so a rolled-back change leaves no audit row,
 * and a committed change always has one.
 */
final class AuditLog
{
    /** Fields that form the hashed body, in fixed order. */
    public const HASHED = [
        'id', 'tenant_id', 'seq', 'actor_id', 'actor_name', 'source', 'action', 'subject_type',
        'subject_id', 'branch_id', 'device_id', 'origin_node', 'changes', 'reason', 'occurred_at',
    ];

    /** @param array<string, array{0: mixed, 1: mixed}> $changes field => [before, after] */
    public function record(
        Actor $actor,
        string $action,
        Model|string $subject,
        array $changes = [],
        ?string $reason = null,
        ?string $branchId = null,
    ): AuditEvent {
        return $this->recordRef($actor, $action, is_string($subject) ? $subject : self::typeOf($subject),
            is_string($subject) ? null : (string) $subject->getKey(), $changes, $reason, $branchId);
    }

    /** Same as record(), for a subject known only by type and id (e.g. a row applied by sync). */
    public function recordRef(
        Actor $actor,
        string $action,
        string $subjectType,
        ?string $subjectId,
        array $changes = [],
        ?string $reason = null,
        ?string $branchId = null,
    ): AuditEvent {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Audit rows must be written inside the domain transaction.');
        }

        $head = DB::table('audit_chain_heads')->where('tenant_id', $actor->tenantId)->lockForUpdate()->first();
        if (! $head) {
            DB::table('audit_chain_heads')->insert(['tenant_id' => $actor->tenantId]);
            $head = DB::table('audit_chain_heads')->where('tenant_id', $actor->tenantId)->lockForUpdate()->first();
        }

        $now = now();
        $row = [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $actor->tenantId,
            'seq' => $head->last_seq + 1,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'source' => $actor->source,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'branch_id' => $branchId,
            'device_id' => $actor->deviceId,
            'origin_node' => (string) config('erp.node.id'),
            // Round-trip through JSON so the hashed form equals what jsonb returns on verification
            // (dates become ISO strings, enums their values).
            'changes' => json_decode(json_encode(self::redact($changes)), true),
            'reason' => $reason,
            // Second precision: the timestamp column round-trips seconds, so the verifier can rebuild it exactly.
            'occurred_at' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $row['prev_hash'] = $head->last_hash;
        $row['hash'] = self::hash($head->last_hash, $row);

        $event = new AuditEvent;
        $event->forceFill($row + ['ip' => $actor->ip, 'request_id' => $actor->requestId, 'received_at' => $now]);
        $event->save();

        DB::table('audit_chain_heads')->where('tenant_id', $actor->tenantId)
            ->update(['last_seq' => $row['seq'], 'last_hash' => $row['hash']]);

        return $event;
    }

    public static function hash(string $prevHash, array $row): string
    {
        $key = (string) config('erp.audit_key');
        if ($key === '') {
            throw new RuntimeException('ERP_AUDIT_KEY is not set; refusing to write unverifiable audit rows.');
        }
        $body = [];
        foreach (self::HASHED as $field) {
            $body[$field] = $row[$field] ?? null;
        }

        return hash_hmac('sha256', $prevHash.self::canonical($body), $key);
    }

    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map(fn ($v) => is_array($v) ? json_decode(self::canonical($v), true) : $v, $value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function typeOf(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    /** Secrets never enter the audit log; we record that they changed, not their value. */
    private static function redact(array $changes): array
    {
        $secret = ['password', 'pin_hash', 'app_authentication_secret', 'app_authentication_recovery_codes',
            'enrollment_code_hash', 'remember_token', 'public_key'];
        foreach ($changes as $field => $pair) {
            if (in_array($field, $secret, true)) {
                $changes[$field] = ['[redacted]', '[changed]'];
            }
        }

        return $changes;
    }
}

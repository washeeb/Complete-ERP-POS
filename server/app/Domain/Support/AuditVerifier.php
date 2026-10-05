<?php

namespace App\Domain\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the HMAC chain. Detects edits, deletions and re-ordering made by anyone without
 * the audit key. A full rewrite by someone holding both DB-superuser access and the key needs
 * external checkpoints (planned, Phase 13).
 */
final class AuditVerifier
{
    /** @return array{ok: bool, checked: int, first_bad_seq: ?int, problem: ?string} */
    public function verify(string $tenantId): array
    {
        $prev = str_repeat('0', 64);
        $expectedSeq = 1;
        $checked = 0;

        foreach (DB::table('audit_events')->where('tenant_id', $tenantId)->orderBy('seq')->cursor() as $row) {
            if ((int) $row->seq !== $expectedSeq) {
                return $this->fail($checked, $expectedSeq, 'A row is missing (sequence gap).');
            }
            if ($row->prev_hash !== $prev) {
                return $this->fail($checked, (int) $row->seq, 'Chain link broken (previous hash mismatch).');
            }
            $data = (array) $row;
            $data['changes'] = json_decode($row->changes, true);
            $data['seq'] = (int) $row->seq;
            $data['occurred_at'] = Carbon::parse($row->occurred_at, 'UTC')->format('Y-m-d\TH:i:s\Z');
            if (! hash_equals(AuditLog::hash($prev, $data), $row->hash)) {
                return $this->fail($checked, (int) $row->seq, 'Row content was altered after it was written.');
            }
            $prev = $row->hash;
            $expectedSeq++;
            $checked++;
        }

        $head = DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->first();
        if ($head && ((int) $head->last_seq !== $checked || $head->last_hash !== $prev)) {
            return $this->fail($checked, $checked + 1, 'Latest rows are missing (chain head does not match).');
        }

        return ['ok' => true, 'checked' => $checked, 'first_bad_seq' => null, 'problem' => null];
    }

    private function fail(int $checked, int $seq, string $problem): array
    {
        return ['ok' => false, 'checked' => $checked, 'first_bad_seq' => $seq, 'problem' => $problem];
    }
}

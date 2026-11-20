<?php

namespace App\Domain\Office;

use App\Domain\Fiscal\FiscalService;
use App\Filament\Resources\FiscalSubmissions\FiscalSubmissionResource;
use App\Filament\Support\Erp;
use App\Models\FiscalSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * GRA E-VAT in the new back office (release 8 of the rewrite), as the current screen: what was reported to GRA, GRA's
 * answer, and what is still waiting or was refused (search, status, type), one report with what was sent and what GRA
 * answered, Send again, and in daily mode Send the waiting sales now, through FiscalService (EvatController).
 * A branch-scoped finance role sees its own branches' reports only (missing scope is not global scope).
 */
final class EvatScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['doc_date', 'doc_number', 'created_at'];

    public const TYPES = ['sale' => 'Till sale', 'invoice' => 'Invoice', 'credit_note' => 'Credit note'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return FiscalSubmissionResource::canViewAny();
    }

    public static function canSend(): bool
    {
        return Erp::canAnywhere('finance.journal');
    }

    private function base(): Builder
    {
        $q = FiscalSubmission::query();
        $branches = Erp::branchIds('finance.view');

        return $branches === null ? $q : $q->whereIn('fiscal_submissions.branch_id', $branches);
    }

    public function filtered(array $p): Builder
    {
        $q = $this->base();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('doc_number', 'ilike', "%{$search}%")->orWhere('gra_reference', 'ilike', "%{$search}%"));
        }
        if (array_key_exists((string) ($p['status'] ?? ''), FiscalSubmission::STATUSES)) {
            $q->where('status', $p['status']);
        }
        if (array_key_exists((string) ($p['type'] ?? ''), self::TYPES)) {
            $q->where('doc_type', $p['type']);
        }

        return $q;
    }

    public function rowOf(FiscalSubmission $s): array
    {
        return ['id' => $s->id, 'date' => $s->doc_date?->toDateString(), 'number' => $s->doc_number, 'type' => $s->doc_type, 'type_label' => self::TYPES[$s->doc_type] ?? $s->doc_type,
            'mode' => $s->mode, 'reference' => $s->gra_reference, 'status' => $s->status, 'status_label' => FiscalSubmission::STATUSES[$s->status] ?? $s->status, 'error' => $s->error,
            'attempts' => (int) $s->attempts, 'can_retry' => $s->status !== 'submitted' && self::canSend()];
    }

    /** @param  array<string, mixed>  $p  q, status, type, sort, dir, page, per */
    public function list(array $p): array
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'created_at';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $counts = $this->base()->toBase()->reorder()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (clone $this->filtered($p))->count(), 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'counts' => collect(array_keys(FiscalSubmission::STATUSES))->mapWithKeys(fn ($k) => [$k => (int) ($counts[$k] ?? 0)])->all(),
            'rows' => $this->filtered($p)->orderBy($sort, $dir)->orderBy('id', $dir)->forPage($page, $per)->get()->map(fn (FiscalSubmission $s) => $this->rowOf($s))->all(),
        ];
    }

    public function options(): array
    {
        $mode = app(FiscalService::class)->mode($this->user->tenant_id);

        return ['per' => self::PER_PAGE, 'mode' => $mode, 'mode_label' => FiscalService::MODES[$mode] ?? $mode, 'can_batch' => $mode === 'daily' && self::canSend(),
            'statuses' => FiscalSubmission::STATUSES, 'types' => self::TYPES];
    }

    public function find(string $id): ?FiscalSubmission
    {
        return $this->base()->find($id);
    }

    /** One report: the row, what was sent and what GRA answered (as they were stored). */
    public function show(FiscalSubmission $s): array
    {
        $json = fn ($v) => $v ? json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        return $this->rowOf($s) + ['sent' => $json($s->payload), 'answer' => $json($s->response), 'batch' => $s->batch_id,
            'next_attempt' => $s->status === 'pending' ? $s->next_attempt_at?->toIso8601String() : null];
    }
}

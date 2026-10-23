<?php

namespace App\Filament\Pages;

use App\Domain\Pos\DayCloseService;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\DayClose;
use App\Models\Tenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Cash → Close the day (owner request 2026-10-23): before leaving, one screen per branch says whether the day is wrapped
 * up, and closes it with the cash banked (and slip) or kept in the safe (DayCloseService). Past closes are listed below.
 */
class CloseDay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Cash';

    protected static ?string $navigationLabel = 'Close the day';

    protected static ?string $title = 'Close the day';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'cash/close-day';

    protected string $view = 'filament.pages.close-day';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $date = '';

    public string $banked = '';

    public string $bankRef = '';

    public string $safe = '';

    public string $note = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere(DayCloseService::PERMISSION);
    }

    public function mount(): void
    {
        $this->branch = array_key_exists($this->branch, $this->branches()) ? $this->branch : (string) array_key_first($this->branches());
        $this->date = $this->date ?: $this->today();
        $this->fillFromClose();
    }

    /** @return array<string, string> */
    public function branches(): array
    {
        $ids = Erp::branchIds(DayCloseService::PERMISSION);

        return Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all();
    }

    public function updatedBranch(): void
    {
        $this->fillFromClose();
    }

    public function updatedDate(): void
    {
        $this->fillFromClose();
    }

    public function state(): array
    {
        $b = Str::isUuid($this->branch) ? Branch::find($this->branch) : null;

        return $b ? app(DayCloseService::class)->checks($b, $this->date) : ['checks' => [], 'cash_counted' => 0, 'over_short' => 0, 'currency' => 'GHS'];
    }

    public function closed(): ?DayClose
    {
        if (! Str::isUuid($this->branch)) {
            return null; // no branch this person may close
        }

        return DayClose::where('branch_id', $this->branch)->whereDate('business_date', $this->date)->first();
    }

    /** @return Collection<int, DayClose> */
    public function recent(): Collection
    {
        if (! Str::isUuid($this->branch)) {
            return collect();
        }

        return DayClose::where('branch_id', $this->branch)->orderByDesc('business_date')->limit(14)->get();
    }

    public function closeDay(): void
    {
        $b = Branch::whereIn('id', array_keys($this->branches()))->findOrFail($this->branch); // only a branch this person may close
        $done = Erp::run(fn ($actor) => app(DayCloseService::class)->close($actor, $b, $this->date,
            ['banked' => $this->banked, 'bank_ref' => $this->bankRef, 'safe' => $this->safe, 'note' => $this->note]), 'Day closed');
        if ($done) {
            $this->fillFromClose();
        }
    }

    private function fillFromClose(): void
    {
        $c = $this->closed();
        $fmt = fn (?int $v) => $v === null ? '' : number_format($v / 100, 2, '.', '');
        $this->banked = $fmt($c?->banked_minor);
        $this->bankRef = (string) ($c?->bank_ref ?? '');
        $this->safe = $fmt($c?->safe_minor);
        $this->note = (string) ($c?->note ?? '');
    }

    /** The branch's own clock, for "today" and the times shown. */
    public function tz(): string
    {
        return (Str::isUuid($this->branch) ? Branch::find($this->branch)?->timezone : null) ?: (Tenant::find(Erp::user()->tenant_id)?->timezone ?: 'UTC');
    }

    private function today(): string
    {
        return Carbon::now($this->tz())->toDateString();
    }
}

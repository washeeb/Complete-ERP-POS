<?php

namespace App\Filament\Pages;

use App\Domain\Pharmacy\PharmacyRules;
use App\Domain\Pharmacy\PharmacySignoffs;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Prescription-only and controlled medicines sold without a pharmacist's approval (where a branch lets tellers sell
 * them): the list to sign off, and the report for an inspection, by period, branch and teller.
 */
class MedicineSignoffs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Sales to sign off';

    protected static ?string $title = 'Medicines sold without a pharmacist';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return PrescriptionResource::canViewAny();
    }

    public function getSubheading(): ?string
    {
        return 'Prescription-only and controlled medicines sold where tellers may sell them (System → Settings → Pharmacy). A pharmacist checks each sale and signs it off; '
            .'the list is the record for an inspection.';
    }

    /** @return array{0: string, 1: string} UTC bounds of the chosen days */
    private function period(?array $filters): array
    {
        $tz = Tenant::find(Erp::user()->tenant_id)?->timezone ?: 'UTC';
        $from = $filters['period']['from'] ?? null ?: now($tz)->subDays(30)->toDateString();
        $to = $filters['period']['to'] ?? null ?: now($tz)->toDateString();

        return [Carbon::parse($from, $tz)->startOfDay()->utc()->toDateTimeString(), Carbon::parse($to, $tz)->endOfDay()->utc()->toDateTimeString()];
    }

    private function rows(?array $filters): Collection
    {
        [$from, $to] = $this->period($filters);
        $status = $filters['status']['value'] ?? 'waiting';

        return PharmacyRules::unapproved(Erp::user()->tenant_id, PrescriptionResource::branchIds(), $from, $to)
            ->when($filters['branch_id']['value'] ?? null, fn ($q, $b) => $q->where('s.branch_id', $b))
            ->when($status === 'waiting', fn ($q) => $q->whereNull('so.id'))->when($status === 'signed', fn ($q) => $q->whereNotNull('so.id'))
            ->orderByDesc('s.occurred_at')->limit(2000)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Download (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function () {
                    $rows = $this->rows($this->tableFilters);
                    $branches = Branch::pluck('name', 'id');

                    return response()->streamDownload(function () use ($rows, $branches) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['Date', 'Receipt', 'Branch', 'Sold by', 'Medicines', 'Controlled', 'Signed off by', 'Signed off', 'Note'], ',', '"', '');
                        foreach ($rows as $r) {
                            fputcsv($out, [Carbon::parse($r->occurred_at)->toDateTimeString(), $r->receipt_no, $branches[$r->branch_id] ?? '', $r->cashier_name, $r->medicines,
                                $r->controlled ? 'yes' : 'no', $r->signed_by_name, $r->signed_at, $r->note], ',', '"', '');
                        }
                        fclose($out);
                    }, 'medicines-sold-without-a-pharmacist.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?array $filters) => $this->rows($filters)->mapWithKeys(fn ($r) => [$r->id => (array) $r]))
            ->columns([
                TextColumn::make('occurred_at')->label('Sold')->dateTime('j M Y H:i'),
                TextColumn::make('receipt_no')->label('Receipt'),
                TextColumn::make('cashier_name')->label('Sold by'),
                TextColumn::make('medicines')->label('Medicines')->wrap()
                    ->color(fn (array $record) => $record['controlled'] ? 'danger' : null),
                TextColumn::make('signed_by_name')->label('Signed off')->placeholder('waiting')
                    ->description(fn (array $record) => $record['signed_at'] ? Carbon::parse($record['signed_at'])->format('j M H:i').($record['note'] ? " · {$record['note']}" : '') : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Show')->default('waiting')->selectablePlaceholder(false)
                    ->options(['waiting' => 'Waiting to be signed off', 'signed' => 'Signed off', 'all' => 'All']),
                SelectFilter::make('branch_id')->label('Branch')->options(fn () => Branch::when(PrescriptionResource::branchIds(), fn ($q, $ids) => $q->whereIn('id', $ids))
                    ->orderBy('code')->pluck('name', 'id')->all()),
                Filter::make('period')->schema([DatePicker::make('from')->label('From'), DatePicker::make('to')->label('To')]),
            ])
            ->toolbarActions([
                BulkAction::make('sign')->label('Sign off')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                    ->visible(fn () => Erp::canAnywhere('pharmacy.dispense'))
                    ->schema([TextInput::make('note')->label('Note (optional)')->maxLength(255)->placeholder('e.g. checked with the patient, counselled')])
                    ->action(function (Collection $records, array $data) {
                        $n = Erp::run(fn ($actor) => app(PharmacySignoffs::class)->sign($actor, $records->pluck('id')->all(), $data['note'] ?? null));
                        Notification::make()->success()->title("{$n} sale(s) signed off")->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Nothing to sign off')
            ->emptyStateDescription('No prescription-only or controlled medicine was sold without a pharmacist in this period.');
    }
}

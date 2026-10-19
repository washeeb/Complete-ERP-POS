<?php

namespace App\Filament\Resources\Shifts;

use App\Domain\Pos\ShiftService;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Support\Erp;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Support\Exports\Exporter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till shifts';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sales.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('sales.view');

        return parent::getEloquentQuery()->with('branch', 'till', 'latestCorrection')->withCount('sales')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        $m = fn ($state, Shift $r) => $state === null ? '—' : Money::display((int) $state, $r->currency_code);

        return $table
            ->columns([
                TextColumn::make('where')->label('Till')->state(fn (Shift $r) => "{$r->branch->code} · {$r->till->code}"),
                TextColumn::make('opened_by_name')->asPerson('opened_by')->label('Opened by'),
                TextColumn::make('opened_at')->label('Opened')->dateTime()->sortable(),
                TextColumn::make('closed_at')->label('Closed')->dateTime()->placeholder('still open'),
                TextColumn::make('sales_count')->label('Sales'),
                TextColumn::make('opening_float_minor')->label('Float')->formatStateUsing($m),
                TextColumn::make('expected_cash_minor')->label('Expected cash')->formatStateUsing($m),
                // A corrected count shows the corrected figure, with the original and the reason on hover.
                TextColumn::make('counted_cash_minor')->label('Counted')
                    ->state(fn (Shift $r) => $r->latestCorrection?->counted_after_minor ?? $r->counted_cash_minor)->formatStateUsing($m)
                    ->icon(fn (Shift $r) => $r->latestCorrection ? 'heroicon-m-pencil-square' : null)->iconPosition('after')
                    ->tooltip(fn (Shift $r) => $r->latestCorrection ? 'Corrected from '.Money::display($r->latestCorrection->counted_before_minor, $r->currency_code)
                        ." by {$r->latestCorrection->corrected_by_name}: {$r->latestCorrection->reason}" : null),
                TextColumn::make('over_short_minor')->label('Over / short')
                    ->state(fn (Shift $r) => $r->latestCorrection?->over_short_after_minor ?? $r->over_short_minor)->formatStateUsing($m)
                    ->color(fn ($state) => $state === null ? null : ($state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success'))),
                // Mobile money per network and the card terminal: what was read at the close against the system.
                TextColumn::make('other')->label('MoMo & card')->state(fn (Shift $r) => self::otherSummary($r))
                    ->color(fn ($state) => str_contains((string) $state, 'short') ? 'danger' : (str_contains((string) $state, 'over') ? 'warning' : null))
                    ->tooltip(fn (Shift $r) => collect($r->otherChecks())->map(fn ($c) => "{$c['label']}: expected ".Money::display($c['expected'], $r->currency_code)
                        .', '.($c['counted'] === null ? 'not checked' : 'entered '.Money::display($c['counted'], $r->currency_code)))->implode(' · ') ?: null),
                TextColumn::make('close_note')->label('Note')->limit(40)->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed'])])
            ->recordActions([
                Action::make('report')->label('Shift report')->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->visible(fn (Shift $r) => ! $r->isOpen())->action(fn (Shift $r) => self::report($r)),
                // A shift no till can close any more (till reinstalled or replaced, or left open).
                Action::make('closeShift')->label('Close shift')->icon(Heroicon::OutlinedLockClosed)->color('warning')
                    ->visible(fn (Shift $r) => $r->isOpen() && ! $r->vault_session_id && Erp::can('cash.setup', $r->branch))
                    ->modalDescription(fn (Shift $r) => 'For a shift no till can close (the till was reinstalled or replaced, or it was left open). Expected in the drawer: '
                        .Money::display(app(ShiftService::class)->expectedCash($r), $r->currency_code).'. The over/short goes to the books as for any close.')
                    ->schema([
                        TextInput::make('counted')->label('Cash actually there')->required()->numeric()->minValue(0)->default('0'),
                        TextInput::make('reason')->label('Why it is closed here')->required()->maxLength(200)->placeholder('e.g. Till reinstalled; test shift'),
                    ])
                    ->action(fn (Shift $r, array $data) => Erp::run(fn ($actor) => app(ShiftService::class)
                        ->closeFromOffice($actor, $r, Money::toMinor((string) $data['counted'], $r->currency_code), $data['reason']), 'Shift closed')),
                // A mistyped count: the right amount and why; the difference is posted, the original stays visible.
                Action::make('correctCount')->label('Correct the count')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (Shift $r) => ! $r->isOpen() && ! $r->vault_session_id && Erp::can('cash.setup', $r->branch))
                    ->modalDescription(fn (Shift $r) => 'Recorded count: '.Money::display($r->latestCorrection?->counted_after_minor ?? (int) $r->counted_cash_minor, $r->currency_code)
                        .'; expected: '.Money::display((int) $r->expected_cash_minor, $r->currency_code).'. The shift keeps its original count; the correction and the difference in the books are recorded with your name.')
                    ->schema([
                        TextInput::make('counted')->label('The right count')->required()->numeric()->minValue(0),
                        TextInput::make('reason')->label('Why')->required()->maxLength(200)->placeholder('e.g. Typed 4000 instead of 40; cash found in the safe'),
                    ])
                    ->action(fn (Shift $r, array $data) => Erp::run(fn ($actor) => app(ShiftService::class)
                        ->correctCount($actor, $r, Money::toMinor((string) $data['counted'], $r->currency_code), $data['reason']), 'Count corrected')),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    /** "Balanced", "not checked", or each check with a difference ("MTN MoMo short GH₵ 20.00"). */
    public static function otherSummary(Shift $r): string
    {
        $checks = $r->otherChecks();
        if ($r->isOpen() || $checks === []) {
            return '—';
        }
        $off = array_filter($checks, fn ($c) => ($c['diff'] ?? 0) !== 0);
        if ($off === []) {
            return collect($checks)->contains(fn ($c) => $c['counted'] === null) ? 'Not checked' : 'Balanced';
        }

        return collect($off)->map(fn ($c) => $c['label'].' '.($c['diff'] < 0 ? 'short ' : 'over ').Money::display(abs($c['diff']), $r->currency_code))->implode('; ');
    }

    /** Shift report (Z report): the cash count, then every payment method taken, with the MoMo and card checks. */
    private static function report(Shift $r)
    {
        $cur = $r->currency_code;
        $m = fn (?int $v) => $v === null ? '' : Money::display($v, $cur);
        $counted = $r->latestCorrection?->counted_after_minor ?? $r->counted_cash_minor;
        $cash = [['Opening float', $m($r->opening_float_minor)], ['Expected in the drawer', $m($r->expected_cash_minor)], ['Counted', $m($counted)],
            ['Over / short', $m($r->latestCorrection?->over_short_after_minor ?? $r->over_short_minor)]];
        $methods = DB::table('sale_payments')->whereIn('sale_id', $r->sales()->where('status', 'posted')->select('id'))
            ->groupBy('method', 'provider')->orderBy('method')->selectRaw('method, provider, COUNT(*) AS n, SUM(amount_minor) AS amount')->get()
            ->map(fn ($p) => [(SalePayment::METHODS[$p->method] ?? $p->method).($p->provider ? ' · '.(SalePayment::MOMO_PROVIDERS[$p->provider] ?? $p->provider) : ''),
                (string) $p->n, $m((int) $p->amount)])->all();
        $checks = array_map(fn ($c) => [$c['label'], $m($c['expected']), $c['counted'] === null ? 'not checked' : $m($c['counted']),
            $c['diff'] === null ? '' : $m($c['diff'])], $r->otherChecks());
        $where = "{$r->branch->code} · {$r->till->code} · opened {$r->opened_at->format('j M Y H:i')} by {$r->opened_by_name} · closed "
            .($r->closed_at?->format('j M Y H:i') ?? '').' by '.($r->closed_by_name ?? '');
        $sheets = [
            ['title' => 'Cash', 'headings' => ['', 'Amount'], 'rows' => $cash, 'note' => $where.' · Checked by: ______________', 'numeric' => [1]],
            ['title' => 'Takings by payment method', 'headings' => ['Method', 'Payments', 'Amount'], 'rows' => $methods, 'numeric' => [1, 2]],
            ['title' => 'Mobile money and card check', 'headings' => ['Check', 'Expected', 'Entered at close', 'Over / short'], 'rows' => $checks, 'numeric' => [1, 2, 3]],
        ];

        return Erp::run(fn ($actor) => app(Exporter::class)->pdf($actor, "Shift report {$r->till->code}", $sheets, [$r->branch->code], 'portrait'));
    }

    public static function getPages(): array
    {
        return ['index' => ListShifts::route('/')];
    }
}

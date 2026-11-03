<?php

namespace App\Filament\Pages;

use App\Domain\Projects\Projects;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ProjectUi;
use App\Models\ProjectRate;
use App\Models\User;
use App\Security\Authorizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Projects → Hourly rates: what an hour of each person who logs time costs the company (pay and overheads) — used for
 * project costs — and what it is billed at (else the project's own rate). Seen only by people who run projects.
 */
class ProjectRates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $navigationLabel = 'Hourly rates';

    protected static ?string $title = 'Hourly rates';

    protected static ?string $slug = 'project-rates';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return ProjectUi::can('projects.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Cost per hour: pay plus overheads, divided by the hours a person works (e.g. GHS 3,000 a month ÷ 176 hours ≈ GHS 17). It values approved time on projects.';
    }

    public function table(Table $table): Table
    {
        $authz = app(Authorizer::class);
        $ids = User::where('is_active', true)->get()->filter(fn (User $u) => $authz->allowsAnywhere($u, 'projects.time'))->pluck('id');
        $rate = fn (User $u) => ProjectRate::where('user_id', $u->id)->first();

        return $table
            ->query(fn () => User::query()->whereIn('id', $ids))
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('cost')->label('Costs per hour')->alignEnd()->state(fn (User $u) => ($r = $rate($u)) ? FinanceUi::money($r->cost_rate_minor) : 'Not set')
                    ->color(fn (User $u) => $rate($u) ? null : 'warning'),
                TextColumn::make('bill')->label('Billed per hour')->alignEnd()->state(fn (User $u) => ($r = $rate($u)) && $r->bill_rate_minor !== null ? FinanceUi::money($r->bill_rate_minor) : 'Project’s rate'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nobody logs time on projects yet')
            ->emptyStateDescription('Give people the right “Log your own time on projects” (People → Roles).')
            ->recordActions([
                Action::make('set')->label('Set rates')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (User $record) => ['cost' => ($rate($record)?->cost_rate_minor ?? 0) / 100,
                        'bill' => ($b = $rate($record)?->bill_rate_minor) === null ? null : $b / 100])
                    ->schema([
                        TextInput::make('cost')->label('Costs the company per hour')->numeric()->minValue(0)->required()->prefix(fn () => FinanceUi::currency()),
                        TextInput::make('bill')->label('Billed per hour (empty: the project’s rate)')->numeric()->minValue(0)->prefix(fn () => FinanceUi::currency()),
                    ])
                    ->action(fn (User $record, array $data) => Erp::run(fn ($actor) => app(Projects::class)->saveRate($actor, $record, FinanceUi::toMinor($data['cost']),
                        ($data['bill'] ?? '') === '' || $data['bill'] === null ? null : FinanceUi::toMinor($data['bill'])), 'Rates saved')),
            ]);
    }
}

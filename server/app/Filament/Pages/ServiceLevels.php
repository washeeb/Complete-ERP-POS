<?php

namespace App\Filament\Pages;

use App\Domain\Service\ServiceDesk;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Models\ServiceLevel;
use App\Models\Ticket;
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

/** Service desk → Deadlines: how fast tickets of each priority are answered and fixed (around the clock). */
class ServiceLevels extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Service desk';

    protected static ?string $navigationLabel = 'Deadlines';

    protected static ?string $title = 'Deadlines';

    protected static ?string $slug = 'service-levels';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return ServiceUi::can('helpdesk.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Time to answer and to fix a ticket, by its priority, counted around the clock; the clock stops while you wait for the customer. The person handling it is warned when 75 % of the time is gone, managers when it is missed.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ServiceLevel::query()->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END"))
            ->paginated(false)
            ->columns([
                TextColumn::make('priority')->formatStateUsing(fn ($state) => Ticket::PRIORITIES[$state] ?? $state)->weight('bold'),
                TextColumn::make('respond_minutes')->label('Answer within')->formatStateUsing(fn ($state) => ServiceLevel::span((int) $state)),
                TextColumn::make('resolve_minutes')->label('Fix within')->formatStateUsing(fn ($state) => ServiceLevel::span((int) $state)),
            ])
            ->recordActions([
                Action::make('change')->label('Change')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (ServiceLevel $l) => ['respond' => $l->respond_minutes / 60, 'resolve' => $l->resolve_minutes / 60])
                    ->schema([TextInput::make('respond')->label('Answer within (hours)')->numeric()->minValue(0.25)->required(),
                        TextInput::make('resolve')->label('Fix within (hours)')->numeric()->minValue(0.25)->required()->helperText('e.g. 72 for three days')])
                    ->action(fn (ServiceLevel $record, array $data) => Erp::run(fn ($actor) => app(ServiceDesk::class)->saveLevel($actor, $record,
                        (int) round($data['respond'] * 60), (int) round($data['resolve'] * 60)), 'Saved')),
            ]);
    }
}

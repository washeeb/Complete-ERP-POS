<?php

namespace App\Filament\Resources\Projects;

use App\Domain\Projects\Projects;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ProjectUi;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Projects → Projects: jobs for customers (milestones or time and materials) and internal ones, with their costs and profit. */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $navigationLabel = 'Projects';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return ProjectUi::can('projects.view');
    }

    public static function canCreate(): bool
    {
        return ProjectUi::can('projects.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return ProjectUi::can('projects.view'); // the page shows the project; changing it needs projects.manage (checked on save)
    }

    public static function canDelete(Model $record): bool
    {
        return false; // finished or cancelled, never deleted
    }

    public static function getEloquentQuery(): Builder
    {
        return ProjectUi::scope(parent::getEloquentQuery()->with(['customer', 'manager']));
    }

    public static function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->minValue(0)->prefix(fn () => FinanceUi::currency());

        return $schema->columns(1)->components([
            Section::make('How it is doing')->columns(4)->hiddenOn('create')->schema(fn (?Project $record) => $record ? self::figures($record) : []),
            Section::make()->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                Select::make('status')->options(array_slice(Project::STATUSES, 0, 3, true))->default('active')->required()->selectablePlaceholder(false)->hiddenOn('create'),
                Radio::make('billing')->label('How it is billed')->options(Project::BILLING)->default('time')->required()->live()->columnSpanFull()->inline(),
                Select::make('customer_id')->label('Customer')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)
                    ->required(fn (Get $get) => $get('billing') !== 'internal')->default(fn () => request()->query('customer')),
                Select::make('branch_id')->label('Branch')->options(fn () => ProjectUi::branches())->default(fn () => array_key_first(ProjectUi::branches()))->required(),
                Select::make('manager_id')->label('Project manager')->default(fn () => Erp::user()?->id)
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => app(Authorizer::class)->allowsAnywhere($u, 'projects.view'))->pluck('name', 'id')),
                DatePicker::make('starts_on')->label('Starts'),
                DatePicker::make('ends_on')->label('Due to finish'),
                $money('budget_cost', 'Budget (what it should cost)'),
                $money('contract', 'Agreed price (before tax)')->visible(fn (Get $get) => $get('billing') === 'milestones'),
                $money('hourly_rate', 'Hourly rate billed')->visible(fn (Get $get) => $get('billing') === 'time')
                    ->helperText('For people without a billing rate of their own (Projects → Hourly rates).'),
                TextInput::make('markup')->label('Materials billed at cost +')->numeric()->minValue(0)->maxValue(500)->suffix('%')->default(0)
                    ->visible(fn (Get $get) => $get('billing') === 'time'),
                Select::make('service_product_id')->label('Service on invoices')->searchable()->visible(fn (Get $get) => $get('billing') !== 'internal')
                    ->required(fn (Get $get) => $get('billing') !== 'internal')
                    ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where('track_stock', false)->where('name', 'ilike', "%{$search}%")->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                    ->helperText('A service (not a stock item), e.g. “Professional services”: time, milestones and bought-in costs show as it, with its tax.'),
                Textarea::make('description')->columnSpanFull(),
            ]),
        ]);
    }

    /** The figures at the top of a project. */
    public static function figures(Project $p): array
    {
        $f = app(Projects::class)->figures($p);
        $m = fn (int $v) => FinanceUi::money($v);
        $box = fn (string $label, string $value, ?string $note = null) => Text::make(new HtmlString(
            '<div class="text-xs text-gray-500">'.e($label).'</div><div class="text-lg font-semibold">'.e($value).'</div>'.($note ? '<div class="text-xs text-gray-500">'.e($note).'</div>' : '')));

        return [
            $box('Cost so far', $m($f['cost']), $f['budget'] ? 'Budget '.$m($f['budget']).' ('.round($f['cost'] * 100 / max(1, $f['budget'])).' % used)' : null),
            $box($p->isBilled() ? 'Invoiced (before tax)' : 'Hours', $p->isBilled() ? $m($f['invoiced']) : number_format($f['hours'], 1),
                $p->billing === 'milestones' && $p->contract_minor ? 'of '.$m($p->contract_minor).' agreed' : null),
            $box($p->billing === 'time' ? 'Not yet invoiced' : 'Hours logged', $p->billing === 'time' ? $m($f['unbilled']) : number_format($f['hours'], 1)),
            $box($p->isBilled() ? 'Profit so far' : 'Tasks done', $p->isBilled() ? $m($f['profit']).($f['margin'] !== null ? " ({$f['margin']} %)" : '') : ($f['progress'] === null ? '—' : "{$f['progress']} %")),
        ];
    }

    public static function toDomain(array $data): array
    {
        foreach (['budget_cost' => 'budget_cost_minor', 'contract' => 'contract_minor', 'hourly_rate' => 'hourly_rate_minor'] as $in => $out) {
            if (array_key_exists($in, $data)) {
                $data[$out] = $data[$in] === null || $data[$in] === '' ? null : FinanceUi::toMinor($data[$in]);
                unset($data[$in]);
            }
        }
        if (array_key_exists('markup', $data)) {
            $data['materials_markup_bp'] = (int) round(((float) $data['markup']) * 100);
            unset($data['markup']);
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('name')->searchable()->weight('bold')->wrap()->description(fn (Project $p) => $p->customer?->name ?? 'Internal'),
                TextColumn::make('billing')->label('Billed')->formatStateUsing(fn ($state) => match ($state) {
                    'milestones' => 'Milestones', 'time' => 'Time & materials', default => 'Internal'
                }),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Project::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'info', 'done' => 'success', 'on_hold' => 'warning', default => 'gray'
                    }),
                TextColumn::make('cost')->label('Cost')->alignEnd()->state(fn (Project $p) => FinanceUi::money((int) $p->costs()->sum('cost_minor')))
                    ->description(fn (Project $p) => $p->budget_cost_minor ? 'of '.FinanceUi::money($p->budget_cost_minor) : null),
                TextColumn::make('ends_on')->label('Due')->date('j M Y')->sortable()->placeholder('—')
                    ->color(fn (Project $p) => $p->isOpen() && $p->ends_on?->isPast() ? 'danger' : null),
                TextColumn::make('manager.name')->label('Manager')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Project::STATUSES)->default('active'),
                SelectFilter::make('billing')->label('Billed')->options(Project::BILLING),
            ])
            ->emptyStateHeading('No projects yet')
            ->emptyStateDescription('A project collects its costs (stock, bills, expenses, time) by itself and shows its profit.');
    }

    /** The project's own buttons: stock in and out, invoicing, closing. */
    public static function projectActions(): array
    {
        $lines = Repeater::make('lines')->label('Items')->minItems(1)->defaultItems(1)->columns(2)->addActionLabel('Add an item')->schema([
            Fields::productUnit()->required(), TextInput::make('qty')->label('Quantity')->numeric()->minValue(0.001)->required(),
        ]);
        $store = Select::make('warehouse_id')->label('Store')->required()->options(fn () => Fields::warehouses('stock.adjust'));
        $stock = fn (bool $return) => function (Project $record, array $data) use ($return) {
            Erp::run(fn ($actor) => app(Projects::class)->issue($actor, $record, Warehouse::findOrFail($data['warehouse_id']), $data['lines'], $data['note'] ?? null, $return),
                $return ? 'Returned to stock' : 'Issued to the project');
        };

        return [
            Action::make('issue')->label('Issue stock')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn (Project $p) => $p->isOpen() && ProjectUi::can('projects.manage'))
                ->modalDescription('Items taken out of a store for this project, at their cost.')
                ->schema([$store, $lines, TextInput::make('note')->maxLength(255)])->action($stock(false)),
            Action::make('return')->label('Return stock')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (Project $p) => $p->isOpen() && ProjectUi::can('projects.manage') && $p->costs()->where('kind', 'material')->exists())
                ->modalDescription('Items not used, back into a store at what the project was charged for them.')
                ->schema([$store, $lines, TextInput::make('note')->maxLength(255)])->action($stock(true)),
            Action::make('invoiceTime')->label('Invoice time and materials')->icon(Heroicon::OutlinedDocumentText)->color('success')
                ->visible(fn (Project $p) => $p->billing === 'time' && ProjectUi::can('projects.bill'))
                ->requiresConfirmation()->modalDescription(fn (Project $p) => 'Everything not yet invoiced ('.FinanceUi::money((int) app(Projects::class)->unbilled($p)->sum('bill_minor'))
                    .' before tax) goes on one invoice. To leave some out, tick the costs to invoice in the list below instead.')
                ->action(function (Project $record, $livewire) {
                    $invoice = Erp::run(fn ($actor) => app(Projects::class)->invoiceTime($actor, $record), 'Invoiced');
                    $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
            Action::make('finish')->label('Finish')->icon(Heroicon::OutlinedCheckCircle)->color('gray')->requiresConfirmation()
                ->visible(fn (Project $p) => $p->isOpen() && ProjectUi::can('projects.manage'))
                ->modalDescription('Its open tasks are closed, and for a billed project, costs not yet invoiced move from work in progress to project costs.')
                ->action(fn (Project $record) => Erp::run(fn ($actor) => app(Projects::class)->close($actor, $record, 'done'), 'Project finished')),
            Action::make('cancel')->label('Cancel project')->icon(Heroicon::OutlinedXCircle)->color('danger')->requiresConfirmation()
                ->visible(fn (Project $p) => $p->isOpen() && ProjectUi::can('projects.manage'))
                ->action(fn (Project $record) => Erp::run(fn ($actor) => app(Projects::class)->close($actor, $record, 'cancelled'), 'Project cancelled')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->requiresConfirmation()
                ->visible(fn (Project $p) => ! $p->isOpen() && ProjectUi::can('projects.manage'))
                ->action(fn (Project $record) => Erp::run(fn ($actor) => app(Projects::class)->reopen($actor, $record), 'Project reopened')),
        ];
    }

    public static function getRelations(): array
    {
        return [TasksRelationManager::class, MilestonesRelationManager::class, CostsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}

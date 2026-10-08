<?php

namespace App\Filament\Resources\Employees;

use App\Domain\Hr\HrService;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Employees. Pay and bank details are only shown to HR (hr.manage). */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('hr.view') || Erp::canAnywhere('hr.manage')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('hr.manage') && config('erp.node.role') !== 'branch';
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('hr.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::canAnywhere('hr.manage') ? null : Erp::branchIds('hr.view');

        return parent::getEloquentQuery()->with('branch', 'department')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function toForm(Employee $e): array
    {
        $m = fn ($v) => $v ? Money::format((int) $v, FinanceUi::currency()) : null;

        return $e->only(['name', 'gender', 'phone', 'email', 'address', 'ghana_card_no', 'ssnit_no', 'department_id', 'branch_id', 'job_title', 'employment_type',
            'pay_method', 'bank_name', 'bank_account_no', 'momo_number', 'overtime_junior', 'ssnit_exempt', 'notes', 'user_id', 'takes_appointments'])
            + ['commission_pct' => $e->commission_bp / 100, 'date_of_birth' => $e->date_of_birth?->toDateString(), 'hire_date' => $e->hire_date?->toDateString(), 'contract_end' => $e->contract_end?->toDateString(),
                'basic_salary' => $m($e->basic_salary_minor), 'transport_allowance' => $m($e->transport_allowance_minor), 'meal_allowance' => $m($e->meal_allowance_minor),
                'welfare' => $m($e->welfare_minor), 'overtime_rate' => $e->overtime_rate_bp / 10000, 'tier3_pct' => $e->tier3_bp / 100];
    }

    public static function toDomain(array $d): array
    {
        $out = collect($d)->except(['basic_salary', 'transport_allowance', 'meal_allowance', 'welfare', 'overtime_rate', 'tier3_pct', 'commission_pct'])->all();

        return $out + ['basic_salary_minor' => FinanceUi::toMinor($d['basic_salary'] ?? 0), 'transport_allowance_minor' => FinanceUi::toMinor($d['transport_allowance'] ?? 0),
            'meal_allowance_minor' => FinanceUi::toMinor($d['meal_allowance'] ?? 0), 'welfare_minor' => FinanceUi::toMinor($d['welfare'] ?? 0),
            'overtime_rate_bp' => (int) round((float) ($d['overtime_rate'] ?? 1.5) * 10000), 'tier3_bp' => (int) round((float) ($d['tier3_pct'] ?? 0) * 100),
            'overtime_junior' => (bool) ($d['overtime_junior'] ?? false), 'ssnit_exempt' => (bool) ($d['ssnit_exempt'] ?? false),
            'takes_appointments' => (bool) ($d['takes_appointments'] ?? false), 'commission_bp' => (int) round((float) ($d['commission_pct'] ?? 0) * 100)];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Person')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('gender')->options(['female' => 'Female', 'male' => 'Male']),
                DatePicker::make('date_of_birth')->maxDate(now()),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('address')->maxLength(255),
                TextInput::make('ghana_card_no')->label('Ghana Card no. (TIN)')->maxLength(30),
                TextInput::make('ssnit_no')->label('SSNIT no.')->maxLength(30),
                Select::make('user_id')->label('Their sign-in (for clocking in and self-service)')->searchable()
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->username})"])),
            ]),
            Section::make('Job')->columns(3)->schema([
                Select::make('branch_id')->label('Branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                Select::make('department_id')->label('Department')->options(fn () => Department::orderBy('name')->pluck('name', 'id')),
                TextInput::make('job_title')->maxLength(255),
                Select::make('employment_type')->required()->options(Employee::TYPES)->default('permanent'),
                DatePicker::make('hire_date')->label('Start date')->required()->default(now()),
                DatePicker::make('contract_end')->label('Contract ends'),
            ]),
            Section::make('Pay (per month)')->columns(3)->schema([
                TextInput::make('basic_salary')->label('Basic salary')->required()->numeric()->minValue(0),
                TextInput::make('transport_allowance')->numeric()->minValue(0),
                TextInput::make('meal_allowance')->numeric()->minValue(0),
                TextInput::make('overtime_rate')->label('Overtime rate (× hourly)')->numeric()->minValue(1)->default('1.5'),
                Toggle::make('overtime_junior')->label('Qualifying junior staff (overtime taxed at 5 % / 10 %)'),
                Toggle::make('ssnit_exempt')->label('Not in SSNIT (e.g. pensioner)'),
                TextInput::make('tier3_pct')->label('Provident fund (Tier 3) % of basic')->numeric()->minValue(0)->maxValue(100)->default('0'),
                TextInput::make('welfare')->label('Welfare dues')->numeric()->minValue(0),
                TextInput::make('commission_pct')->label('Commission % (Services)')->numeric()->minValue(0)->maxValue(100)->default('0')
                    ->helperText('Share of the price before tax of the services they do (a product can set its own rate). Paid with payroll.'),
                Toggle::make('takes_appointments')->label('Takes appointments (Services)')
                    ->helperText('Shows them in the staff calendar and the till’s "done by" list.'),
            ]),
            Section::make('Paid by')->columns(3)->schema([
                Select::make('pay_method')->required()->options(Employee::PAY_METHODS)->default('bank')->live(),
                TextInput::make('bank_name')->visible(fn (Get $get) => $get('pay_method') === 'bank')->maxLength(255),
                TextInput::make('bank_account_no')->label('Account number')->visible(fn (Get $get) => $get('pay_method') === 'bank')->maxLength(40),
                TextInput::make('momo_number')->label('MoMo number')->visible(fn (Get $get) => $get('pay_method') === 'momo')->maxLength(20),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->description(fn (Employee $r) => $r->job_title),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('employment_type')->label('Type')->formatStateUsing(fn ($state) => Employee::TYPES[$state] ?? $state),
                TextColumn::make('hire_date')->label('Started')->date(),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'terminated' => 'Left'])->default('active'),
                SelectFilter::make('branch_id')->label('Branch')->options(fn () => FinanceUi::branches())])
            ->defaultSort('number')
            ->recordActions([EditAction::make(), self::terminateAction()]);
    }

    public static function terminateAction(): Action
    {
        return Action::make('terminate')->label('End employment')->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->visible(fn (Employee $r) => $r->status === 'active' && Erp::canAnywhere('hr.manage'))
            ->schema([DatePicker::make('date')->label('Last day')->required()->default(now()), TextInput::make('reason')->required()->maxLength(255)])
            ->action(fn (Employee $record, array $data) => Erp::run(fn ($actor) => app(HrService::class)->terminate($actor, $record, $data['date'], $data['reason']), 'Employment ended'));
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployees::route('/'), 'create' => CreateEmployee::route('/create'), 'edit' => EditEmployee::route('/{record}/edit')];
    }
}

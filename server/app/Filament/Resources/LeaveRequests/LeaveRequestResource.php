<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Domain\Hr\HrService;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Support\Erp;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Leave requests (owner decision 2026-10-14: Ghana statutory types; approved by the line manager). */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'Leave';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('leave.approve') || Erp::canAnywhere('hr.manage')) && config('erp.node.role') !== 'branch';
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
        $ids = Erp::canAnywhere('hr.manage') ? null : Erp::branchIds('leave.approve');

        return parent::getEloquentQuery()->with('employee', 'leaveType')
            ->when($ids !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->whereIn('branch_id', $ids)));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'requested')->count();

        return $n ? (string) $n : null;
    }

    /** Request leave (for yourself from My HR, or by HR for anyone). */
    public static function requestForm(bool $chooseEmployee): array
    {
        return array_values(array_filter([
            $chooseEmployee ? Select::make('employee_id')->label('Employee')->required()->searchable()
                ->options(fn () => Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id')) : null,
            Select::make('leave_type_id')->label('Type')->required()->options(fn () => LeaveType::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            DatePicker::make('starts_on')->label('From')->required(),
            DatePicker::make('ends_on')->label('To (last day off)')->required(),
            TextInput::make('reason')->maxLength(255),
            FileUpload::make('document_path')->label('Document (e.g. doctor\'s note)')->disk('local')->directory('leave-documents')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')->label('Employee')->searchable(),
                TextColumn::make('leaveType.name')->label('Type'),
                TextColumn::make('starts_on')->label('From')->date()->sortable(),
                TextColumn::make('ends_on')->label('To')->date(),
                TextColumn::make('days')->label('Working days'),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => LeaveRequest::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'rejected', 'cancelled' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(LeaveRequest::STATUSES)])
            ->defaultSort('starts_on', 'desc')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (LeaveRequest $r) => $r->status === 'requested' && $r->employee->user_id !== Erp::user()?->id)
                    ->action(fn (LeaveRequest $record) => Erp::run(fn ($actor) => app(HrService::class)->decideLeave($actor, $record, true), 'Leave approved')),
                Action::make('refuse')->label('Refuse')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (LeaveRequest $r) => $r->status === 'requested' && $r->employee->user_id !== Erp::user()?->id)
                    ->schema([TextInput::make('note')->label('Reason')->required()->maxLength(255)])
                    ->action(fn (LeaveRequest $record, array $data) => Erp::run(fn ($actor) => app(HrService::class)->decideLeave($actor, $record, false, $data['note']), 'Leave refused')),
                Action::make('cancel')->label('Cancel')->color('gray')->requiresConfirmation()
                    ->visible(fn (LeaveRequest $r) => in_array($r->status, ['requested', 'approved'], true) && ! $r->starts_on->isPast())
                    ->action(fn (LeaveRequest $record) => Erp::run(fn ($actor) => app(HrService::class)->cancelLeave($actor, $record), 'Leave cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveRequests::route('/')];
    }
}

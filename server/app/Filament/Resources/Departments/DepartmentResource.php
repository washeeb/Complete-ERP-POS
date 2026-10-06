<?php

namespace App\Filament\Resources\Departments;

use App\Domain\Hr\HrService;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Support\Erp;
use App\Models\Department;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Departments (reached from Employees). */
class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('hr.view') || Erp::canAnywhere('hr.manage')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('hr.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('hr.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([TextInput::make('code')->required()->maxLength(20), TextInput::make('name')->required()->maxLength(255)]);
    }

    public static function save(Department $d, array $data): Department
    {
        return Erp::run(fn ($actor) => app(HrService::class)->saveDepartment($actor, $d, (string) $data['code'], (string) $data['name']));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code'), TextColumn::make('name')])
            ->recordActions([EditAction::make()->using(fn (Department $record, array $data) => self::save($record, $data))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDepartments::route('/')];
    }
}

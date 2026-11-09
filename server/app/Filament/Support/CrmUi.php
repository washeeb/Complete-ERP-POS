<?php

namespace App\Filament\Support;

use App\Domain\Crm\Crm;
use App\Models\Branch;
use App\Models\CrmActivity;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\IndustryContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** What the CRM pages share: who may see them (the add-on and the rights), the branches shown, the follow-up action. */
final class CrmUi
{
    public static function canView(): bool
    {
        return Crm::enabled() && Erp::canAnywhere('crm.view');
    }

    public static function canManage(): bool
    {
        return Crm::enabled() && Erp::canAnywhere('crm.manage');
    }

    /** Only records of the branches the person sees CRM records for. */
    public static function scope(Builder $q, string $column = 'branch_id'): Builder
    {
        $b = Erp::branchIds('crm.view');

        return $b === null ? $q : $q->whereIn($column, $b);
    }

    /** @return array<string, string> branches the person may put CRM records in */
    public static function branches(): array
    {
        $b = Erp::branchIds('crm.manage');

        return Branch::where('is_active', true)->when($b !== null, fn ($q) => $q->whereIn('id', $b))->orderBy('code')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> people who work CRM records (all branches the person sees) */
    public static function people(): array
    {
        $authz = app(Authorizer::class);

        return IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->get()->filter(fn (User $u) => $authz->allowsAnywhere($u, 'crm.manage'))->pluck('name', 'id')->all();
    }

    /** "Follow-up" on a lead, deal or customer row: something to do later, or a note of what just happened. */
    public static function followUpAction(string $field): Action
    {
        return Action::make('followUp')->label('Follow-up')->icon(Heroicon::OutlinedBellAlert)->color('gray')
            ->visible(fn () => self::canManage())
            ->modalHeading('Plan a follow-up or note what happened')
            ->schema([
                Select::make('type')->label('What')->options(CrmActivity::TYPES)->default('call')->required()->live()->selectablePlaceholder(false),
                TextInput::make('subject')->label('About')->required()->maxLength(255)->placeholder('e.g. Call back about the price'),
                DateTimePicker::make('due_at')->label('When (leave empty if it already happened)')->seconds(false)
                    ->visible(fn (Get $get) => $get('type') !== 'note')->default(now()->addDay()->setTime(9, 0)),
                Select::make('assigned_to')->label('Who does it')->options(fn () => self::people())->default(fn () => Erp::user()?->id)
                    ->visible(fn (Get $get) => $get('type') !== 'note' && $get('due_at')),
                TextInput::make('outcome')->label('What came of it')->maxLength(255)->visible(fn (Get $get) => $get('type') !== 'note' && ! $get('due_at')),
                Textarea::make('details')->label('Details')->rows(3),
            ])
            ->action(fn (Model $record, array $data) => Erp::run(fn ($actor) => app(Crm::class)->logActivity($actor, $data + [$field => $record->getKey()]),
                ($data['due_at'] ?? null) ? 'Follow-up planned' : 'Noted'));
    }
}

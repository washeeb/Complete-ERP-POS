<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\Recorder;
use App\Filament\Support\Erp;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** Settings → Modules: enable, disable or reserve modules for one branch at a time. */
class ManageModules extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Modules';

    protected static ?string $title = 'Modules per branch';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('admin.modules.manage');
    }

    public function mount(): void
    {
        $first = $this->branches()->keys()->first();
        $this->form->fill(['branch_id' => $first] + $this->statusesFor($first));
        $this->rememberData(); // what is on screen now counts as saved
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (['industry' => 'Industry modules', 'department' => 'Optional department modules'] as $kind => $label) {
            $fields = [];
            foreach (ModuleService::licensed() as $m) { // only modules the licence covers
                if ($m->kind !== $kind) {
                    continue;
                }
                $fields[] = ToggleButtons::make('modules.'.$m->key)->label($m->name)->inline()->grouped()
                    ->helperText($m->statusLabel().' — '.$m->description)
                    ->options($m->isAvailable()
                        ? ['enabled' => 'Enabled', 'disabled' => 'Off']
                        : ['reserved' => 'Reserved', 'disabled' => 'Off'])
                    ->colors(['enabled' => 'success', 'reserved' => 'warning', 'disabled' => 'gray']);
            }
            $sections[] = Section::make($label)->schema($fields)->columns(2);
        }

        return $schema->statePath('data')->components([
            Select::make('branch_id')->label('Branch')->options($this->branches())->required()->live()
                ->afterStateUpdated(function ($state) {
                    $this->form->fill(['branch_id' => $state] + $this->statusesFor($state));
                    $this->rememberData(); // another branch's modules: not a change
                }),
            Section::make('Core')->description('Company, users, roles, devices, audit, settings — always installed.')->compact(),
            ...$sections,
            TextInput::make('reason')->label('Reason for change')->maxLength(255),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save modules')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $branch = Branch::findOrFail($data['branch_id']);
        $current = app(ModuleService::class)->statuses($branch);
        $changes = collect($data['modules'] ?? [])->filter(fn ($status, $key) => ($current[$key] ?? 'disabled') !== $status);
        // Enable/reserve before disable so dependencies resolve in a sensible order.
        $ordered = $changes->sortBy(fn ($status) => $status === 'disabled' ? 1 : 0);

        Erp::run(fn ($actor) => app(Recorder::class)->transaction(function () use ($actor, $ordered, $branch, $data) {
            foreach ($ordered as $key => $status) {
                app(ModuleService::class)->setStatus($actor, $branch, $key, $status, $data['reason'] ?? null);
            }
        }), $ordered->isEmpty() ? 'Nothing changed' : 'Modules updated');
        $this->rememberData();
    }

    /** @return Collection<string, string> */
    private function branches()
    {
        $ids = Erp::branchIds('admin.modules.manage');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()
            ->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"]);
    }

    private function statusesFor(?string $branchId): array
    {
        if (! $branchId) {
            return ['modules' => []];
        }
        $current = app(ModuleService::class)->statuses(Branch::findOrFail($branchId));
        $out = [];
        foreach (ModuleService::licensed() as $m) {
            $out[$m->key] = $current[$m->key] ?? 'disabled';
        }

        return ['modules' => $out];
    }
}

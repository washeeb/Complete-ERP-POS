<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\Recorder;
use App\Filament\Support\Erp;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Generated from Settings::DEFINITIONS — a new definition appears here with no screen work. */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Erp::can('admin.settings.manage');
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $values = [];
        foreach (array_keys(Settings::DEFINITIONS) as $key) {
            $values[self::field($key)] = $settings->get($key, Erp::user()->tenant_id);
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $groups = [];
        foreach (Settings::DEFINITIONS as $key => $def) {
            $groups[$def['group']][] = match ($def['type']) {
                'bool' => Toggle::make(self::field($key))->label($def['label'])->helperText($def['help']),
                'string' => TextInput::make(self::field($key))->label($def['label'])->helperText($def['help'])->maxLength($def['max']),
                'choice' => Select::make(self::field($key))->label($def['label'])->helperText($def['help'])->options($def['options'])->required()->selectablePlaceholder(false),
                default => TextInput::make(self::field($key))->label($def['label'])->helperText($def['help'])
                    ->numeric()->integer()->required()->minValue($def['min'])->maxValue($def['max']),
            };
        }

        // An industry's settings show only where that industry runs (and follow the industry chosen at the top).
        $industryGroups = ['Restaurant' => 'restaurant', 'Pharmacy' => 'pharmacy', 'Grocery' => 'grocery', 'Fashion' => 'fashion', 'Services' => 'services', 'Distribution' => 'distribution'];

        return $schema->statePath('data')->components([
            ...collect($groups)->map(fn ($fields, $group) => Section::make($group)->schema($fields)
                ->visible(fn () => ! isset($industryGroups[$group]) || ModuleService::enabledAnywhere($industryGroups[$group])))->values()->all(),
            TextInput::make('reason')->label('Reason for change')->maxLength(255),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save settings')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(Settings::class);
        Erp::run(fn ($actor) => app(Recorder::class)->transaction(function () use ($actor, $data, $settings) {
            foreach (array_keys(Settings::DEFINITIONS) as $key) {
                if (! array_key_exists(self::field($key), $data)) {
                    continue; // a hidden section (industry not switched on here) keeps its value
                }
                if ($data[self::field($key)] != $settings->get($key, $actor->tenantId)) {
                    $settings->set($actor, $key, $data[self::field($key)], $data['reason'] ?? null);
                }
            }
        }), 'Settings saved');
    }

    /** Dots in keys would nest the form state; keep each setting a flat field. */
    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}

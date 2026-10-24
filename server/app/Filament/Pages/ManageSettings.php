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
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Generated from Settings::DEFINITIONS — a new definition appears here with no screen work. The groups open closed, as a
 * short list to choose from, and the search box shows only the settings whose name or explanation has the words typed,
 * across every group (owner request 2026-10-24: the page had grown too long to find anything).
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    /** Words typed in the search box. */
    public string $find = '';

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
        $keys = [];
        foreach (Settings::DEFINITIONS as $key => $def) {
            $field = match ($def['type']) {
                'bool' => Toggle::make(self::field($key))->label($def['label'])->helperText($def['help']),
                'string' => TextInput::make(self::field($key))->label($def['label'])->helperText($def['help'])->maxLength($def['max']),
                'choice' => Select::make(self::field($key))->label($def['label'])->helperText($def['help'])->options($def['options'])->required()->selectablePlaceholder(false),
                default => TextInput::make(self::field($key))->label($def['label'])->helperText($def['help'])
                    ->numeric()->integer()->required()->minValue($def['min'])->maxValue($def['max']),
            };
            // While searching, only the settings that match show (hidden ones keep their value when saving).
            $groups[$def['group']][] = $field->visible(fn () => $this->matches($key));
            $keys[$def['group']][] = $key;
        }

        // An industry's settings show only where that industry runs (and follow the industry chosen at the top).
        $industryGroups = ['Restaurant' => 'restaurant', 'Pharmacy' => 'pharmacy', 'Grocery' => 'grocery', 'Fashion' => 'fashion', 'Services' => 'services', 'Distribution' => 'distribution'];

        return $schema->statePath('data')->components([
            ...collect($groups)->map(fn ($fields, $group) => Section::make($group)->schema($fields)->description(count($fields).' setting(s)')
                ->collapsible()->collapsed(fn () => trim($this->find) === '') // a short list of groups; searching opens what matches
                ->visible(fn () => (! isset($industryGroups[$group]) || ModuleService::enabledAnywhere($industryGroups[$group]))
                    && collect($keys[$group])->contains(fn ($k) => $this->matches($k))))->values()->all(),
            TextInput::make('reason')->label('Reason for change')->maxLength(255),
        ]);
    }

    /** Does the setting have every word typed (in its name, explanation, group or options)? */
    public function matches(string $key): bool
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($this->find))) ?: []);
        if ($words === []) {
            return true;
        }
        $d = Settings::DEFINITIONS[$key];
        $text = mb_strtolower($d['group'].' '.$d['label'].' '.$d['help'].' '.implode(' ', $d['options'] ?? []).' '.$key);

        return collect($words)->every(fn ($w) => str_contains($text, $w));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('saveTop')->label('Save settings')->action(fn () => $this->save())]; // no scrolling to the bottom to save
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.settings-search'),
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

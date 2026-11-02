<?php

namespace App\Filament\Auth;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Support\Look;
use App\Support\NotifyPrefs;
use App\Support\Settings;
use App\Support\UserPrefs;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;
use Spatie\LaravelPasskeys\Livewire\PasskeysComponent;

/**
 * The profile page: a short list of its parts on the left (with where to get help) and a card for each part on the
 * right — personal details and photo, the look, password, two-factor sign-in and passkeys. Saved through the domain
 * service so changes are audited.
 */
class EditProfile extends BaseEditProfile
{
    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false); // labels above the fields, inside each card
    }

    public function getSubheading(): ?string
    {
        return 'Your details, how the back office looks for you, and how you sign in.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                View::make('filament.profile.nav')->columnSpan(['lg' => 3]),
                Group::make([
                    $this->getFormContentComponent(),
                    ...array_filter([$this->getMultiFactorAuthenticationContentComponent()]),
                    Livewire::make(PasskeysComponent::class)->key('passkeys'),
                ])->columnSpan(['lg' => 9])->extraAttributes(['class' => 'zx-profile-main']),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Personal information')->description('Your photo, name and e-mail address, as colleagues see them.')
                ->icon('heroicon-o-user')->extraAttributes(['data-zx-part' => 'zx-you'])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        FileUpload::make('photo')->hiddenLabel()->avatar()->image()
                            ->imageEditor()->circleCropper()->imageEditorAspectRatioOptions(['1:1'])
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                            ->disk('local')->directory(AccessService::PHOTO_UPLOADS)->visibility('private')
                            ->helperText('Optional. Kept on your company\'s server only.')
                            ->extraAttributes(['class' => 'zx-photo']),
                        Group::make([$this->getNameFormComponent(), $this->getEmailFormComponent()])->columnSpan(['md' => 3]),
                    ]),
                ]),
            Section::make('Look')->description('How the back office looks for you. Switch at any time; on a phone the side menu is always used.')
                ->icon('heroicon-o-paint-brush')->extraAttributes(['data-zx-part' => 'zx-look'])
                ->schema([
                    ViewField::make('ui_look')->hiddenLabel()->view('filament.profile.look-picker')
                        ->viewData(['company' => $this->companyLook()]),
                ]),
            Section::make('Preferences')->description('Your own defaults. They follow you to any computer you sign in to.')
                ->icon('heroicon-o-adjustments-horizontal')->extraAttributes(['data-zx-part' => 'zx-prefs'])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('ui_prefs.home')->label('Home page')->options(fn () => UserPrefs::homes(Erp::user()))->selectablePlaceholder(false)
                            ->helperText('Opened when you sign in and by the house icon. Favourites (the star) can be chosen too.'),
                        Select::make('ui_prefs.rows')->label('Rows a list shows at first')->options(array_combine(UserPrefs::ROWS, UserPrefs::ROWS))->selectablePlaceholder(false),
                        Select::make('ui_prefs.date')->label('How dates are written')->options(array_map(fn ($d) => $d[0], UserPrefs::DATES))->selectablePlaceholder(false),
                        ToggleButtons::make('ui_prefs.theme')->label('Light or dark')->options(UserPrefs::THEMES)->inline()
                            ->helperText('Set on each computer you sign in to; the switcher in your menu still changes it for that computer.'),
                        ToggleButtons::make('ui_prefs.density')->label('Lists')->options(UserPrefs::DENSITIES)->inline(),
                    ]),
                ]),
            Section::make('Notifications')->description('What reaches you, and how. The company decides which channels are used at all (System → Settings); here you can turn off what you do not need. Security notices always reach you.')
                ->icon('heroicon-o-bell')->extraAttributes(['data-zx-part' => 'zx-notify'])
                ->schema(collect(NotifyPrefs::TOPICS)->map(fn ($t, $topic) => CheckboxList::make("notify_prefs.{$topic}")->label($t[0])->helperText($t[1])
                    ->options(array_intersect_key(NotifyPrefs::CHANNELS, array_flip($t[2])))->columns(4)->gridDirection('row'))->values()->all()),
            Section::make('Password')->description('Leave empty to keep your password. A long phrase is safer than a short word with symbols.')
                ->icon('heroicon-o-lock-closed')->extraAttributes(['data-zx-part' => 'zx-security'])
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Group::make([$this->getPasswordFormComponent()])->columnSpan(['md' => 2]),
                        View::make('filament.profile.password-strength'),
                    ]),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    /** Two-factor sign-in as a card like the others (Filament's own is a small untitled box). */
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if (! Filament::hasMultiFactorAuthentication()) {
            return null;
        }
        $user = Filament::auth()->user();

        return Section::make('Two-factor sign-in')->description('A code from an app on your phone, asked for at sign-in, so a stolen password alone is not enough.')
            ->icon('heroicon-o-shield-check')->extraAttributes(['data-zx-part' => 'zx-2fa'])
            ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                ->sort(fn (MultiFactorAuthenticationProvider $p): int => $p->isEnabled($user) ? 0 : 1)
                ->map(fn (MultiFactorAuthenticationProvider $p): Component => Group::make($p->getManagementSchemaComponents())->statePath($p->getId()))
                ->all());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = Erp::user();

        // Notifications as ticked channels per topic (nothing chosen yet = every channel ticked).
        $notify = collect(NotifyPrefs::TOPICS)->map(fn ($t, $topic) => array_values(array_filter($t[2], fn ($c) => NotifyPrefs::wants($user, $topic, $c))))->all();

        return ['photo' => $user->avatar_path, 'ui_look' => $user->ui_look ?? '', 'ui_prefs' => UserPrefs::for($user), 'notify_prefs' => $notify] + $data;
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (isset($data['notify_prefs'])) { // ticked channels per topic → on/off for each channel the topic uses
            $data['notify_prefs'] = collect(NotifyPrefs::TOPICS)->map(fn ($t, $topic) => collect($t[2])->mapWithKeys(fn ($c) => [$c => in_array($c, (array) ($data['notify_prefs'][$topic] ?? []), true)])->all())->all();
        }
        $saved = Erp::run(fn ($actor) => app(AccessService::class)->updateOwnProfile($actor, $record, $data));
        $this->redirect(static::getUrl()); // the new look and photo show at once

        return $saved;
    }

    private function companyLook(): string
    {
        $look = (string) app(Settings::class)->get('ui.look', Erp::user()->tenant_id);

        return isset(Look::LOOKS[$look]) ? $look : Look::DEFAULT;
    }
}

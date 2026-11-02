<?php

namespace App\Filament\Auth;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Support\Look;
use App\Support\Settings;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
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
                ->icon('heroicon-o-user')->extraAttributes(['id' => 'zx-you'])
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
                ->icon('heroicon-o-paint-brush')->extraAttributes(['id' => 'zx-look'])
                ->schema([
                    ViewField::make('ui_look')->hiddenLabel()->view('filament.profile.look-picker')
                        ->viewData(['company' => $this->companyLook()]),
                ]),
            Section::make('Password')->description('Leave empty to keep your password. A long phrase is safer than a short word with symbols.')
                ->icon('heroicon-o-lock-closed')->extraAttributes(['id' => 'zx-security'])
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
            ->icon('heroicon-o-shield-check')->extraAttributes(['id' => 'zx-2fa'])
            ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                ->sort(fn (MultiFactorAuthenticationProvider $p): int => $p->isEnabled($user) ? 0 : 1)
                ->map(fn (MultiFactorAuthenticationProvider $p): Component => Group::make($p->getManagementSchemaComponents())->statePath($p->getId()))
                ->all());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = Erp::user();

        return ['photo' => $user->avatar_path, 'ui_look' => $user->ui_look ?? ''] + $data;
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
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

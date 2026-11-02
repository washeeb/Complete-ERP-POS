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
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Filament's profile page, laid out in titled sections (what it is on the left, the fields on the right) like the rest
 * of the back office, with a photo and the choice of look; saved through the domain service so it is audited.
 */
class EditProfile extends BaseEditProfile
{
    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false); // labels above the fields, inside each section
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('You')->aside()
                ->description('Your photo, name and e-mail address, as colleagues see them. Changing the e-mail address asks for your current password.')
                ->schema([
                    FileUpload::make('photo')->label('Photo')->avatar()->image()
                        ->imageEditor()->circleCropper()->imageEditorAspectRatioOptions(['1:1'])
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                        ->disk('local')->directory(AccessService::PHOTO_UPLOADS)->visibility('private')
                        ->helperText('Optional. Kept on your company\'s server only.'),
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                ]),
            Section::make('Look')->aside()
                ->description('How the back office looks for you. You can switch at any time; on a phone the side menu is always used.')
                ->schema([
                    Radio::make('ui_look')->hiddenLabel()
                        ->options(['' => 'As the company chose: '.Look::LOOKS[$this->companyLook()]] + Look::LOOKS)
                        ->descriptions([
                            'top' => 'The menu in tabs across the top, each opening a panel of its pages, like a desktop program.',
                            'side' => 'A dark menu down the side that folds to icons, for more room.',
                            'classic' => 'The original layout.',
                        ]),
                ]),
            Section::make('Password')->aside()
                ->description('Leave empty to keep your password. A long phrase is safer than a short word with symbols.')
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    /** Two-factor sign-in in the same titled-section layout (Filament's own is a small untitled box). */
    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if (! Filament::hasMultiFactorAuthentication()) {
            return null;
        }
        $user = Filament::auth()->user();

        return Section::make('Two-factor sign-in')->aside()
            ->description('A code from an app on your phone, asked for at sign-in, so a stolen password alone is not enough.')
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

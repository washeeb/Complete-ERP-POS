<?php

namespace App\Filament\Auth;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Support\Look;
use App\Support\Settings;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/** Filament's profile page, with a photo and the choice of look, saving through the domain service so it is audited. */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('photo')->label('Photo')->avatar()->image()
                ->imageEditor()->circleCropper()->imageEditorAspectRatioOptions(['1:1'])
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                ->disk('local')->directory(AccessService::PHOTO_UPLOADS)->visibility('private')
                ->helperText('Optional. Shown beside your name to colleagues. Kept on your company\'s server only.'),
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Radio::make('ui_look')->label('How the back office looks for you')
                ->options(['' => 'As the company chose: '.Look::LOOKS[$this->companyLook()]] + Look::LOOKS)
                ->helperText('The new look puts the menu in tabs across the top, like a desktop program. Try it; you can switch back at any time. On a phone the side menu is always used.'),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
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

        return isset(Look::LOOKS[$look]) ? $look : 'classic';
    }
}

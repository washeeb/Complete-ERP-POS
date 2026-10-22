<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/**
 * Back-office sign-in with the e-mail address or the username (owner request 2026-10-22), the same username as on the
 * till. A username used in two companies on one server is ambiguous, so those people sign in with their e-mail.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('E-mail or username')->required()->maxLength(255)
            ->autocomplete('username')->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);
        if (! str_contains($login, '@')) {
            $matches = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($login)])->limit(2)->pluck('email');
            $login = $matches->count() === 1 ? $matches->first() : $login;
        }

        return ['email' => mb_strtolower($login), 'password' => $data['password']];
    }
}

<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\UserPrefs;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** After signing in: the page the person was going to, else their own home page (Profile → Preferences), in the new back office. */
class HomeLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        return redirect()->intended(UserPrefs::officeHomeUrl($user instanceof User ? $user : null)); // the new back office
    }
}

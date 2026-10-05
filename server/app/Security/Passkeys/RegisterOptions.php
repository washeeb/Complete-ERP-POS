<?php

namespace App\Security\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * A passkey counts as the second factor for L3+ people, so the authenticator must verify the
 * person (device PIN, fingerprint, face) — "preferred" would let a bare security key through.
 */
class RegisterOptions extends GeneratePasskeyRegisterOptionsAction
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }

    protected function challenge(): string
    {
        return random_bytes(32);
    }
}

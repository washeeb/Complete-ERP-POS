<?php

namespace App\Security\Passkeys;

use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialRequestOptions;

/** Sign-in challenge: 32 random bytes, and the authenticator must verify the person. */
class AuthenticationOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    public function execute(): string
    {
        $options = new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        $json = Serializer::make()->toJson($options);
        Session::put('passkey-authentication-options', $json);

        return $json;
    }
}

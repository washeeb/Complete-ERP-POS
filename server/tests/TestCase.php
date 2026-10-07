<?php

namespace Tests;

use App\Domain\Licensing\LicenseManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** One development signing key for the whole test run; its public half is trusted (tests are a development build). */
    public static ?array $licenseKeys = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$licenseKeys === null) {
            $pair = sodium_crypto_sign_keypair();
            self::$licenseKeys = ['kid' => 'dev', 'secret' => sodium_crypto_sign_secretkey($pair), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
        }
        config(['license.dev_public_key' => self::$licenseKeys['public'], 'license.public_keys' => []]);
        LicenseManager::forget();
    }
}

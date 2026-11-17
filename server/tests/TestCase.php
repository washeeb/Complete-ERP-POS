<?php

namespace Tests;

use App\Domain\Access\AccessService;
use App\Domain\Imports\ImportService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\DocumentNumbers;
use App\Security\Reconfirm;
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
        AccessService::$limitCompanyWide = false;
        Reconfirm::$assumeConfirmed = true; // "confirm it's you" before payments: ReconfirmTest checks it
        ImportService::$backgroundFrom = 500;
        DocumentNumbers::$outerTransactions = 1; // RefreshDatabase wraps each test: a number taken outside the document's own transaction must still fail
    }

    /**
     * Long exports and bulk changes call set_time_limit() so a big job is not cut short on a live server. The whole test run
     * is one PHP process, and on Windows the limit counts clock time: a limit set by one test would stop every test after
     * it once that time had passed. Each test's limit ends with the test.
     */
    protected function tearDown(): void
    {
        set_time_limit(0);
        parent::tearDown();
    }
}

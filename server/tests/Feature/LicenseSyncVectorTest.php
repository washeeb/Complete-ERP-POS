<?php

use App\Domain\Licensing\LicenseKey;
use App\Domain\Licensing\Signer;
use App\Http\Middleware\VerifySyncSignature;

/*
 * The desktop app (SES-Licensing, Rust) and this server must agree byte for byte. These pin both directions with a
 * throwaway key: the seed is public on purpose and no installation trusts it. The same vectors are in the app's tests.
 */
const SES_VECTOR_SEED = 'a7b1c2d3e4f5061728394a5b6c7d8e9fa7b1c2d3e4f5061728394a5b6c7d8e9f';

function sesVectorPublic(): string
{
    return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hex2bin(SES_VECTOR_SEED)));
}

it('verifies a key made by the desktop app', function () {
    $key = trim((string) file_get_contents(__DIR__.'/fixtures/ses-licensing-vector.txt'));
    $k = LicenseKey::decode($key);
    expect(Signer::verify($k['payload'], $k['signature'], sesVectorPublic()))->toBeTrue()
        ->and($k['payload']['customer'])->toBe('Acme Stores / Ltée ₵')
        ->and($k['payload']['limits'])->toBe(['branches' => 1, 'devices' => null, 'users' => 5])
        ->and($k['payload']['modules'])->toBe(['pharmacy'])->and($k['payload']['features'])->toBe([]);
});

it('signs the same message the desktop app signs', function () {
    expect(VerifySyncSignature::message('POST', 'api/license/sync/pull', '1700000000', 'abc', '{}'))
        ->toBe("ZX-SYNC-1\nPOST\n/api/license/sync/pull\n1700000000\nabc\n".hash('sha256', '{}'))
        ->and(hash('sha256', '{}'))->toBe('44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a');
});

<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Security\SealedSecret;

/**
 * The one sealing scheme, and that the stores moved onto it still open what
 * they sealed before: a destination's ciphertext is the scheme's with the
 * destination's associated data, byte for byte in format.
 */
final class SealedSecretTest extends TestCase
{
    public function testASealedValueOpensOnlyUnderItsKeyAndAssociatedData(): void
    {
        $key = random_bytes(32);
        $sealed = SealedSecret::seal('the secret', $key, 'aad-1', 'failed');

        self::assertStringStartsWith('v1.', $sealed);
        self::assertSame('the secret', SealedSecret::open($sealed, $key, 'aad-1', 'not v1', 'does not open'));
        self::assertNotSame($sealed, SealedSecret::seal('the secret', $key, 'aad-1', 'failed'), 'a fresh nonce every time');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusals(): iterable
    {
        yield 'another associated data' => ['aad-2', 'x', 'does not open'];
        yield 'another key' => ['aad-1', 'key', 'does not open'];
        yield 'not v1' => ['aad-1', 'v2', 'not v1'];
        yield 'truncated' => ['aad-1', 'short', 'not v1'];
    }

    /** @dataProvider refusals */
    public function testWhatDoesNotOpenIsRefusedWithItsOwnWords(string $aad, string $change, string $said): void
    {
        $key = random_bytes(32);
        $sealed = SealedSecret::seal('the secret', $key, 'aad-1', 'failed');
        $sealed = match ($change) {
            'v2' => 'v2.' . substr($sealed, 3),
            'short' => 'v1.AAAA',
            default => $sealed,
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($said);
        SealedSecret::open($sealed, $change === 'key' ? random_bytes(32) : $key, $aad, 'not v1', 'does not open');
    }

    public function testADestinationsCredentialsSealedAsBeforeStillOpen(): void
    {
        $key = random_bytes(32);
        // The ciphertext format and associated data DestinationStore wrote before the scheme was shared.
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt('{"api_secret":"x"}', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'p202-destination-credential-v1|7|3', 16);
        $before = 'v1.' . base64_encode($nonce . $tag . (string) $ciphertext);

        self::assertSame('{"api_secret":"x"}', DestinationStore::open($before, $key, 7, 3));
        self::assertSame('{"api_secret":"x"}', SealedSecret::open(DestinationStore::seal('{"api_secret":"x"}', $key, 7, 3), $key, 'p202-destination-credential-v1|7|3', 'a', 'b'));
    }
}

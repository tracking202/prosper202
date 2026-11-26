<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Security\MalformedSecretKey;
use Prosper202\Security\SecretsKeyring;

/**
 * Where the integration secrets key comes from, without a database: an
 * external key is used whole, named by an id that does not reveal it, and a
 * configured one that cannot be read is an error naming where it was set,
 * never the value and never a step down to the database's key
 * (IntegrationSecretStoreIntegrationTest runs the database half).
 */
final class SecretsKeyringTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @param array<string, mixed> $constants
     */
    private static function keyring(array $env, array $constants = []): SecretsKeyring
    {
        $unconnected = mysqli_init();
        self::assertInstanceOf(\mysqli::class, $unconnected);

        return new SecretsKeyring(
            new Connection($unconnected),
            static fn (string $name): string|false => $env[$name] ?? false,
            static fn (string $name): mixed => $constants[$name] ?? null
        );
    }

    public function testAnEnvironmentKeySealsAndOpensWhatItSealed(): void
    {
        $hex = bin2hex(random_bytes(32));
        $keyring = self::keyring([SecretsKeyring::ENV_KEY => " $hex\n"]);

        [$id, $key] = $keyring->sealingKey();
        self::assertSame(hex2bin($hex), $key);
        self::assertMatchesRegularExpression('/\Aexternal:[0-9a-f]{16}\z/', $id);
        self::assertStringNotContainsString(substr($hex, 0, 16), $id, 'the id is not the key');
        self::assertSame($key, $keyring->keyFor($id));
        self::assertSame(SecretsKeyring::ENV_KEY, $keyring->source());

        try {
            $keyring->keyFor(SecretsKeyring::keyId('external', random_bytes(32)));
            self::fail('opened what another external key sealed');
        } catch (\Prosper202\Security\SecretKeyUnavailable $e) {
            self::assertStringContainsString('and the key P202_SECRETS_KEY gives is ' . $id, $e->getMessage());
        }
    }

    public function testAKeyFileAndTheConstantNameTheSameKey(): void
    {
        $hex = bin2hex(random_bytes(32));
        $file = (string) tempnam(sys_get_temp_dir(), 'p202key');
        file_put_contents($file, $hex . "\n");
        try {
            $fromEnv = self::keyring([SecretsKeyring::ENV_FILE => $file]);
            $fromConstant = self::keyring([], [SecretsKeyring::ENV_FILE => $file]);
            self::assertSame($fromEnv->sealingKey(), $fromConstant->sealingKey());
            self::assertSame('the P202_SECRETS_KEY_FILE constant in 202-config.php', $fromConstant->source());
        } finally {
            unlink($file);
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, mixed>, string}> */
    public static function unusable(): iterable
    {
        $hex = str_repeat('0f', 32);
        yield 'one character short' => [[SecretsKeyring::ENV_KEY => substr($hex, 1)], [], 'P202_SECRETS_KEY is set but is not 64'];
        yield 'base64, not hex' => [[SecretsKeyring::ENV_KEY => base64_encode(random_bytes(32))], [], 'is not 64 hexadecimal'];
        yield 'set and empty' => [[SecretsKeyring::ENV_KEY => ''], [], 'is not 64 hexadecimal'];
        yield 'a relative path' => [[SecretsKeyring::ENV_FILE => 'secrets.key'], [], 'is not an absolute path'];
        yield 'a directory' => [[SecretsKeyring::ENV_FILE => sys_get_temp_dir()], [], 'which is not a file this process can read'];
        yield 'a constant that is not a string' => [[], [SecretsKeyring::ENV_FILE => true], 'is not an absolute path'];
        yield 'two at once' => [[SecretsKeyring::ENV_KEY => $hex], [SecretsKeyring::ENV_FILE => '/k'], 'More than one'];
    }

    /**
     * @dataProvider unusable
     * @param array<string, string> $env
     * @param array<string, mixed> $constants
     */
    public function testAConfiguredKeyThatCannotBeReadIsRefusedEverywhere(array $env, array $constants, string $why): void
    {
        $keyring = self::keyring($env, $constants);
        foreach ([
            'seal' => static fn () => $keyring->sealingKey(),
            'open a database-sealed row' => static fn () => $keyring->keyFor('database:0123456789abcdef'),
            'open an external-sealed row' => static fn () => $keyring->keyFor('external:0123456789abcdef'),
        ] as $what => $call) {
            try {
                $call();
                self::fail($what . ': went on past a key it could not read');
            } catch (MalformedSecretKey $e) {
                self::assertStringContainsString($why, $e->getMessage(), $what);
                foreach ($env as $value) {
                    if (strlen($value) >= 32) {
                        self::assertStringNotContainsString($value, $e->getMessage(), 'the value is never in the error');
                    }
                }
            }
        }
    }

    public function testARowSealedWithAnExternalKeyIsAnErrorWithoutOne(): void
    {
        $this->expectExceptionMessage('it is sealed with the external key external:0123456789abcdef, and neither P202_SECRETS_KEY nor');
        self::keyring([])->keyFor('external:0123456789abcdef');
    }

    public function testAKeyIdOfNoKnownKindIsAnError(): void
    {
        $this->expectExceptionMessage('which is not an external or a database key');
        self::keyring([SecretsKeyring::ENV_KEY => str_repeat('a', 64)])->keyFor('plaintext:');
    }
}

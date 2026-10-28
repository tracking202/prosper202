<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\Integrity\GooglePlayIntegrityClient;
use Api\V3\Apps\Android\Integrity\IntegrityCredentialStore;
use Api\V3\Apps\Android\Integrity\ServiceAccountCredential;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The service-account key file as the API accepts it, and its encryption
 * at rest: what is refused (and that no refusal echoes the key), that the
 * pinned token endpoint cannot be redirected by the file, and that a
 * ciphertext opens only for the registration and owner it was sealed for.
 */
final class ServiceAccountCredentialTest extends TestCase
{
    private static string $pem = '';

    public static function setUpBeforeClass(): void
    {
        self::$pem = FakeGoogle::rsaKey()[0];
    }

    /** @return array<string, mixed> */
    private static function keyFile(): array
    {
        return [
            'type' => 'service_account',
            'project_id' => 'p202-test-project',
            'private_key_id' => '0123456789abcdef0123456789abcdef01234567',
            'private_key' => self::$pem,
            'client_email' => 'integrity@p202-test-project.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => GooglePlayIntegrityClient::TOKEN_URL,
        ];
    }

    public function testAKeyFileIsReducedToWhatSigningNeeds(): void
    {
        $c = ServiceAccountCredential::fromKeyFile(self::keyFile());
        self::assertSame(['client_email' => 'integrity@p202-test-project.iam.gserviceaccount.com',
            'private_key_id' => '0123456789abcdef0123456789abcdef01234567', 'project_id' => 'p202-test-project'], $c->summary());
        $stored = json_decode($c->toStoredJson(), true);
        self::assertSame(['client_email', 'private_key_id', 'private_key', 'project_id'], array_keys($stored), 'client_id, auth_uri and the rest are not kept');
        self::assertStringNotContainsString('PRIVATE KEY', print_r($c, true), 'a dump of the object redacts the key');
        self::assertSame($c->summary(), ServiceAccountCredential::fromStoredJson($c->toStoredJson())->summary());
    }

    /** @dataProvider badKeyFiles */
    public function testABadKeyFileIsRefusedByFieldWithoutEchoingTheKey(mixed $file, string $field): void
    {
        $file = $file instanceof \Closure ? $file() : $file;
        try {
            ServiceAccountCredential::fromKeyFile($file);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($e->getFieldErrors()));
            self::assertStringNotContainsString('PRIVATE KEY', json_encode($e->getFieldErrors()) . $e->getMessage());
        }
    }

    /** @return array<string, array{mixed, string}> */
    public static function badKeyFiles(): array
    {
        $with = static fn (array $change): \Closure => static fn (): array => $change + self::keyFile();
        $without = static fn (string $key): \Closure => static function () use ($key): array {
            $f = self::keyFile();
            unset($f[$key]);

            return $f;
        };

        return [
            'not an object' => ['{"type": "service_account"}', 'credential'],
            'a list' => [[1, 2], 'credential'],
            'another type' => [$with(['type' => 'authorized_user']), 'credential.type'],
            'no email' => [$without('client_email'), 'credential.client_email'],
            'no key id' => [$without('private_key_id'), 'credential.private_key_id'],
            'no key' => [$without('private_key'), 'credential.private_key'],
            'a public key' => [$with(['private_key' => FakeGoogle::rsaKey()[1]]), 'credential.private_key'],
            'garbage key' => [$with(['private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n"]), 'credential.private_key'],
            'a token_uri elsewhere' => [$with(['token_uri' => 'https://evil.example/token']), 'credential.token_uri'],
            'a bad project id' => [$with(['project_id' => 'Not A Project']), 'credential.project_id'],
        ];
    }

    public function testAnEcKeyIsRefused(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $pem);
        $this->expectException(ValidationException::class);
        ServiceAccountCredential::fromKeyFile(['private_key' => $pem] + self::keyFile());
    }

    public function testTheCiphertextOpensOnlyWhereItWasSealed(): void
    {
        $key = random_bytes(32);
        $plain = ServiceAccountCredential::fromKeyFile(self::keyFile())->toStoredJson();
        $sealed = IntegrityCredentialStore::seal($plain, $key, 5, 1);
        self::assertStringStartsWith('v1.', $sealed);
        self::assertStringNotContainsString('PRIVATE', $sealed);
        self::assertStringNotContainsString('integrity@', $sealed);
        self::assertSame($plain, IntegrityCredentialStore::open($sealed, $key, 5, 1));
        self::assertNotSame($sealed, IntegrityCredentialStore::seal($plain, $key, 5, 1), 'a fresh nonce every time');

        foreach ([
            'another registration' => fn () => IntegrityCredentialStore::open($sealed, $key, 6, 1),
            'another owner' => fn () => IntegrityCredentialStore::open($sealed, $key, 5, 2),
            'another key' => fn () => IntegrityCredentialStore::open($sealed, random_bytes(32), 5, 1),
            'a flipped byte' => function () use ($sealed, $key) {
                $raw = base64_decode(substr($sealed, 3));
                $raw[40] = chr(ord($raw[40]) ^ 1);

                return IntegrityCredentialStore::open('v1.' . base64_encode($raw), $key, 5, 1);
            },
            'not v1' => fn () => IntegrityCredentialStore::open('v2.' . substr($sealed, 3), $key, 5, 1),
            'truncated' => fn () => IntegrityCredentialStore::open('v1.AAAA', $key, 5, 1),
        ] as $name => $open) {
            try {
                $open();
                self::fail($name . ': opened');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('PRIVATE', $e->getMessage(), $name);
            }
        }
    }
}

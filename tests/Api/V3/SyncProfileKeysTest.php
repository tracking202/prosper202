<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\SyncException;
use Api\V3\Support\SyncProfileKeys;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * A queued sync job's profile keys, sealed: never in the stored profile as
 * sent, opened only for the side and url they were sealed for, read as
 * written from a job queued before they were sealed, and a sealed key that
 * does not open is a reason to queue again, not a key.
 */
final class SyncProfileKeysTest extends TestCase
{
    private const KEY = '9f2c0d7e4a1b3c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5';
    private const PROFILE = ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => self::KEY];

    private static function keys(string $fill = 'k'): SyncProfileKeys
    {
        return SyncProfileKeys::withKey(str_repeat($fill, 32));
    }

    private static function unreadable(callable $open): SyncException
    {
        try {
            $open();
        } catch (SyncException $e) {
            self::assertSame(SyncException::KEY_UNREADABLE, $e->errorCode);
            self::assertFalse($e->retryable);
            self::assertStringContainsString('Queue the sync again', $e->getMessage());

            return $e;
        }
        self::fail('the key opened');
    }

    public function testASealedKeyIsNotInTheStoredProfileAndOpensForItsSideAndUrl(): void
    {
        $sealed = self::keys()->seal(self::PROFILE, 'target');

        self::assertArrayNotHasKey('api_key', $sealed);
        $rest = array_diff_key($sealed, [SyncProfileKeys::SEALED => 1]);
        self::assertSame(['name' => 'stage', 'url' => 'https://stage.example.com'], $rest);
        self::assertStringStartsWith('v1.', $sealed[SyncProfileKeys::SEALED]);
        $stored = (string) json_encode($sealed);
        self::assertStringNotContainsString(self::KEY, $stored);
        self::assertStringNotContainsString(substr(self::KEY, 0, 16), $stored);
        self::assertNotSame($sealed, self::keys()->seal(self::PROFILE, 'target'), 'a fresh nonce each time');

        self::assertSame(self::PROFILE, self::keys()->open($sealed, 'target'));
    }

    public function testASealedKeyOpensNowhereElse(): void
    {
        $sealed = self::keys()->seal(self::PROFILE, 'target');

        self::unreadable(static fn () => self::keys()->open($sealed, 'source'));
        $moved = ['url' => 'https://collector.example.net'] + $sealed;
        $e = self::unreadable(static fn () => self::keys()->open($moved, 'target'));
        self::assertStringContainsString('does not open for its url', $e->getMessage());
        self::unreadable(static fn () => self::keys('z')->open($sealed, 'target'));
        foreach (['v1.!!', self::KEY] as $notSealed) {
            $stored = [SyncProfileKeys::SEALED => $notSealed] + self::PROFILE;
            self::unreadable(static fn () => self::keys()->open($stored, 'target'));
        }
        $none = ['name' => 'stage', 'url' => 'https://stage.example.com'];
        $e = self::unreadable(static fn () => self::keys()->open($none, 'target'));
        self::assertStringContainsString('holds no API key', $e->getMessage());
    }

    public function testAJobQueuedBeforeKeysWereSealedIsReadAsWritten(): void
    {
        self::assertTrue(SyncProfileKeys::holdsPlainKey(self::PROFILE));
        self::assertSame(self::PROFILE, self::keys()->open(self::PROFILE, 'source'));
        self::assertFalse(SyncProfileKeys::holdsPlainKey(self::keys()->seal(self::PROFILE, 'source')));
    }

    public function testAFinishedJobKeepsNoKey(): void
    {
        $profile = ['name' => 'stage', 'url' => 'https://stage.example.com'];
        self::assertSame($profile, SyncProfileKeys::forget(self::PROFILE));
        self::assertSame($profile, SyncProfileKeys::forget(self::keys()->seal(self::PROFILE, 'source')));
        self::assertSame($profile, self::keys()->seal($profile, 'source'), 'no key, nothing sealed');
    }

    /** The install's key is the database's: minted once, read back, never replaced. */
    public function testTheKeyIsTheDatabases(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('SELECT secret_value', [['secret_value' => str_repeat('ab', 32)]]);
        $sealed = SyncProfileKeys::fromDatabase($db)->seal(self::PROFILE, 'source');
        $inserts = static fn (FakeMysqliConnection $db): array => array_values(array_filter(
            $db->preparedSql,
            static fn (string $sql): bool => str_contains($sql, 'INSERT INTO')
        ));
        $insert = $inserts($db);
        self::assertCount(1, $insert);
        self::assertStringContainsString('202_deployment_secrets', $insert[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE secret_name = secret_name', $insert[0]);
        $same = SyncProfileKeys::withKey((string) hex2bin(str_repeat('ab', 32)));
        self::assertSame(self::PROFILE, $same->open($sealed, 'source'));

        $none = new FakeMysqliConnection();
        $e = self::unreadable(static fn () => SyncProfileKeys::fromDatabase($none)->open($sealed, 'source'));
        self::assertStringContainsString('missing from 202_deployment_secrets', $e->getMessage());
        self::assertSame([], $inserts($none), 'opening mints nothing');

        $corrupt = new FakeMysqliConnection();
        $corrupt->whenQueryContainsReturnRows('SELECT secret_value', [['secret_value' => 'not-hex']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not 64 lower-case hex characters');
        SyncProfileKeys::fromDatabase($corrupt)->seal(self::PROFILE, 'source');
    }
}

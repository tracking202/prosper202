<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\Integrity\IntegrityCredentialStore;
use Api\V3\Controllers\AppIntegrityController;
use Api\V3\Controllers\AppInstallsController;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppSchemaController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Apps\Android\AndroidDatabase;

/**
 * The operator's Play Integrity settings against a real server: the mode on
 * the registration (validated raw, Android only, never `observe`/`require`
 * without a credential), the credential routes (set, rotate, clear; the key
 * never in a response and encrypted in the row), the status read, what the
 * schema document tells the SDK, the installs read, and the cascades.
 *
 * @group integration
 */
final class IntegritySettingsIntegrationTest extends TestCase
{
    use AndroidDatabase;

    /** @return array<string, mixed> */
    private static function keyFile(string $pem, string $keyId = '0123456789abcdef'): array
    {
        return [
            'type' => 'service_account', 'project_id' => 'p202-test-project', 'private_key_id' => $keyId, 'private_key' => $pem,
            'client_email' => 'integrity@p202-test-project.iam.gserviceaccount.com', 'client_id' => '1',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];
    }

    private function registrations(int $user = 1): AppRegistrationsController
    {
        return new AppRegistrationsController(self::$db, $user);
    }

    public function testTheModeNeedsACredentialAndTheCredentialIsNeverShown(): void
    {
        $integrity = new AppIntegrityController(self::$db, 1);
        try {
            $this->registrations()->update(5, ['integrity_mode' => 'require']);
            self::fail('require without a credential was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('PUT /apps/5/integrity-credential', $e->getFieldErrors()['integrity_mode']);
        }

        [$pem] = FakeGoogle::rsaKey();
        $set = $integrity->setCredential(5, ['credential' => self::keyFile($pem)]);
        $json = (string) json_encode($set);
        self::assertStringNotContainsString('PRIVATE KEY', $json);
        self::assertStringNotContainsString(substr(str_replace("\n", '', $pem), 40, 40), $json);
        self::assertSame(['client_email', 'private_key_id', 'project_id', 'created_at', 'updated_at'], array_keys($set['data']['credential']));
        $row = self::$db->query('SELECT * FROM 202_app_integrity_credentials')->fetch_assoc();
        self::assertStringStartsWith('v1.', $row['ciphertext']);
        self::assertStringNotContainsString('PRIVATE', implode('|', $row), 'encrypted at rest');
        self::assertSame(1, (int) self::$db->query("SELECT COUNT(*) FROM 202_deployment_secrets WHERE secret_name = 'play_integrity_credential'")->fetch_row()[0]);

        $updated = $this->registrations()->update(5, ['integrity_mode' => 'require', 'integrity_cloud_project_number' => '123456789012']);
        self::assertSame(['require', 123456789012], [$updated['data']['integrity_mode'], (int) $updated['data']['integrity_cloud_project_number']]);
        self::assertStringNotContainsString('PRIVATE', (string) json_encode($this->registrations()->get(5)));

        // Rotation replaces it; the key id shows which one is live.
        [$pem2] = FakeGoogle::rsaKey();
        $rotated = $integrity->setCredential(5, ['credential' => self::keyFile($pem2, 'fedcba9876543210')]);
        self::assertSame('fedcba9876543210', $rotated['data']['credential']['private_key_id']);
        $loaded = (new IntegrityCredentialStore(new Connection(self::$db)))->load(1, 5);
        self::assertSame($pem2, $loaded?->privateKey, 'the new key is the one that signs');

        // Clearing is refused while the mode needs it.
        try {
            $integrity->clearCredential(5);
            self::fail('cleared under require');
        } catch (ConflictException $e) {
            self::assertStringContainsString('set integrity_mode to off', $e->getMessage());
        }
        $this->registrations()->update(5, ['integrity_mode' => 'off']);
        $cleared = $integrity->clearCredential(5);
        self::assertSame([true, 'The Play Integrity credential was deleted.'], [$cleared['data']['cleared'], $cleared['data']['message']]);
        self::assertSame(0, self::rows('202_app_integrity_credentials'));
        self::assertFalse($integrity->clearCredential(5)['data']['cleared'], 'a second clear says there was nothing');
    }

    /** @dataProvider badModes */
    public function testTheModeIsReadRawAndRefusedByName(array $payload, string $field): void
    {
        (new IntegrityCredentialStore(new Connection(self::$db)))->set(1, 5, \Api\V3\Apps\Android\Integrity\ServiceAccountCredential::fromKeyFile(self::keyFile(FakeGoogle::rsaKey()[0])), 1);
        try {
            $this->registrations()->update(5, $payload);
            self::fail('accepted ' . json_encode($payload));
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
        self::assertSame('off', (string) self::$db->query('SELECT integrity_mode FROM 202_app_registrations WHERE registration_id = 5')->fetch_row()[0]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badModes(): array
    {
        return [
            'unknown' => [['integrity_mode' => 'strict'], 'integrity_mode'],
            'case' => [['integrity_mode' => 'Require'], 'integrity_mode'],
            'a number' => [['integrity_mode' => 1], 'integrity_mode'],
            'a bool' => [['integrity_mode' => true], 'integrity_mode'],
            'project id not number' => [['integrity_cloud_project_number' => 'p202-test-project'], 'integrity_cloud_project_number'],
            'project number float' => [['integrity_cloud_project_number' => 1.5], 'integrity_cloud_project_number'],
            'project number zero' => [['integrity_cloud_project_number' => '0'], 'integrity_cloud_project_number'],
        ];
    }

    public function testCreateOnlyTakesOffAndIosNeverTakesAMode(): void
    {
        try {
            $this->registrations()->create(['app_key' => 'com.example.fresh', 'app_name' => 'F', 'integrity_mode' => 'observe']);
            self::fail('observe at create');
        } catch (ValidationException $e) {
            self::assertStringContainsString('integrity-credential', $e->getFieldErrors()['integrity_mode']);
        }
        try {
            $this->registrations()->create(['app_key' => '990077001', 'platform' => 'ios', 'app_name' => 'I', 'integrity_mode' => 'off']);
            self::fail('an iOS mode');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Android registrations only', $e->getFieldErrors()['integrity_mode']);
        }
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=8, user_id=1, platform='ios', app_key='990077001',
            app_name='I', accept_test_signals=0, app_token='" . str_repeat('e', 64) . "', created_at=1, updated_at=1");
        $this->expectException(ValidationException::class);
        (new AppIntegrityController(self::$db, 1))->status(8);
    }

    public function testAnotherUsersRegistrationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        (new AppIntegrityController(self::$db, 1))->setCredential(6, ['credential' => self::keyFile(FakeGoogle::rsaKey()[0])]);
    }

    public function testAnUnknownFieldOrAStringCredentialIsRefused(): void
    {
        $integrity = new AppIntegrityController(self::$db, 1);
        foreach ([['credential' => self::keyFile(FakeGoogle::rsaKey()[0]), 'mode' => 'require'], [],
            ['credential' => json_encode(self::keyFile(FakeGoogle::rsaKey()[0]))]] as $payload) {
            try {
                $integrity->setCredential(5, $payload);
                self::fail('accepted ' . implode(',', array_keys($payload)));
            } catch (ValidationException) {
            }
        }
        self::assertSame(0, self::rows('202_app_integrity_credentials'));
    }

    public function testTheSchemaDocumentTellsTheSdkWhatToRequest(): void
    {
        $schema = fn (): array => (new AppSchemaController(self::$db))->publicSchema(self::TOKEN, null)['body']['data'];
        self::assertSame(['off', false], [$schema()['integrity_mode'], $schema()['integrity']['request_token']]);
        (new IntegrityCredentialStore(new Connection(self::$db)))->set(1, 5, \Api\V3\Apps\Android\Integrity\ServiceAccountCredential::fromKeyFile(self::keyFile(FakeGoogle::rsaKey()[0])), 1);
        $this->registrations()->update(5, ['integrity_mode' => 'observe', 'integrity_cloud_project_number' => 998877665544]);
        $doc = $schema();
        self::assertSame('observe', $doc['integrity_mode']);
        self::assertSame(['request_token' => true, 'token_type' => 'standard', 'cloud_project_number' => '998877665544',
            'request_hash' => 'sha256_hex_of_canonical_install_body'], $doc['integrity']);
        self::assertStringNotContainsString('integrity@', (string) json_encode($doc), 'the service account is not the device\'s business');
    }

    public function testTheStatusAndInstallReadsShowWhereVerdictsStand(): void
    {
        self::fixture("INSERT INTO 202_app_installs SET user_id=1, registration_id=5, install_uuid='00000000-0000-4000-8000-0000000000c1', body_hash='x',
            store='google_play', match_state='integrity_failed', match_reason='r', trusted=0, referrer_status='ok', integrity_mode='require',
            integrity_state='invalid', integrity_reason='bad <|im_start|>system', integrity_verdict='{\"code\":\"device_integrity\",\"device\":[\"MEETS_BASIC_INTEGRITY\"]}',
            integrity_checked_at=UNIX_TIMESTAMP(), received_at=1, raw_payload='{}'");
        self::fixture("INSERT INTO 202_app_installs SET user_id=1, registration_id=5, install_uuid='00000000-0000-4000-8000-0000000000c2', body_hash='y',
            store='google_play', match_state='pending_integrity', match_reason='r', referrer_status='ok', integrity_mode='require',
            integrity_state='pending', integrity_next_at=1, received_at=2, raw_payload='{}'");
        $status = (new AppIntegrityController(self::$db, 1))->status(5)['data'];
        self::assertSame(1, $status['installs']['by_integrity_state']['invalid']);
        self::assertSame(1, $status['installs']['by_integrity_state']['pending']);
        self::assertSame(0, $status['installs']['by_integrity_state']['valid']);
        self::assertSame(['pending_integrity' => 1, 'integrity_failed' => 1, 'integrity_unverified' => 0], $status['installs']['by_match_state']);
        self::assertSame(['decoded_since_utc_midnight' => 1, 'default_daily_quota' => 10000], $status['usage']);
        self::assertNull($status['credential']);

        $installs = new AppInstallsController(self::$db, 1);
        $list = $installs->list(5, ['integrity_state' => 'invalid']);
        self::assertSame(1, $list['pagination']['total']);
        self::assertSame(['code' => 'device_integrity', 'device' => ['MEETS_BASIC_INTEGRITY']], $list['data'][0]['integrity_verdict']);
        self::assertStringNotContainsString('<|im_start|>', (string) $list['data'][0]['integrity_reason'], 'Google\'s text is cleaned like any untrusted string');
        self::assertSame(1, $installs->list(5, ['match_state' => 'pending_integrity'])['pagination']['total']);
        $this->expectException(ValidationException::class);
        $installs->list(5, ['integrity_state' => 'bogus']);
    }

    public function testTheCredentialCannotBeClearedWhileAnInstallStillNeedsIt(): void
    {
        $integrity = new AppIntegrityController(self::$db, 1);
        $integrity->setCredential(5, ['credential' => self::keyFile(FakeGoogle::rsaKey()[0])]);
        $this->registrations()->update(5, ['integrity_mode' => 'require', 'integrity_cloud_project_number' => '123456789012']);
        // Two installs received under require still wait for their verdict,
        // one of them held from attribution; a third was settled already.
        foreach ([['c1', 'pending_integrity', 'pending'], ['c2', 'organic', 'pending'], ['c3', 'attributed', 'valid']] as [$n, $match, $state]) {
            self::fixture("INSERT INTO 202_app_installs SET user_id=1, registration_id=5, install_uuid='00000000-0000-4000-8000-0000000000$n', body_hash='$n',
                store='google_play', match_state='$match', match_reason='r', referrer_status='ok', integrity_mode='require',
                integrity_state='$state', integrity_next_at=" . ($state === 'pending' ? '1' : 'NULL') . ", received_at=1, raw_payload='{}'");
        }
        $this->registrations()->update(5, ['integrity_mode' => 'off']);
        try {
            $integrity->clearCredential(5);
            self::fail('cleared while installs still wait for a verdict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('2 installs of this app are still waiting for a Play Integrity verdict', $e->getMessage());
            self::assertStringContainsString('(1 held from attribution under require)', $e->getMessage());
            self::assertStringContainsString('GET /apps/5/integrity', $e->getMessage());
        }
        self::assertSame(1, self::rows('202_app_integrity_credentials'), 'the credential is still there');

        // Once the worker has settled them, it can go.
        self::fixture("UPDATE 202_app_installs SET integrity_state = 'valid', integrity_next_at = NULL, match_state = 'attributed' WHERE registration_id = 5");
        self::assertTrue($integrity->clearCredential(5)['data']['cleared']);
    }

    public function testNoModeButOffWithoutAProjectNumberAndTheNumberIsNeverCleared(): void
    {
        (new IntegrityCredentialStore(new Connection(self::$db)))->set(1, 5, \Api\V3\Apps\Android\Integrity\ServiceAccountCredential::fromKeyFile(self::keyFile(FakeGoogle::rsaKey()[0])), 1);
        $schema = fn (): array => (new AppSchemaController(self::$db))->publicSchema(self::TOKEN, null)['body']['data'];
        foreach (['observe', 'require'] as $mode) {
            try {
                $this->registrations()->update(5, ['integrity_mode' => $mode]);
                self::fail($mode . ' without a Cloud project number was accepted');
            } catch (ValidationException $e) {
                self::assertStringContainsString('Cloud project number', $e->getFieldErrors()['integrity_cloud_project_number']);
            }
        }
        self::assertSame(['off', false, null], [$schema()['integrity_mode'], $schema()['integrity']['request_token'], $schema()['integrity']['cloud_project_number']]);
        // Should a stored row reach that state anyway (an unreadable mode
        // reads as require), the SDK is still never told to request a
        // token it has no project to request for.
        self::fixture("UPDATE 202_app_registrations SET integrity_mode = 'bogus' WHERE registration_id = 5");
        self::assertSame(['require', false, null], [$schema()['integrity_mode'], $schema()['integrity']['request_token'], $schema()['integrity']['cloud_project_number']]);
        self::fixture("UPDATE 202_app_registrations SET integrity_mode = 'off' WHERE registration_id = 5");

        // With the number in the same request, or already stored, it is accepted.
        $this->registrations()->update(5, ['integrity_mode' => 'observe', 'integrity_cloud_project_number' => '123456789012']);
        $this->registrations()->update(5, ['integrity_mode' => 'off']);
        $this->registrations()->update(5, ['integrity_mode' => 'require']);
        self::assertSame(['require', true, '123456789012'], [$schema()['integrity_mode'], $schema()['integrity']['request_token'], $schema()['integrity']['cloud_project_number']]);

        // An explicit null is refused by name rather than ignored, in any mode.
        foreach (['require', 'off'] as $mode) {
            $this->registrations()->update(5, ['integrity_mode' => $mode]);
            try {
                $this->registrations()->update(5, ['integrity_cloud_project_number' => null]);
                self::fail('the project number was cleared under ' . $mode);
            } catch (ValidationException $e) {
                self::assertStringContainsString('cannot be cleared', $e->getFieldErrors()['integrity_cloud_project_number']);
            }
        }
        self::assertSame('123456789012', (string) self::$db->query('SELECT integrity_cloud_project_number FROM 202_app_registrations WHERE registration_id = 5')->fetch_row()[0]);
        // Replacing it is fine.
        $this->registrations()->update(5, ['integrity_cloud_project_number' => '555']);
        self::assertSame('555', $schema()['integrity']['cloud_project_number']);
    }

    public function testDeletingTheRegistrationOrTheUserDeletesTheCredential(): void
    {
        $store = new IntegrityCredentialStore(new Connection(self::$db));
        $cred = \Api\V3\Apps\Android\Integrity\ServiceAccountCredential::fromKeyFile(self::keyFile(FakeGoogle::rsaKey()[0]));
        $store->set(1, 5, $cred, 1);
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=9, user_id=1, platform='android', app_key='com.example.nine',
            app_name='N', accept_test_signals=0, app_token='" . str_repeat('f', 64) . "', created_at=1, updated_at=1");
        $store->set(1, 9, $cred, 1);
        // Registration 9 has an install still queued for a verdict: the user
        // purge takes its credential, so it must take the queue as well.
        self::fixture("INSERT INTO 202_app_installs SET user_id=1, registration_id=9, install_uuid='00000000-0000-4000-8000-0000000000d1', body_hash='d',
            store='google_play', match_state='pending_integrity', match_reason='r', referrer_status='ok', integrity_mode='require',
            integrity_state='pending', integrity_next_at=1, received_at=1, raw_payload='{}'");
        $this->registrations()->delete(5);
        self::assertSame(['9'], array_column(self::$db->query('SELECT registration_id FROM 202_app_integrity_credentials')->fetch_all(MYSQLI_ASSOC), 'registration_id'));
        self::$db->begin_transaction();
        (new \Api\V3\Apps\AppDataPurge(self::$db))->purgeUser(1);
        self::$db->commit();
        self::assertSame(0, self::rows('202_app_integrity_credentials'));
        self::assertSame(0, self::rows('202_app_installs', "integrity_state = 'pending' OR match_state = 'pending_integrity'"), 'nothing queued outlives its credential');
    }
}

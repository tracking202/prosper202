<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\InstallPayload;
use Api\V3\Apps\Android\Integrity\DecodeResult;
use Api\V3\Apps\Android\Integrity\IntegrityCredentialStore;
use Api\V3\Apps\Android\Integrity\IntegrityVerifier;
use Api\V3\Apps\Android\Integrity\PlayIntegrityClient;
use Api\V3\Apps\Android\Integrity\ServiceAccountCredential;
use Api\V3\Apps\Android\PendingClickSettler;
use Api\V3\Controllers\AppRegistrationsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Apps\Android\AndroidDatabase;

/**
 * Play Integrity against a real server (plan §5.6, §5.11): the three modes
 * through the intake, the verdict worker and the pending-click settler,
 * with the install's match state, trust, ledger row, MTA outbox row,
 * traffic-source notification and goal subject read back after each step.
 *
 * Google is a double here (the client's own wire behaviour is
 * GooglePlayIntegrityClientTest's, against a TLS fake): what this proves is
 * what the server does with each answer.
 *
 * @group integration
 */
final class IntegrityIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-0000000000a1';
    private const U2 = '00000000-0000-4000-8000-0000000000a2';
    private const U3 = '00000000-0000-4000-8000-0000000000a3';

    private static ?ServiceAccountCredential $credential = null;
    /** @var array<string, list<DecodeResult|\Closure>> */
    private array $answers = [];
    /** @var list<string> */
    private array $decoded = [];

    private function mode(string $mode, bool $credential = true): void
    {
        self::fixture("UPDATE 202_app_registrations SET integrity_mode = '$mode' WHERE registration_id = 5");
        if ($credential) {
            self::$credential ??= ServiceAccountCredential::fromKeyFile([
                'type' => 'service_account', 'private_key_id' => 'abcdef0123456789', 'private_key' => FakeGoogle::rsaKey()[0],
                'client_email' => 'integrity@p202-test-project.iam.gserviceaccount.com', 'project_id' => 'p202-test-project',
            ]);
            (new IntegrityCredentialStore(new Connection(self::$db)))->set(1, 5, self::$credential, 1);
        }
    }

    private function verifier(): IntegrityVerifier
    {
        $client = new class ($this) implements PlayIntegrityClient {
            public function __construct(private readonly IntegrityIntegrationTest $test)
            {
            }

            public function decode(ServiceAccountCredential $credential, string $packageName, string $integrityToken): DecodeResult
            {
                return $this->test->answer($packageName, $integrityToken);
            }
        };

        return new IntegrityVerifier(self::$db, $client, fn (): int => $this->clock);
    }

    /** @internal the double's answer, in order per token; the last repeats */
    public function answer(string $package, string $token): DecodeResult
    {
        $this->decoded[] = $package . ' ' . $token;
        $queue = $this->answers[$token] ?? [];
        self::assertNotSame([], $queue, 'no answer planned for ' . $token);
        $next = count($queue) > 1 ? array_shift($this->answers[$token]) : $queue[0];

        return $next instanceof \Closure ? $next() : $next;
    }

    /**
     * A verdict Google would give for this body, with one field changed.
     *
     * @param array<string, mixed> $body
     * @param array<string, array<string, mixed>> $override
     */
    private function verdict(array $body, array $override = [], ?int $issuedAt = null): DecodeResult
    {
        $payload = [
            'requestDetails' => ['requestPackageName' => 'com.example.summit',
                'requestHash' => InstallPayload::fromDecoded(json_decode((string) json_encode($body), true))->fingerprint(),
                'timestampMillis' => (string) (($issuedAt ?? $this->clock) * 1000)],
            'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => 'com.example.summit', 'versionCode' => '42'],
            'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
            'accountDetails' => ['appLicensingVerdict' => 'LICENSED'],
        ];
        foreach ($override as $section => $fields) {
            $payload[$section] = $fields + $payload[$section];
        }

        return DecodeResult::decoded($payload);
    }

    /** @return array<string, mixed> */
    private function tokenBody(string $uuid, int $click, string $token, array $override = []): array
    {
        return self::body($uuid, 'p202=' . self::tokenFor($click), ['integrity_token' => $token] + $override);
    }

    private function run1(): array
    {
        $this->clock += 1;

        return $this->verifier()->run();
    }

    public function testOffKeepsATokenAndDecodesNothing(): void
    {
        $this->click(100);
        $r = $this->install($this->tokenBody(self::U1, 100, 'tok-off'));
        self::assertSame(['attributed', 'received'], [$r['body']['data']['match'], $r['body']['data']['integrity']]);
        $row = self::installRow(self::U1);
        self::assertSame(['off', null, null], [$row['integrity_mode'], $row['integrity_next_at'], $row['integrity_verdict']]);
        self::assertSame(hash('sha256', 'tok-off'), $row['integrity_token_hash']);
        self::assertStringContainsString('"integrity_token":"tok-off"', $row['raw_payload'], 'kept for a later decode, as PR 5 did');
        self::assertSame(['examined' => 0, 'verdicts' => [], 'retrying' => 0, 'failed' => 0], $this->run1());
        self::assertSame([], $this->decoded);
    }

    public function testObserveRecordsTheVerdictAndNeverMovesAttributionOrMoney(): void
    {
        $this->mode('observe');
        $this->click(100);
        $this->click(101);
        $b1 = $this->tokenBody(self::U1, 100, 'tok-bad-device');
        $b2 = $this->tokenBody(self::U2, 101, 'tok-good');
        $r = $this->install($b1);
        self::assertSame(['attributed', 1, 'pending'], [$r['body']['data']['match'], $r['body']['data']['trusted'], $r['body']['data']['integrity']]);
        $this->install($b2);
        self::assertCount(1, self::ledger(100), 'observe pays at once');
        self::assertCount(2, self::outbox());

        $this->answers['tok-bad-device'] = [$this->verdict($b1, ['deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_BASIC_INTEGRITY']]])];
        $this->answers['tok-good'] = [$this->verdict($b2)];
        self::assertSame(['examined' => 2, 'verdicts' => ['invalid' => 1, 'valid' => 1], 'retrying' => 0, 'failed' => 0], $this->run1());
        self::assertSame(['com.example.summit tok-bad-device', 'com.example.summit tok-good'], $this->decoded, 'decoded for the registration\'s package');

        $bad = self::installRow(self::U1);
        self::assertSame(['attributed', '1', 'invalid'], [$bad['match_state'], (string) $bad['trusted'], $bad['integrity_state']]);
        self::assertStringContainsString('MEETS_BASIC_INTEGRITY', $bad['integrity_reason']);
        self::assertSame('device_integrity', json_decode($bad['integrity_verdict'], true)['code']);
        self::assertNotNull($bad['integrity_checked_at']);
        self::assertCount(1, self::ledger(100), 'a failed verdict under observe un-pays nothing');
        self::assertSame(['lead' => 1, 'payout' => '2.50000'], self::clickValue(100));
        self::assertSame('valid', self::installRow(self::U2)['integrity_state']);
        self::assertCount(2, self::outbox(), 'and recalls no notification');
    }

    public function testRequireHoldsAnAttributableInstallUntilItsVerdictPasses(): void
    {
        $this->mode('require');
        $this->click(100);
        $body = $this->tokenBody(self::U1, 100, 'tok-good');
        $r = $this->install($body);
        self::assertSame(['pending_integrity', null, 'pending'], [$r['body']['data']['match'], $r['body']['data']['trusted'], $r['body']['data']['integrity']]);
        self::assertStringContainsString('waiting for the Play Integrity verdict', $r['body']['data']['reason']);
        $row = self::installRow(self::U1);
        self::assertSame(['require', null, null, null], [$row['integrity_mode'], $row['click_id'], $row['conversion_id'], $row['settled_at']]);
        self::assertSame([], self::ledger(100), 'nothing is paid while the verdict is pending');
        self::assertSame([], self::outbox(), 'and nobody is told');
        self::assertSame(0, self::rows('202_goal_subjects'));
        $waiting = $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]]);
        self::assertSame([503, 'pending_integrity'], [$waiting['status'], $waiting['body']['match']]);

        $this->answers['tok-good'] = [$this->verdict($body, [], $this->clock - 20)];
        self::assertSame(['valid' => 1], $this->run1()['verdicts']);
        $row = self::installRow(self::U1);
        self::assertSame(['attributed', '1', '100', 'valid'], [$row['match_state'], (string) $row['trusted'], (string) $row['click_id'], $row['integrity_state']]);
        self::assertStringContainsString('Play Integrity: valid', $row['match_reason']);
        $ledger = self::ledger(100);
        self::assertCount(1, $ledger);
        self::assertSame((string) $ledger[0]['conv_id'], (string) $row['conversion_id']);
        self::assertSame(['lead' => 1, 'payout' => '2.50000'], self::clickValue(100));
        self::assertSame(1, self::rows('202_attribution_pending', 'conv_id = ' . (int) $ledger[0]['conv_id']));
        self::assertSame([['reached', 'pending']], array_map(static fn (array $o): array => [$o['kind'], $o['status']], self::outbox()));
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]])['status']);

        self::assertSame(['examined' => 0, 'verdicts' => [], 'retrying' => 0, 'failed' => 0], $this->run1(), 'settled once');
        self::assertTrue($this->install($body)['body']['data']['duplicate']);
        self::assertCount(1, self::ledger(100));
    }

    /** @return array<string, array{\Closure, string}> */
    public static function failingVerdicts(): array
    {
        return [
            'another app' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, ['requestDetails' => ['requestPackageName' => 'com.other.app']]), 'requested by "com.other.app"'],
            'another install\'s hash' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, ['requestDetails' => ['requestHash' => str_repeat('1', 64)]]), 'another install body'],
            'stale' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, [], $t->clock - 3600), 'before the install arrived'],
            'not play recognized' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, ['appIntegrity' => ['appRecognitionVerdict' => 'UNRECOGNIZED_VERSION']]), 'UNRECOGNIZED_VERSION'],
            'no device integrity' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, ['deviceIntegrity' => ['deviceRecognitionVerdict' => []]]), 'device integrity'],
            'unlicensed' => [fn (self $t, array $b): DecodeResult => $t->verdict($b, ['accountDetails' => ['appLicensingVerdict' => 'UNLICENSED']]), 'UNLICENSED'],
            'undecodable' => [fn (self $t, array $b): DecodeResult => DecodeResult::rejected('Google could not decode the token (400: Integrity token cannot be decoded.).', 400), 'could not decode'],
        ];
    }

    /** @dataProvider failingVerdicts */
    public function testRequireRefutesAnInstallWhoseVerdictFails(\Closure $verdict, string $says): void
    {
        $this->mode('require');
        $this->click(100);
        $body = $this->tokenBody(self::U1, 100, 'tok-x');
        $this->install($body);
        $this->answers['tok-x'] = [$verdict($this, $body)];
        self::assertSame(['invalid' => 1], $this->run1()['verdicts']);
        $row = self::installRow(self::U1);
        self::assertSame(['integrity_failed', '0', 'invalid', null], [$row['match_state'], (string) $row['trusted'], $row['integrity_state'], $row['click_id']]);
        self::assertStringContainsString($says, $row['match_reason']);
        self::assertNotNull($row['settled_at']);
        self::assertSame([], self::ledger(100));
        self::assertSame([], self::outbox());
        self::assertSame(0, self::rows('202_goal_subjects'), 'a refuted install reaches no goal');
        self::assertSame(409, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'x', 'occurred_at' => self::CLICK_TIME + 500]])['status']);
    }

    public function testAReplayedTokenIsRefused(): void
    {
        $this->mode('require');
        $this->click(100);
        $this->click(101);
        $this->click(102);
        $genuine = $this->tokenBody(self::U1, 100, 'tok-shared');
        $this->install($genuine);
        // The same token lifted onto another install (another uuid, another click).
        $this->install($this->tokenBody(self::U2, 101, 'tok-shared'));
        $this->answers['tok-shared'] = [$this->verdict($genuine)];
        self::assertSame(['valid' => 1, 'invalid' => 1], $this->run1()['verdicts']);
        self::assertSame('attributed', self::installRow(self::U1)['match_state']);
        $lifted = self::installRow(self::U2);
        self::assertSame(['integrity_failed', 'invalid'], [$lifted['match_state'], $lifted['integrity_state']]);
        self::assertStringContainsString('already verified for install ' . self::U1, $lifted['integrity_reason']);
        self::assertSame(['com.example.summit tok-shared'], $this->decoded, 'the replay spent no quota');
        self::assertSame([], self::ledger(101));

        // Processed before the genuine one, the lifted copy fails on its
        // request hash instead: the token is bound to the genuine body.
        $this->install($this->tokenBody(self::U3, 102, 'tok-shared-2'));
        $this->answers['tok-shared-2'] = [$this->verdict($genuine)];
        $this->run1();
        self::assertSame('integrity_failed', self::installRow(self::U3)['match_state']);
        self::assertSame('request_hash', json_decode(self::installRow(self::U3)['integrity_verdict'], true)['code']);
    }

    public function testGoogleFailuresAreRetriedWithBackoffThenRecordedUnverified(): void
    {
        $this->mode('require');
        $this->click(100);
        $this->install($this->tokenBody(self::U1, 100, 'tok-down'));
        $received = (int) self::installRow(self::U1)['received_at'];
        $this->answers['tok-down'] = [DecodeResult::retry('Play Integrity answered 503: The service is currently unavailable.', 503)];

        self::assertSame(['examined' => 1, 'verdicts' => [], 'retrying' => 1, 'failed' => 0], $this->run1());
        $row = self::installRow(self::U1);
        self::assertSame(['pending_integrity', 'pending', '1', (string) ($this->clock + 60)], [$row['match_state'], $row['integrity_state'], (string) $row['integrity_attempts'], (string) $row['integrity_next_at']]);
        self::assertStringContainsString('Attempt 1: Play Integrity answered 503', $row['integrity_reason']);
        self::assertSame(0, $this->run1()['examined'], 'not due before its backoff');
        $this->clock += 60;
        $this->run1();
        $row = self::installRow(self::U1);
        self::assertSame(['2', (string) ($this->clock + 120)], [(string) $row['integrity_attempts'], (string) $row['integrity_next_at']], 'the backoff doubles');

        // Past the deadline the next attempt is the last.
        $this->clock = $received + IntegrityVerifier::DEADLINE;
        self::assertSame(['error' => 1], $this->run1()['verdicts']);
        $row = self::installRow(self::U1);
        self::assertSame(['integrity_unverified', null, 'error', null], [$row['match_state'], $row['trusted'], $row['integrity_state'], $row['integrity_next_at']]);
        self::assertStringContainsString('No verdict after 3 attempts', $row['integrity_reason']);
        self::assertStringContainsString('no verdict could be obtained', $row['match_reason']);
        self::assertSame([], self::ledger(100), 'never waved through');
        self::assertSame([], self::outbox());
        // Unvouched, not refuted: it reaches the funnel with no click.
        self::assertSame(1, self::rows('202_goal_outcomes', "event_id = '@install' AND payable = 0 AND conversion_id IS NULL"));
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]])['status']);
        self::assertSame([], self::ledger(100));
    }

    public function testNoCredentialIsARetryThatNamesTheRemedy(): void
    {
        $this->mode('require', false);
        $this->click(100);
        $this->install($this->tokenBody(self::U1, 100, 'tok-nocred'));
        self::assertSame(1, $this->run1()['retrying']);
        self::assertStringContainsString('PUT /apps/5/integrity-credential', self::installRow(self::U1)['integrity_reason']);
        self::assertSame([], $this->decoded);

        // A stored credential that cannot be decrypted is not "none": it is named.
        $this->mode('require');
        self::fixture("UPDATE 202_app_integrity_credentials SET ciphertext = CONCAT('v1.', TO_BASE64(RANDOM_BYTES(80)))");
        $this->clock += 61;
        $this->run1();
        self::assertStringContainsString('does not decrypt for registration 5', self::installRow(self::U1)['integrity_reason']);
        self::assertSame([], $this->decoded);
    }

    public function testNoTokenUnderRequireIsUnverifiedAtOnceAndPaysNothing(): void
    {
        $this->mode('require');
        $this->click(100);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(['integrity_unverified', null, 'missing'], [$r['body']['data']['match'], $r['body']['data']['trusted'], $r['body']['data']['integrity']]);
        self::assertStringContainsString('carried no integrity token', $r['body']['data']['reason']);
        self::assertSame([], self::ledger(100));
        self::assertSame(0, $this->run1()['examined']);
        // Organic installs are not held: require gates attribution, and they have none.
        self::assertSame(['organic', 'missing'], array_values(array_intersect_key(
            $this->install(self::body(self::U2, 'utm_source=google-play&utm_medium=organic'))['body']['data'],
            ['match' => 1, 'integrity' => 1]
        )));
    }

    public function testATokenOnARefutedInstallIsNotDecoded(): void
    {
        $this->mode('observe');
        $this->click(100);
        $r = $this->install(self::body(self::U1, 'p202=100', ['integrity_token' => 'tok-forger']));
        self::assertSame(['bad_token', 'pending'], [$r['body']['data']['match'], $r['body']['data']['integrity']]);
        self::assertSame(['skipped' => 1], $this->run1()['verdicts']);
        self::assertSame([], $this->decoded, 'a forger cannot spend the quota');
        self::assertSame('skipped', self::installRow(self::U1)['integrity_state']);
    }

    public function testTheModeAnInstallArrivedUnderGovernsIt(): void
    {
        $this->mode('require');
        $this->click(100);
        $this->click(101);
        $body = $this->tokenBody(self::U1, 100, 'tok-held');
        $this->install($body);
        // Switched off while it waits: it still needs its verdict.
        $this->mode('off', false);
        $after = $this->install($this->tokenBody(self::U2, 101, 'tok-after'));
        self::assertSame(['attributed', 'received'], [$after['body']['data']['match'], $after['body']['data']['integrity']], 'a later install is judged under the new mode');
        $this->answers['tok-held'] = [$this->verdict($body, ['appIntegrity' => ['appRecognitionVerdict' => 'UNEVALUATED']])];
        self::assertSame(['invalid' => 1], $this->run1()['verdicts']);
        self::assertSame('integrity_failed', self::installRow(self::U1)['match_state']);
        self::assertSame(['com.example.summit tok-held'], $this->decoded);
    }

    public function testAPendingClickAndAPendingVerdictSettleInEitherOrder(): void
    {
        $this->mode('require');
        // Verdict first, then the click.
        $b1 = $this->tokenBody(self::U1, 500, 'tok-first');
        self::assertSame('pending_click', $this->install($b1)['body']['data']['match']);
        $this->answers['tok-first'] = [$this->verdict($b1)];
        self::assertSame(['valid' => 1], $this->run1()['verdicts']);
        self::assertSame(['pending_click', 'valid'], [self::installRow(self::U1)['match_state'], self::installRow(self::U1)['integrity_state']]);
        $this->click(500);
        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        self::assertSame(['attributed' => 1], $settler->run()['settled']);
        self::assertCount(1, self::ledger(500));

        // The click first, then the verdict.
        $b2 = $this->tokenBody(self::U2, 501, 'tok-second');
        $this->install($b2);
        $this->click(501);
        self::assertSame(['pending_integrity' => 1], $settler->run()['settled']);
        self::assertSame([], self::ledger(501));
        $this->answers['tok-second'] = [$this->verdict($b2)];
        $this->run1();
        self::assertSame('attributed', self::installRow(self::U2)['match_state']);
        self::assertCount(1, self::ledger(501));
    }

    public function testTwoInstallsWaitingOnOneClickPayOnce(): void
    {
        $this->mode('require');
        $this->click(100);
        $b1 = $this->tokenBody(self::U1, 100, 'tok-a');
        $b2 = $this->tokenBody(self::U2, 100, 'tok-b');
        $this->install($b1);
        self::assertSame('pending_integrity', $this->install($b2)['body']['data']['match'], 'neither holds the click while it waits');
        $this->answers['tok-a'] = [$this->verdict($b1)];
        $this->answers['tok-b'] = [$this->verdict($b2)];
        $this->run1();
        self::assertSame(['attributed', 'duplicate_click'], [self::installRow(self::U1)['match_state'], self::installRow(self::U2)['match_state']]);
        self::assertCount(1, self::ledger(100));
        self::assertCount(1, self::outbox());
    }

    public function testAClaimInFlightIsNotTakenTwice(): void
    {
        $this->mode('require');
        $this->click(100);
        $body = $this->tokenBody(self::U1, 100, 'tok-race');
        $this->install($body);
        $id = (int) self::installRow(self::U1)['install_row_id'];
        $inner = null;
        // While the first worker is inside Google's call, a second one runs.
        $this->answers['tok-race'] = [function () use (&$inner, $id, $body): DecodeResult {
            $inner = $this->verifier()->processOne($id);

            return $this->verdict($body);
        }];
        $this->clock += 1;
        self::assertSame('valid', $this->verifier()->processOne($id)?->value);
        self::assertNull($inner, 'the second worker found the lease and left it');
        self::assertCount(1, $this->decoded);
        self::assertCount(1, self::ledger(100));
    }

    public function testASettleThatFailsIsRolledBackAndRetriedWhenItsLeaseEnds(): void
    {
        $this->mode('require');
        $this->click(100);
        $body = $this->tokenBody(self::U1, 100, 'tok-roll');
        $this->install($body);
        $this->answers['tok-roll'] = [$this->verdict($body)];
        self::fixture("CREATE TRIGGER p202_plant BEFORE INSERT ON 202_notification_pending FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: enqueue'");
        self::assertCount(1, self::$db->query('SHOW TRIGGERS')->fetch_all(), 'the plant landed');
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-integrity-it.log');
        try {
            self::assertSame(1, $this->run1()['failed']);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
        $row = self::installRow(self::U1);
        self::assertSame(['pending_integrity', 'pending', '1'], [$row['match_state'], $row['integrity_state'], (string) $row['integrity_attempts']], 'the verdict and the settle rolled back together');
        self::assertSame([], self::ledger(100));
        self::fixture('DROP TRIGGER p202_plant');
        self::assertSame(0, $this->run1()['examined'], 'held by its lease');
        $this->clock += 60;
        self::assertSame(['valid' => 1], $this->run1()['verdicts']);
        self::assertCount(1, self::ledger(100));
        self::assertCount(1, self::outbox());
    }

    public function testDeletingARegistrationSettlesWhatItsInstallsWereWaitingFor(): void
    {
        $this->mode('require');
        $this->click(100);
        $this->install($this->tokenBody(self::U1, 100, 'tok-held'));
        $this->install(self::body(self::U2, 'utm_source=google-play&utm_medium=organic', ['integrity_token' => 'tok-organic']));
        self::assertSame(['pending_integrity', 'pending'], [self::installRow(self::U1)['match_state'], self::installRow(self::U1)['integrity_state']]);
        self::assertSame(['organic', 'pending'], [self::installRow(self::U2)['match_state'], self::installRow(self::U2)['integrity_state']]);

        (new AppRegistrationsController(self::$db, 1))->delete(5);

        // Settled in the delete's own transaction, to states that never pay:
        // the verdict is `error` (none will ever be had) and the held install
        // integrity_unverified, as the deadline would have left it.
        $held = self::installRow(self::U1);
        self::assertSame(['integrity_unverified', null, 'error', null, null, null], [
            $held['match_state'], $held['trusted'], $held['integrity_state'], $held['integrity_next_at'], $held['click_id'], $held['conversion_id'],
        ]);
        self::assertStringContainsString('registration was deleted', $held['integrity_reason']);
        self::assertStringContainsString('registration was deleted', $held['match_reason']);
        self::assertNotNull($held['settled_at']);
        $organic = self::installRow(self::U2);
        self::assertSame(['organic', 'error', null], [$organic['match_state'], $organic['integrity_state'], $organic['integrity_next_at']]);
        self::assertSame([], self::ledger(100));
        self::assertSame([], self::outbox());
        self::assertSame(0, self::rows('202_app_installs', "integrity_state = 'pending' OR match_state = 'pending_integrity'"));

        $this->clock += IntegrityVerifier::DEADLINE;
        self::assertSame(['examined' => 0, 'verdicts' => [], 'retrying' => 0, 'failed' => 0], $this->run1(), 'nothing left for the worker');
        self::assertSame([], $this->decoded);
    }

    public function testRowsTheWorkerCannotProcessNeverStarveAnotherApp(): void
    {
        // Registration 5's queue is orphaned the way a registration deleted
        // outside the API (or before its delete settled the queue) leaves
        // it: installs still pending, their registration gone.
        $this->mode('require');
        $this->click(100);
        $this->install($this->tokenBody(self::U1, 100, 'tok-orphan-held'));
        $this->install(self::body(self::U2, 'utm_source=google-play&utm_medium=organic', ['integrity_token' => 'tok-orphan']));
        // Another owner's app queues one after them.
        self::fixture("UPDATE 202_app_registrations SET integrity_mode = 'observe' WHERE registration_id = 6");
        (new IntegrityCredentialStore(new Connection(self::$db)))->set(2, 6, self::$credential, 1);
        $this->install(self::body(self::U3, 'utm_source=google-play&utm_medium=organic', ['app_key' => 'com.other.app', 'integrity_token' => 'tok-other']), self::OTHER_TOKEN);
        self::fixture('DELETE FROM 202_app_registrations WHERE registration_id = 5');
        self::assertSame(0, self::rows('202_app_registrations', 'registration_id = 5'), 'the orphaning landed');
        self::assertSame(3, self::rows('202_app_installs', "integrity_state = 'pending'"));

        $this->answers['tok-other'] = [DecodeResult::retry('Play Integrity answered 503: unavailable.', 503)];
        $this->clock += 1;
        $run = $this->verifier()->run(1);
        self::assertSame(['com.other.app tok-other'], $this->decoded, 'the one slot went to the install that can be verified, not the older orphans');
        self::assertSame(['examined' => 1, 'verdicts' => [], 'retrying' => 1, 'failed' => 0], $run);

        // And the orphans are finalized rather than left due forever.
        $held = self::installRow(self::U1);
        self::assertSame(['integrity_unverified', null, 'error', null], [$held['match_state'], $held['trusted'], $held['integrity_state'], $held['integrity_next_at']]);
        self::assertStringContainsString('registration no longer exists', $held['integrity_reason']);
        self::assertSame(['organic', 'error'], [self::installRow(self::U2)['match_state'], self::installRow(self::U2)['integrity_state']]);
        self::assertSame('pending', self::installRow(self::U3)['integrity_state']);
        self::assertSame([], self::ledger(100));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\ParsedPostback;
use Api\V3\Attribution\PostbackProtocol;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Tests\TestCase;

/**
 * Receiver behaviour over a bind-capturing mysqli double: validation
 * rejections never touch the database, and the stored row's bound values are
 * asserted against the exact INSERT the receiver builds — the (type, value)
 * pairing is what error pattern #7 is about, so the test reads the captured
 * binds rather than trusting the array's shape.
 *
 * The receiver is exercised through the real SKAdNetwork protocol for the
 * SKAdNetwork cases, and through a stub protocol for the policy only the
 * receiver owns (what a signature verdict is worth once the app's opt-in is
 * known; which columns a protocol may not touch) — SKAdNetwork has no
 * development key, so its protocol cannot produce those inputs.
 */
final class PostbackReceiverTest extends TestCase
{
    use CapturingMysqli;
    use SigningKeyFixture;

    public static function setUpBeforeClass(): void
    {
        self::generateSigningKey();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
    }

    private function receiver(\mysqli $db): PostbackReceiver
    {
        return new PostbackReceiver($db, new SkadnetworkProtocol(self::fixtureVerifier()));
    }

    /**
     * A protocol double that hands the receiver a fixed verdict and a fixed
     * set of protocol-owned columns.
     *
     * @param array<string, array{0: string, 1: mixed}> $columns
     */
    private function stubProtocol(SignatureState $signatureState, array $columns = []): PostbackProtocol
    {
        return new class ($signatureState, $columns) implements PostbackProtocol {
            /** @param array<string, array{0: string, 1: mixed}> $columns */
            public function __construct(private readonly SignatureState $state, private readonly array $columns)
            {
            }

            public function name(): string
            {
                return 'stub';
            }

            public function describe(): array
            {
                return ['endpoint' => 'stub', 'accepts' => 'anything'];
            }

            public function parse(array $body): ParsedPostback|array
            {
                return new ParsedPostback(
                    adNetworkId: 'stub.network',
                    postbackId: 'stub-postback-1',
                    appId: 42,
                    sequenceIndex: 0,
                    didWin: true,
                    signatureState: $this->state,
                    keyId: 'stub-key/1',
                    columns: $this->columns,
                );
            }
        };
    }

    /** @return array<string, mixed> */
    private function signedV4Postback(): array
    {
        return $this->signPostback([
            'version' => '4.0',
            'ad-network-id' => 'example123.skadnetwork',
            'source-identifier' => '5239',
            'app-id' => 525463029,
            'transaction-id' => '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28',
            'redownload' => false,
            'source-app-id' => 1234567891,
            'fidelity-type' => 1,
            'did-win' => true,
            'conversion-value' => 63,
            'country-code' => 'US',
            'postback-sequence-index' => 0,
        ]);
    }

    /** @return array{sql: string, types: string, values: mixed[]}|null */
    private function capturedInsert(): ?array
    {
        foreach ($this->captured as $capture) {
            if (str_starts_with(ltrim($capture['sql']), 'INSERT INTO 202_attribution_postbacks')) {
                return $capture;
            }
        }
        return null;
    }

    /**
     * The captured INSERT as column => bound value, after checking that the
     * column list, the bind string and the value list line up 1:1.
     *
     * @return array<string, mixed>
     */
    private function insertedRow(): array
    {
        $insert = $this->capturedInsert();
        $this->assertNotNull($insert, 'an INSERT must have been prepared');
        $this->assertSame(1, preg_match('/\(([^)]+)\) VALUES/', $insert['sql'], $m));
        $columns = array_map('trim', explode(',', $m[1]));
        $this->assertCount(count($columns), $insert['values']);
        $this->assertSame(strlen($insert['types']), count($insert['values']));
        $row = array_combine($columns, $insert['values']);
        $this->assertNotFalse($row);
        return $row;
    }

    // ─── Rejections (never touch the database) ───────────────────────

    public function testOversizedBodyIsRejectedWithoutTouchingTheDatabase(): void
    {
        $result = $this->receiver($this->capturingDb())->receive(
            str_repeat('x', PostbackReceiver::MAX_BODY_BYTES + 1),
            '1.2.3.4'
        );
        $this->assertSame(413, $result['status']);
        $this->assertSame([], $this->captured);
    }

    public function testEmptyAndMalformedBodiesAreRejected(): void
    {
        $receiver = $this->receiver($this->capturingDb());

        $this->assertSame(400, $receiver->receive('', '1.2.3.4')['status']);
        $this->assertSame(400, $receiver->receive('   ', '1.2.3.4')['status']);
        $this->assertSame(400, $receiver->receive('{not json', '1.2.3.4')['status']);
        $this->assertSame(400, $receiver->receive('"a string"', '1.2.3.4')['status']);
        $this->assertSame(400, $receiver->receive('null', '1.2.3.4')['status']);
        $this->assertSame([], $this->captured);
    }

    public function testMissingCoreFieldsAreNamedInFieldErrors(): void
    {
        $result = $this->receiver($this->capturingDb())->receive('{}', '1.2.3.4');
        $this->assertSame(400, $result['status']);
        $fieldErrors = $result['body']['field_errors'] ?? [];
        foreach (['version', 'ad-network-id', 'transaction-id', 'app-id', 'attribution-signature'] as $field) {
            $this->assertArrayHasKey($field, $fieldErrors);
        }
        $this->assertSame([], $this->captured);
    }

    public function testOutOfRangeAndMistypedFieldsAreRejected(): void
    {
        $valid = $this->signedV4Postback();
        $bad = [
            ['conversion-value', 64],
            ['conversion-value', -1],
            ['conversion-value', '63'],
            ['coarse-conversion-value', 'huge'],
            ['postback-sequence-index', 3],
            ['fidelity-type', 2],
            ['did-win', 'yes'],
            ['redownload', 1],
            ['app-id', '525463029'],
            ['source-identifier', '12345'],
            ['source-app-id', -5],
        ];
        foreach ($bad as [$field, $value]) {
            $payload = $valid;
            $payload[$field] = $value;
            $encoded = json_encode($payload);
            $this->assertNotFalse($encoded);
            $result = $this->receiver($this->capturingDb())->receive($encoded, '1.2.3.4');
            $this->assertSame(400, $result['status'], "$field=" . var_export($value, true));
            $this->assertArrayHasKey($field, $result['body']['field_errors'] ?? [], (string)$field);
        }
        $this->assertSame([], $this->captured);
    }

    // ─── Storage ─────────────────────────────────────────────────────

    public function testValidPostbackIsStoredWithItsBoundValuesIntact(): void
    {
        $postback = $this->signedV4Postback();
        $rawBody = json_encode($postback);
        $this->assertNotFalse($rawBody);

        // The advertised app is registered to user 7.
        $db = $this->capturingDb(['FROM 202_attribution_apps' => [['user_id' => 7, 'accept_development_postbacks' => 0]]]);
        $result = $this->receiver($db)->receive($rawBody, '203.0.113.9', receivedAt: 1_700_000_000);

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['accepted' => true, 'duplicate' => false, 'signature' => 'valid'],
            $result['body']['data']
        );

        $row = $this->insertedRow();
        $this->assertSame(7, $row['user_id']);
        $this->assertSame(1_700_000_000, $row['received_at']);
        $this->assertSame('skadnetwork', $row['protocol']);
        $this->assertSame('4.0', $row['version']);
        $this->assertSame('example123.skadnetwork', $row['ad_network_id']);
        $this->assertSame('6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', $row['transaction_id']);
        $this->assertSame(525463029, $row['app_id']);
        $this->assertSame('5239', $row['source_identifier']);
        $this->assertNull($row['campaign_id']);
        $this->assertSame(63, $row['conversion_value']);
        $this->assertNull($row['coarse_conversion_value']);
        $this->assertSame(0, $row['postback_sequence_index']);
        $this->assertSame(0, $row['redownload']);
        $this->assertSame(1, $row['did_win']);
        $this->assertSame(1234567891, $row['source_app_id']);
        $this->assertNull($row['source_domain']);
        $this->assertSame(1, $row['fidelity_type']);
        $this->assertSame('US', $row['country_code']);
        // The protocol-neutral reading of the SKAdNetwork flags.
        $this->assertSame('download', $row['conversion_type']);
        $this->assertSame('click', $row['ad_interaction_type']);
        $this->assertArrayNotHasKey('marketplace_id', $row, 'SKAdNetwork has no marketplace; the protocol leaves the column alone');
        $this->assertSame('valid', $row['signature_state']);
        $this->assertSame(1, $row['signature_valid']);
        $this->assertNull($row['key_id'], 'SKAdNetwork does not name its key');
        $this->assertSame($rawBody, $row['raw_payload']);
        $this->assertSame('203.0.113.9', $row['remote_ip']);
        $this->assertSame(
            PostbackReceiver::dedupeHash('skadnetwork', 'example123.skadnetwork', '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', 0, true, $rawBody),
            $row['dedupe_hash']
        );
        $this->assertEmpty(
            array_diff(PostbackReceiver::GENERIC_COLUMNS, array_keys($row)),
            'every receiver-owned column is written for every protocol'
        );
    }

    public function testUnregisteredAppStoresAsUnclaimed(): void
    {
        $rawBody = json_encode($this->signedV4Postback());
        $this->assertNotFalse($rawBody);

        $db = $this->capturingDb(['FROM 202_attribution_apps' => []]);
        $result = $this->receiver($db)->receive($rawBody, '1.2.3.4');

        $this->assertSame(200, $result['status']);
        $insert = $this->capturedInsert();
        $this->assertNotNull($insert);
        $this->assertSame(0, $insert['values'][0], 'user_id binds first and must be 0 (unclaimed)');
    }

    public function testTamperedPostbackIsStoredButFlaggedInvalid(): void
    {
        $postback = $this->signedV4Postback();
        $postback['source-identifier'] = '1111'; // breaks the signature
        $rawBody = json_encode($postback);
        $this->assertNotFalse($rawBody);

        $result = $this->receiver($this->capturingDb())->receive($rawBody, '1.2.3.4');

        $this->assertSame(200, $result['status']);
        $this->assertSame('invalid', $result['body']['data']['signature']);
        $row = $this->insertedRow();
        $this->assertSame('invalid', $row['signature_state']);
        $this->assertSame(0, $row['signature_valid']);
    }

    public function testRetiredVersionIsStoredAsUnverifiable(): void
    {
        $postback = [
            'version' => '2.0',
            'ad-network-id' => 'old.skadnetwork',
            'campaign-id' => 9,
            'app-id' => 42,
            'transaction-id' => 'legacy-tx',
            'redownload' => false,
            'attribution-signature' => 'AA==',
        ];
        $rawBody = json_encode($postback);
        $this->assertNotFalse($rawBody);

        $result = $this->receiver($this->capturingDb())->receive($rawBody, '1.2.3.4');

        $this->assertSame(200, $result['status']);
        $this->assertSame('unverifiable', $result['body']['data']['signature']);
        $row = $this->insertedRow();
        $this->assertSame('unverifiable', $row['signature_state']);
        $this->assertNull($row['signature_valid']);
        $this->assertNull($row['did_win'], '2.0 has no did-win; absence must store as NULL, not false');
        $this->assertNull($row['postback_sequence_index']);
        $this->assertNull($row['ad_interaction_type'], 'no fidelity-type means no interaction type, not a guess');
        $this->assertSame('download', $row['conversion_type']);
    }

    // ─── Trust policy (receiver-owned, protocol-independent) ─────────

    /**
     * @dataProvider developmentTrustCases
     * @param array<int, array<string, mixed>> $appRows
     */
    public function testADevelopmentSignatureIsTrustedOnlyWhenTheRegisteredAppOptedIn(array $appRows, int $expectedUserId, ?int $expectedTrustBit): void
    {
        // "Verified against Apple's DEVELOPMENT key" is a true statement that
        // must not count as verified: any phone in Developer Mode can mint
        // one naming any App Store id. The state is stored as the verifier
        // found it; the trust bit is the registration's decision.
        $db = $this->capturingDb(['FROM 202_attribution_apps' => $appRows]);
        $result = (new PostbackReceiver($db, $this->stubProtocol(SignatureState::DEVELOPMENT)))
            ->receive('{"any":"body"}', '1.2.3.4');

        $this->assertSame(200, $result['status']);
        $this->assertSame('development', $result['body']['data']['signature']);
        $row = $this->insertedRow();
        // The stub contributes no columns, so the INSERT is exactly the
        // receiver's own set — GENERIC_COLUMNS must list every one of them
        // and nothing else, or the collision guard has a blind spot.
        $this->assertEqualsCanonicalizing(PostbackReceiver::GENERIC_COLUMNS, array_keys($row));
        $this->assertSame('stub', $row['protocol']);
        $this->assertSame($expectedUserId, $row['user_id']);
        $this->assertSame('development', $row['signature_state']);
        $this->assertSame($expectedTrustBit, $row['signature_valid']);
        $this->assertSame('stub-key/1', $row['key_id']);
    }

    /** @return array<string, array{0: array<int, array<string, mixed>>, 1: int, 2: ?int}> */
    public static function developmentTrustCases(): array
    {
        return [
            'unregistered app' => [[], 0, null],
            'registered, opt-in off' => [[['user_id' => 7, 'accept_development_postbacks' => 0]], 7, null],
            'registered, opt-in on' => [[['user_id' => 7, 'accept_development_postbacks' => 1]], 7, 1],
            // A value that is not exactly 1 is the untrusting reading
            // (error pattern #11): the column cannot be read as "on" by
            // accident, and a corrupt row must not widen trust.
            'registered, opt-in unreadable' => [[['user_id' => 7, 'accept_development_postbacks' => null]], 7, null],
        ];
    }

    public function testTheOptInNeverPromotesAForgedOrUnverifiableSignature(): void
    {
        // The opt-in is about development keys only. A forgery stays 0 and
        // an unverifiable row stays NULL whatever the registration says.
        $appRows = [['user_id' => 7, 'accept_development_postbacks' => 1]];
        foreach ([[SignatureState::INVALID, 0], [SignatureState::UNVERIFIABLE, null]] as [$state, $expected]) {
            $this->captured = [];
            $db = $this->capturingDb(['FROM 202_attribution_apps' => $appRows]);
            (new PostbackReceiver($db, $this->stubProtocol($state)))->receive('{}', '1.2.3.4');
            $row = $this->insertedRow();
            $this->assertSame($state->value, $row['signature_state']);
            $this->assertSame($expected, $row['signature_valid'], $state->value);
        }
    }

    public function testAProtocolCannotWriteAReceiverOwnedColumn(): void
    {
        // The trust bit, the owner and the identity columns have one author.
        // A protocol naming one of them — by design or by a typo in its
        // column map — is a defect that must not reach the table.
        $db = $this->capturingDb(['FROM 202_attribution_apps' => []]);
        $receiver = new PostbackReceiver($db, $this->stubProtocol(SignatureState::INVALID, [
            'signature_valid' => ['i', 1],
        ]));
        try {
            $receiver->receive('{}', '1.2.3.4');
            $this->fail('expected a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('signature_valid', $e->getMessage());
        }
        $this->assertNull($this->capturedInsert(), 'nothing may be written');
    }

    public function testDuplicateKeyOnInsertReportsDuplicateWith200(): void
    {
        $rawBody = json_encode($this->signedV4Postback());
        $this->assertNotFalse($rawBody);

        $db = $this->capturingDb([], static function (): never {
            throw new \mysqli_sql_exception('Duplicate entry', 1062);
        });
        $result = $this->receiver($db)->receive($rawBody, '1.2.3.4');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['data']['duplicate']);
    }

    public function testNonDuplicateInsertFailureReturns500SoTheDeviceRetries(): void
    {
        $rawBody = json_encode($this->signedV4Postback());
        $this->assertNotFalse($rawBody);

        $db = $this->capturingDb([], static function (): never {
            throw new \mysqli_sql_exception('Lock wait timeout exceeded', 1205);
        });
        $result = $this->receiver($db)->receive($rawBody, '1.2.3.4');

        $this->assertSame(500, $result['status']);
        $this->assertTrue($result['body']['error']);
    }

    public function testAMalformedRetentionOverrideSkipsThatClassInsteadOfPruningOnTheDefault(): void
    {
        // "never" is what an operator writes meaning KEEP. Falling back to
        // the 30-day default would delete on a window they never chose, so
        // a value we cannot parse prunes nothing for that class.
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED=never');
        try {
            $db = $this->capturingDb();
            $this->receiver($db)->prunePostbacks(1_800_000_000);

            $deletes = array_values(array_filter(
                $this->captured,
                static fn(array $c): bool => str_starts_with(ltrim($c['sql']), 'DELETE')
            ));
            foreach ($deletes as $delete) {
                $this->assertStringNotContainsString(
                    'user_id = 0',
                    $delete['sql'],
                    'an unparseable retention value must not fall back to the destructive default'
                );
            }
            $this->assertCount(2, $deletes, 'the other classes still prune');
        } finally {
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED');
        }
    }

    /**
     * @dataProvider trailingNewlineFields
     */
    public function testATrailingNewlineDoesNotSlipPastALengthCheckedField(string $field, mixed $value): void
    {
        // PCRE's $ matches before a final newline without the D modifier, so
        // "1234\n" passed a /^\d{1,4}$/ meant to bound a varchar(4): the row
        // then failed the INSERT and answered 500, which a device retries
        // forever, instead of the 400 the validator exists to produce.
        $body = json_encode(array_merge([
            'version' => '4.0',
            'ad-network-id' => 'eval.skadnetwork',
            'transaction-id' => 'tx-newline',
            'app-id' => 42,
            'attribution-signature' => 'AA==',
        ], [$field => $value]));
        $this->assertNotFalse($body);

        $result = $this->receiver($this->capturingDb())->receive($body, '127.0.0.1');
        $this->assertSame(400, $result['status'], "$field must be rejected");
        $this->assertArrayHasKey($field, $result['body']['field_errors']);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function trailingNewlineFields(): array
    {
        return [
            'source-identifier' => ['source-identifier', "1234\n"],
            'version' => ['version', "4.0\n"],
        ];
    }

    public function testRetentionPruningTargetsOnlyUnclaimedAndForgedRowsWithBoundedBatches(): void
    {
        $db = $this->capturingDb();
        $now = 1_800_000_000;
        $this->receiver($db)->prunePostbacks($now);

        $deletes = array_values(array_filter(
            $this->captured,
            static fn(array $c): bool => str_starts_with(ltrim($c['sql']), 'DELETE')
        ));
        $this->assertCount(3, $deletes, 'one bounded delete per retention class');
        $this->assertStringContainsString('WHERE user_id = 0 AND received_at < ?', $deletes[0]['sql']);
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_UNCLAIMED * 86400], $deletes[0]['values']);
        $this->assertStringContainsString('WHERE signature_valid = 0 AND received_at < ?', $deletes[1]['sql']);
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_INVALID * 86400], $deletes[1]['values']);
        // The class an unauthenticated poster can mint for free: naming a
        // version the verifier does not know stores signature_valid = NULL,
        // and a body naming a registered app is claimed too — so without
        // this delete the row matched neither of the others and lived
        // forever.
        $this->assertStringContainsString('WHERE signature_valid IS NULL AND received_at < ?', $deletes[2]['sql']);
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_UNVERIFIABLE * 86400], $deletes[2]['values']);
        foreach ($deletes as $delete) {
            $this->assertStringContainsString('LIMIT 500', $delete['sql'], 'a pass must stay cheap on the request path');
            $this->assertStringNotContainsString('signature_valid = 1', $delete['sql'], 'verified rows are never pruned');
        }
    }

    public function testRetentionOverridesComeFromTheEnvironmentAndZeroDisablesAClass(): void
    {
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED=7');
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID=0');
        try {
            $db = $this->capturingDb();
            $now = 1_800_000_000;
            $this->receiver($db)->prunePostbacks($now);

            $deletes = array_values(array_filter(
                $this->captured,
                static fn(array $c): bool => str_starts_with(ltrim($c['sql']), 'DELETE')
            ));
            $this->assertCount(2, $deletes, 'a 0-day override disables that class entirely');
            $this->assertStringContainsString('user_id = 0', $deletes[0]['sql']);
            $this->assertSame([$now - 7 * 86400], $deletes[0]['values']);
        } finally {
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED');
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID');
        }
    }

    public function testRetentionPolicyReportsTheWindowsThisProcessResolved(): void
    {
        // What 202-cronjobs/attribution-retention.php prints. The overrides
        // are read from the environment of whichever process prunes, so an
        // operator's only way to see that their crontab value took is the
        // cron reporting the window it resolved.
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED=7');
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID=0');
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE=90 days');
        try {
            $now = 1_800_000_000;
            $policy = PostbackReceiver::retentionPolicy($now);

            $this->assertSame(['days' => 7, 'cutoff' => $now - 7 * 86400], $policy['unclaimed']);
            $this->assertSame(['days' => 0, 'cutoff' => null], $policy['invalid'], 'an explicit 0 disables the class');
            // A value we cannot parse prunes nothing rather than falling back
            // to the destructive default, and the report says so — an
            // operator who sees "disabled" for a class they set knows the
            // value was refused.
            $this->assertSame(['days' => 0, 'cutoff' => null], $policy['unverifiable']);
        } finally {
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED');
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID');
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE');
        }
    }

    public function testRetentionBacklogCountsEachClassAgainstItsOwnWindow(): void
    {
        $now = 1_800_000_000;
        $db = $this->capturingDb([
            'WHERE user_id = 0 AND' => [['aged' => 7]],
            'WHERE signature_valid = 0 AND' => [['aged' => 0]],
            'WHERE signature_valid IS NULL AND' => [['aged' => 1200]],
        ]);

        $backlog = $this->receiver($db)->retentionBacklog($now);
        $this->assertSame(['unclaimed' => 7, 'invalid' => 0, 'unverifiable' => 1200], $backlog);

        // The count has to ask exactly what the delete asks, or the cron
        // reports a backlog it is not pruning: same predicates, same cutoffs,
        // and unbounded (the LIMIT belongs to a pass, not to the backlog).
        $counts = $this->capturedStatements('SELECT');
        $this->assertCount(3, $counts, 'one count per pruning class');
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_UNCLAIMED * 86400], $counts[0]['values']);
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_INVALID * 86400], $counts[1]['values']);
        $this->assertSame([$now - PostbackReceiver::DEFAULT_RETENTION_DAYS_UNVERIFIABLE * 86400], $counts[2]['values']);
        foreach ($counts as $count) {
            $this->assertStringContainsString('received_at < ?', $count['sql']);
            $this->assertStringNotContainsString('LIMIT', $count['sql']);
        }
    }

    public function testRetentionBacklogRefusesToReadAMissingCountAsZero(): void
    {
        // COUNT(*) always answers with exactly one row, so no row is a
        // transport failure. Reported as 0 it would tell the cron there is
        // nothing to prune — the silent-empty-answer shape of error pattern
        // #1, on the number an operator uses to decide whether retention is
        // working.
        $db = $this->capturingDb();

        $this->expectException(\Api\V3\Exception\DatabaseException::class);
        $this->receiver($db)->retentionBacklog(1_800_000_000);
    }

    public function testADisabledClassCountsAsZeroWithoutAskingTheDatabase(): void
    {
        putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID=0');
        try {
            $db = $this->capturingDb(['received_at < ?' => [['aged' => 3]]]);
            $backlog = $this->receiver($db)->retentionBacklog(1_800_000_000);

            $this->assertSame(0, $backlog['invalid'], 'a class that prunes nothing has no backlog');
            $this->assertCount(2, $this->capturedStatements('SELECT'), 'and is not counted for');
        } finally {
            putenv('P202_ATTRIBUTION_RETENTION_DAYS_INVALID');
        }
    }

    public function testDedupeHashSeparatesTheLegsOfOneTransaction(): void
    {
        // The three conversion windows and the win/loss legs of one
        // transaction are distinct postbacks; only a true retry (same leg)
        // may collide.
        $body = '{"transaction-id":"tx"}';
        $hashes = [
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, true, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 1, true, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 2, true, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, false, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', null, null, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'other', 'tx', 0, true, $body),
            // One table holds every protocol; each protocol's id space is
            // its own, so the same tuple under another protocol is another
            // postback.
            PostbackReceiver::dedupeHash('adattributionkit', 'n', 'tx', 0, true, $body),
        ];
        $this->assertSame($hashes, array_values(array_unique($hashes)));

        $this->assertSame(
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, true, $body),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, true, $body)
        );
    }

    public function testDedupeHashCoversTheBodySoForgeriesCannotOccupyARealSlot(): void
    {
        // The identity tuple (network, transaction, index, win) is attacker
        // choosable: anyone can POST well-formed junk naming a real
        // transaction. If the dedupe hash covered only that tuple, a forgery
        // arriving first would claim the UNIQUE slot and the genuine signed
        // postback would be dropped as a "duplicate". Folding the body in
        // means only a true retry (Apple resends the identical body) dedupes.
        $this->assertNotSame(
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, true, '{"conversion-value":63}'),
            PostbackReceiver::dedupeHash('skadnetwork', 'n', 'tx', 0, true, '{"conversion-value":0}')
        );
    }

    public function testDedupeHashIsNotFooledByFieldBoundaryGames(): void
    {
        // With a plain joining character an ad-network-id of "a|b" and a
        // transaction of "c" would serialize identically to "a" + "b|c",
        // letting a crafted postback occupy another one's dedupe slot. The
        // length prefixes pin the boundaries even when the bodies match.
        $this->assertNotSame(
            PostbackReceiver::dedupeHash('p', 'a|b', 'c', 0, true, '{}'),
            PostbackReceiver::dedupeHash('p', 'a', 'b|c', 0, true, '{}')
        );
        $this->assertNotSame(
            PostbackReceiver::dedupeHash('p|a', 'b', 'c', 0, true, '{}'),
            PostbackReceiver::dedupeHash('p', 'a|b', 'c', 0, true, '{}')
        );
    }
}

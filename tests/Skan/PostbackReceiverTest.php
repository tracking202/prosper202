<?php

declare(strict_types=1);

namespace Tests\Skan;

use Api\V3\Skan\PostbackReceiver;
use Api\V3\Skan\PostbackVerifier;
use Tests\TestCase;

/**
 * Receiver behaviour over a bind-capturing mysqli double: validation
 * rejections never touch the database, and the stored row's bound values are
 * asserted against the exact INSERT the receiver builds — the (type, value)
 * pairing is what error pattern #7 is about, so the test reads the captured
 * binds rather than trusting the array's shape.
 */
final class PostbackReceiverTest extends TestCase
{
    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $publicKeyB64 = '';

    /** @var array<int, array{sql: string, types: string, values: mixed[]}> */
    private array $captured = [];

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            self::fail('Could not generate a P-256 key');
        }
        self::$key = $key;
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            self::fail('Could not read generated key details');
        }
        self::$publicKeyB64 = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"],
            '',
            $details['key']
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
    }

    /**
     * A mysqli double whose statements capture their SQL, bind types, and
     * bind values into $this->captured. $selectRows maps an SQL substring to
     * the rows its get_result returns; $insertBehavior is 'ok' or a callable
     * run when the INSERT executes (e.g. to throw a duplicate-key error).
     *
     * @param array<string, array<int, array<string, mixed>>> $selectRows
     */
    private function capturingDb(array $selectRows = [], string|callable $insertBehavior = 'ok'): \mysqli
    {
        $testCase = $this;

        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($testCase, $selectRows, $insertBehavior): \mysqli_stmt {
                /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
                $stmt = $testCase->getMockBuilder(\mysqli_stmt::class)
                    ->disableOriginalConstructor()
                    ->getMock();

                $index = count($testCase->captured);
                $testCase->captured[$index] = ['sql' => $sql, 'types' => '', 'values' => []];

                $stmt->method('bind_param')->willReturnCallback(
                    function (string $types, mixed ...$values) use ($testCase, $index): bool {
                        $testCase->captured[$index]['types'] = $types;
                        $testCase->captured[$index]['values'] = $values;
                        return true;
                    }
                );
                $stmt->method('execute')->willReturnCallback(
                    function () use ($sql, $insertBehavior): bool {
                        if (str_starts_with(ltrim($sql), 'INSERT') && is_callable($insertBehavior)) {
                            return (bool)$insertBehavior();
                        }
                        return true;
                    }
                );
                $stmt->method('get_result')->willReturnCallback(
                    function () use ($testCase, $sql, $selectRows): \mysqli_result {
                        foreach ($selectRows as $pattern => $rows) {
                            if (str_contains($sql, $pattern)) {
                                return $testCase->buildResultMock($pattern, [$pattern => $rows]);
                            }
                        }
                        return $testCase->buildResultMock($sql, []);
                    }
                );
                $stmt->method('close')->willReturn(true);

                return $stmt;
            }
        );

        return $db;
    }

    private function receiver(\mysqli $db): PostbackReceiver
    {
        return new PostbackReceiver($db, new PostbackVerifier(self::$publicKeyB64));
    }

    /** @return array<string, mixed> */
    private function signedV4Postback(): array
    {
        $postback = [
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
        ];
        $message = (new PostbackVerifier(self::$publicKeyB64))->buildSignedMessage($postback);
        $this->assertNotNull($message);
        $signature = '';
        $this->assertNotNull(self::$key);
        $this->assertTrue(openssl_sign($message, $signature, self::$key, OPENSSL_ALGO_SHA256));
        $postback['attribution-signature'] = base64_encode($signature);
        return $postback;
    }

    /** @return array{sql: string, types: string, values: mixed[]}|null */
    private function capturedInsert(): ?array
    {
        foreach ($this->captured as $capture) {
            if (str_starts_with(ltrim($capture['sql']), 'INSERT INTO 202_skan_postbacks')) {
                return $capture;
            }
        }
        return null;
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
        $db = $this->capturingDb(['FROM 202_skan_apps' => [['user_id' => 7]]]);
        $result = $this->receiver($db)->receive($rawBody, '203.0.113.9', receivedAt: 1_700_000_000);

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['accepted' => true, 'duplicate' => false, 'signature' => 'valid'],
            $result['body']['data']
        );

        $insert = $this->capturedInsert();
        $this->assertNotNull($insert, 'an INSERT must have been prepared');

        // Columns in the SQL and the bound values must line up 1:1.
        $this->assertSame(1, preg_match('/\(([^)]+)\) VALUES/', $insert['sql'], $m));
        $columns = array_map('trim', explode(',', $m[1]));
        $this->assertCount(count($columns), $insert['values']);
        $this->assertSame(strlen($insert['types']), count($insert['values']));

        $row = array_combine($columns, $insert['values']);
        $this->assertSame(7, $row['user_id']);
        $this->assertSame(1_700_000_000, $row['received_at']);
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
        $this->assertSame(1, $row['signature_valid']);
        $this->assertSame($rawBody, $row['raw_payload']);
        $this->assertSame('203.0.113.9', $row['remote_ip']);
        $this->assertSame(
            PostbackReceiver::dedupeHash('example123.skadnetwork', '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', 0, true),
            $row['dedupe_hash']
        );
    }

    public function testUnregisteredAppStoresAsUnclaimed(): void
    {
        $rawBody = json_encode($this->signedV4Postback());
        $this->assertNotFalse($rawBody);

        $db = $this->capturingDb(['FROM 202_skan_apps' => []]);
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
        $insert = $this->capturedInsert();
        $this->assertNotNull($insert);
        $row = array_combine(
            array_map('trim', explode(',', preg_replace('/^.*\(([^)]+)\) VALUES.*$/s', '$1', $insert['sql']) ?? '')),
            $insert['values']
        );
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
        $insert = $this->capturedInsert();
        $this->assertNotNull($insert);
        $row = array_combine(
            array_map('trim', explode(',', preg_replace('/^.*\(([^)]+)\) VALUES.*$/s', '$1', $insert['sql']) ?? '')),
            $insert['values']
        );
        $this->assertNull($row['signature_valid']);
        $this->assertNull($row['did_win'], '2.0 has no did-win; absence must store as NULL, not false');
        $this->assertNull($row['postback_sequence_index']);
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

    public function testDedupeHashSeparatesTheLegsOfOneTransaction(): void
    {
        // The three conversion windows and the win/loss legs of one
        // transaction are distinct postbacks; only a true retry (same leg)
        // may collide.
        $hashes = [
            PostbackReceiver::dedupeHash('n', 'tx', 0, true),
            PostbackReceiver::dedupeHash('n', 'tx', 1, true),
            PostbackReceiver::dedupeHash('n', 'tx', 2, true),
            PostbackReceiver::dedupeHash('n', 'tx', 0, false),
            PostbackReceiver::dedupeHash('n', 'tx', null, null),
            PostbackReceiver::dedupeHash('other', 'tx', 0, true),
        ];
        $this->assertSame($hashes, array_values(array_unique($hashes)));

        $this->assertSame(
            PostbackReceiver::dedupeHash('n', 'tx', 0, true),
            PostbackReceiver::dedupeHash('n', 'tx', 0, true)
        );
    }

    public function testDedupeHashIsNotFooledByFieldBoundaryGames(): void
    {
        // With a plain joining character an ad-network-id of "a|b" and a
        // transaction of "c" would serialize identically to "a" + "b|c",
        // letting a crafted postback occupy another one's dedupe slot. The
        // length prefixes pin the boundaries.
        $this->assertNotSame(
            PostbackReceiver::dedupeHash('a|b', 'c', 0, true),
            PostbackReceiver::dedupeHash('a', 'b|c', 0, true)
        );
    }
}

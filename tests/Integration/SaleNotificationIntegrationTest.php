<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Integration\NotificationSecrets;
use Prosper202\Integration\SaleNotification;

/**
 * What a payment platform's notification records, through the real ledger
 * writer: the sale on the click's own account, its replay as a duplicate, a
 * refund netting it, and the accounts whose secrets are tried.
 *
 * @group integration
 */
final class SaleNotificationIntegrationTest extends TestCase
{
    private const DATA_ENGINE_STUB = 'class DataEngine { public function setDirtyHour($id) {}'
        . ' public function getSummary($s, $e, $p, $u = 1, $up = false, $n = false) { return ""; } }';

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            require_once __DIR__ . '/../../202-config/functions.php';
        }
        if (!class_exists('DataEngine', false)) {
            eval(self::DATA_ENGINE_STUB);
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_users_pref', '202_aff_campaigns'] as $t) {
            self::q('TRUNCATE TABLE ' . $t);
        }
        self::q('DELETE FROM 202_user_role');
        self::q('DELETE FROM 202_users');
        foreach ([1, 2] as $user) {
            self::q("INSERT INTO 202_users SET user_id = $user, user_name = 'u$user', user_pass = 'x'");
            self::q("INSERT INTO 202_users_pref SET user_id = $user");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = {$user}0, user_id = $user,"
                . ' aff_campaign_payout = 5');
        }
        self::click(101, 1);
        self::click(202, 2);
    }

    private static function q(string $sql): void
    {
        self::assertNotFalse(self::$db?->query($sql), $sql);
    }

    private static function click(int $clickId, int $user): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $clickId, user_id = $user, aff_campaign_id = {$user}0,"
                . ' click_payout = 0, click_cpc = 0, click_lead = 0, click_time = 1700000000');
        }
    }

    /**
     * @return list<array{source: string, amount: string, source_ref: string|null, reverses: int|null}>
     */
    private static function rows(int $clickId): array
    {
        $out = [];
        $res = self::$db?->query('SELECT source, click_payout, source_ref, reverses_conv_id FROM 202_conversion_logs'
            . " WHERE click_id = $clickId AND deleted = 0 ORDER BY conv_id");
        self::assertInstanceOf(\mysqli_result::class, $res);
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'source' => (string) $row['source'],
                'amount' => (string) $row['click_payout'],
                'source_ref' => $row['source_ref'],
                'reverses' => $row['reverses_conv_id'] !== null ? (int) $row['reverses_conv_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $accounts
     * @param array<string, mixed> $extra
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     */
    private static function notify(array $accounts, int $clickId, string $tx, ?string $amount, array $extra = []): array
    {
        $sale = $extra + ['source' => 'jvzoo', 'transaction_id' => $tx, 'amount' => $amount, 'reversal' => false];

        return SaleNotification::record(self::$db, $accounts, $clickId, $sale);
    }

    /** @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string} */
    private static function refund(int $clickId, string $tx, string $ref, ?string $amount = null): array
    {
        return self::notify([1], $clickId, $tx, $amount, ['reversal' => true, 'reversal_ref' => $ref]);
    }

    /**
     * A PayKickstart notification on click 101: a sale, or with $refund a refund
     * under its own transaction id.
     *
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     */
    private static function paykickstart(string $tx, ?string $amount, string $invoice, bool $refund = false): array
    {
        $extra = ['source' => 'paykickstart', 'source_ref' => 'invoice:' . $invoice];
        if ($refund) {
            $extra += ['reversal' => true, 'reversal_ref' => $tx];
        }

        return self::notify([1], 101, $tx, $amount, $extra);
    }

    public function testASaleRecordsOnceAtItsAmount(): void
    {
        $first = self::notify([1], 101, 'R-1', '47.00');
        self::assertTrue($first['recorded']);
        self::assertSame(
            ['recorded' => false, 'duplicate' => true, 'conv_id' => $first['conv_id'], 'reason' => 'duplicate'],
            self::notify([1], 101, 'R-1', '47.00')
        );

        self::assertTrue(self::notify([1], 101, 'R-2', '47.00')['recorded'], 'another receipt is another sale');
        self::assertSame(['47.00000', '47.00000'], array_column(self::rows(101), 'amount'));
        self::assertSame(['jvzoo', 'jvzoo'], array_column(self::rows(101), 'source'));
    }

    public function testASaleRecordsOnlyOnTheAccountWhoseClickItIs(): void
    {
        $outcome = self::notify([1], 202, 'R-1', '47.00');
        self::assertSame('foreign_click', $outcome['reason'], 'account 1 verified it; click 202 is account 2\'s');
        self::assertSame([], self::rows(202));

        self::assertTrue(self::notify([1, 2], 202, 'R-1', '47.00')['recorded']);
        $owner = self::$db?->query('SELECT user_id FROM 202_conversion_logs WHERE click_id = 202')->fetch_row();
        self::assertSame('2', (string) ($owner[0] ?? ''));

        self::assertSame('unknown_click', self::notify([1, 2], 999, 'R-9', '1.00')['reason']);
    }

    public function testARefundNetsTheSaleOnce(): void
    {
        $sale = self::notify([1], 101, 'R-1', '47.00');
        self::assertTrue(self::refund(101, 'R-1', 'RFND')['recorded']);
        self::assertSame(['47.00000', '-47.00000'], array_column(self::rows(101), 'amount'));
        self::assertSame($sale['conv_id'], self::rows(101)[1]['reverses']);

        self::assertTrue(self::refund(101, 'R-1', 'RFND')['duplicate']);
        self::assertSame(
            'already_reversed',
            self::refund(101, 'R-1', 'CGBK')['reason'],
            'a chargeback after the refund does not net the sale twice'
        );
        self::assertSame('nothing_to_reverse', self::refund(101, 'R-404', 'RFND')['reason']);
        self::assertCount(2, self::rows(101));
    }

    public function testAPartialRefundNetsItsOwnAmount(): void
    {
        self::notify([1], 101, 'R-1', '47.00');
        self::refund(101, 'R-1', 'RFND', '7.00');
        self::assertSame(['47.00000', '-7.00000'], array_column(self::rows(101), 'amount'));
    }

    public function testARefundUnderItsOwnTransactionIdReversesTheNewestSaleOfItsPurchase(): void
    {
        self::paykickstart('T-1', '9.00', 'I-1');
        self::paykickstart('T-2', '9.00', 'I-1');
        self::paykickstart('T-3', '20.00', 'I-2');

        $refund = self::paykickstart('T-9', null, 'I-1', true);
        self::assertTrue($refund['recorded']);
        $rows = self::rows(101);
        self::assertSame(['9.00000', '9.00000', '20.00000', '-9.00000'], array_column($rows, 'amount'));
        self::assertSame(
            ['invoice:I-1', 'invoice:I-1', 'invoice:I-2', 'conv:2'],
            array_column($rows, 'source_ref'),
            'it reversed T-2'
        );

        $replay = self::paykickstart('T-9', null, 'I-1', true);
        self::assertTrue($replay['duplicate'], 'the same refund again is a duplicate');
        self::assertSame($refund['conv_id'], $replay['conv_id']);
        self::assertCount(4, self::rows(101), 'and does not reverse the purchase\'s other payment');

        self::assertTrue(
            self::paykickstart('T-10', null, 'I-1', true)['recorded'],
            'the next refund of the purchase takes the sale not yet reversed'
        );
        self::assertSame('nothing_to_reverse', self::paykickstart('T-11', null, 'I-1', true)['reason']);
    }

    public function testTheSecretsTriedAreActiveAccountsThatSavedOne(): void
    {
        self::q("UPDATE 202_users_pref SET jvzoo_ipn_secret_key = ' key-one ', user_account_currency = 'EUR'"
            . ' WHERE user_id = 1');
        self::q("UPDATE 202_users_pref SET jvzoo_ipn_secret_key = 'key-two' WHERE user_id = 2");
        $conn = new Connection(self::$db);
        self::assertSame(
            [
                ['user_id' => 1, 'secret' => 'key-one', 'currency' => 'EUR'],
                ['user_id' => 2, 'secret' => 'key-two', 'currency' => 'USD'],
            ],
            NotificationSecrets::holders($conn, 'jvzoo_ipn_secret_key')
        );

        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = 2');
        self::assertSame([1], array_column(NotificationSecrets::holders($conn, 'jvzoo_ipn_secret_key'), 'user_id'));
        self::q('UPDATE 202_users SET user_active = 1, user_deleted = 1 WHERE user_id = 2');
        self::assertSame([1], array_column(NotificationSecrets::holders($conn, 'jvzoo_ipn_secret_key'), 'user_id'));
        self::assertSame([], NotificationSecrets::holders($conn, 'zaxaa_api_signature'));

        $this->expectException(\InvalidArgumentException::class);
        NotificationSecrets::holders($conn, 'cb_key');
    }
}

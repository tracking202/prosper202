<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * tests/fixtures/summary-totals/vectors.json holds 202_dataengine rows for
 * the profiles of each case and what GET /reports/summary answers for each
 * profile and for all of their rows at once. The Go CLI's multi-profile
 * TOTAL is held to the union's answer (TestSummaryTotalIsWhatTheServer
 * AnswersForTheUnion), so the answers have to be the server's: this runs
 * every case through ReportsController::summary() on a real server, as each
 * profile and as the union, and compares. MySQL alone decides how its
 * DECIMAL division truncates and rounds, which is what the TOTAL repeats.
 *
 * P202_UPDATE_SUMMARY_TOTALS=1 writes the answers into the file instead of
 * comparing them (after a change to the summary's SQL, or to the rows).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 7400-7999.
 *
 * @group integration
 */
final class SummaryTotalVectorsIntegrationTest extends TestCase
{
    private const FILE = __DIR__ . '/../../fixtures/summary-totals/vectors.json';
    private const FIRST_USER = 7400;
    private const CURRENCY_USER = 7999;
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
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
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        $range = self::FIRST_USER . ' AND ' . self::CURRENCY_USER;
        self::q("DELETE FROM 202_dataengine WHERE user_id BETWEEN $range");
        self::q("DELETE FROM 202_users_pref WHERE user_id BETWEEN $range");
    }

    /** @param list<list<?string>> $rows clicks, click_out, leads, income, cost */
    private static function insertRows(int $userId, array $rows, int &$clickId): void
    {
        foreach ($rows as $row) {
            self::assertCount(5, $row);
            $values = array_map(
                static fn (?string $v): string => $v === null ? 'NULL' : "'" . self::$db->real_escape_string($v) . "'",
                $row
            );
            $clickId++;
            self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, landing_page_id,'
                . ' payout, clicks, click_out, leads, income, cost)'
                . " VALUES ($userId, $clickId, 1700000000, 0, 0, 0, " . implode(', ', $values) . ')');
        }
    }

    /** @return array<string, mixed> the summary's data as a client decodes it */
    private static function answer(int $userId): array
    {
        $data = (new ReportsController(self::$db, $userId))->summary([])['data'];

        return json_decode((string) json_encode($data, self::JSON), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryCaseIsWhatTheServerAnswers(): void
    {
        $file = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(50, count($file['cases']));
        $update = getenv('P202_UPDATE_SUMMARY_TOTALS') === '1';

        $clickId = 0;
        foreach ($file['cases'] as $i => &$case) {
            $union = self::FIRST_USER + $i * 4 + 3;
            self::assertLessThanOrEqual(3, count($case['profiles']), $case['name']);
            foreach ($case['profiles'] as $p => &$profile) {
                self::insertRows(self::FIRST_USER + $i * 4 + $p, $profile['rows'], $clickId);
                self::insertRows($union, $profile['rows'], $clickId);
            }
            unset($profile);

            foreach ($case['profiles'] as $p => &$profile) {
                $got = self::answer(self::FIRST_USER + $i * 4 + $p);
                if ($update) {
                    $profile['answer'] = $got;
                } else {
                    self::assertSame($profile['answer'], $got, "{$case['name']}: profile $p");
                }
            }
            unset($profile);
            $got = self::answer($union);
            if ($update) {
                $case['union'] = $got;
            } else {
                self::assertSame($case['union'], $got, "{$case['name']}: the union");
            }
        }
        unset($case);

        if ($update) {
            self::write($file);
            self::markTestIncomplete('Wrote the answers to ' . realpath(self::FILE) . '; run again to compare.');
        }
    }

    /** The currency is the account's, read as the report pages read it. */
    public function testTheSummaryStatesTheAccountCurrency(): void
    {
        self::q('INSERT INTO 202_users_pref (user_id, user_account_currency)'
            . ' VALUES (' . self::CURRENCY_USER . ", 'EUR')");
        self::assertSame('EUR', self::answer(self::CURRENCY_USER)['currency']);
        $none = self::answer(self::CURRENCY_USER - 1);
        self::assertSame('USD', $none['currency'], 'no preferences row: the column default');
    }

    /** One case per block and one answer per line, so a diff reads as a change of answers. */
    private static function write(array $file): void
    {
        $line = static fn (mixed $v): string => (string) json_encode($v, self::JSON | JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($file['cases'] as $case) {
            $profiles = array_map(
                static fn (array $p): string => '        {"rows": ' . $line($p['rows']) . ",\n"
                    . '         "answer": ' . $line($p['answer']) . '}',
                $case['profiles']
            );
            $cases[] = "    {\n      \"name\": " . $line($case['name']) . ",\n"
                . "      \"profiles\": [\n" . implode(",\n", $profiles) . "\n      ],\n"
                . '      "union": ' . $line($case['union']) . "\n    }";
        }
        $about = $file['about'] ?? '';
        file_put_contents(
            self::FILE,
            "{\n  \"about\": " . $line($about) . ",\n  \"cases\": [\n" . implode(",\n", $cases) . "\n  ]\n}\n"
        );
    }
}

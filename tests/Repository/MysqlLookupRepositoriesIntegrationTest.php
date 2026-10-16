<?php

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\StoredAddressIds;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Repository\Cache\MemcacheIdCache;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\LookupRepositoryFactory;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;
use RuntimeException;

/**
 * The click path's lookup tables, written and read by the repositories on a
 * real server, under both session modes the click path runs in
 * (connect2.php: '' by default, strict with P202_SQL_STRICT). Under the
 * first a value past its column is cut by the server and an uncut lookup
 * never finds it again; under the second it is refused.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME,
 * whose name must read as a scratch one); it installs the schema there and
 * empties the lookup tables before every test.
 *
 * @group integration
 */
final class MysqlLookupRepositoriesIntegrationTest extends TestCase
{
    private const STRICT = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** tests/live/guard.sh's rule: the tables here are emptied. */
    private const SCRATCH = '/(^|[_-])'
        . '(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)'
        . '([_-]|$)/';

    private const TABLES = [
        '202_keywords', '202_tracking_c1', '202_tracking_c2', '202_tracking_c3', '202_tracking_c4',
        '202_utm_source', '202_utm_medium', '202_utm_campaign', '202_utm_term', '202_utm_content',
        '202_custom_variables', '202_variable_sets', '202_variable_sets2',
        '202_locations_country', '202_locations_city', '202_locations_region', '202_locations_isp',
        '202_ips', '202_ips_v6', '202_site_domains', '202_site_urls',
        '202_browsers', '202_platforms', '202_device_models',
    ];

    /** Every character a hand-built statement had to escape. */
    private const AWKWARD = "it's \"quoted\", back\\slashed, \\' \0 NUL and a\nnewline";

    /** The same characters, short enough for a 50-character column. */
    private const AWKWARD_NAME = "it's \"q\", back\\slash \\' \0 NUL\nnewline";

    private static ?mysqli $db = null;
    private static ?Connection $conn = null;
    private static bool $installed = false;

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
        self::$conn = null;
    }

    protected function setUp(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No scratch database configured (P202_TEST_DB_HOST).');
        }
        $name = (string) getenv('P202_TEST_DB_NAME');
        if (preg_match(self::SCRATCH, $name) !== 1) {
            self::fail('Refusing to empty the lookup tables of "' . $name . '": P202_TEST_DB_NAME must name'
                . ' a scratch database (a name with test, scratch, tmp, ci, … as a word).');
        }
        if (self::$db === null) {
            if (!function_exists('_mysqli_query')) {
                eval('function _mysqli_query($dbOrSql, $sql = null) '
                    . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
            }
            mysqli_report(MYSQLI_REPORT_STRICT);
            self::$db = self::connect();
            self::$conn = new Connection(self::$db);
        }
        if (!self::$installed) {
            self::q("SET SESSION sql_mode = ''");
            (new SchemaInstaller(self::db()))->install();
            self::$installed = true;
        }
        foreach (self::TABLES as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
        LookupRepositoryFactory::reset();
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['permissive' => [''], 'strict' => [self::STRICT]];
    }

    // ── Tracking ────────────────────────────────────────────────────

    /** @dataProvider modes */
    public function testAKeywordIsStoredAsSentAndFoundAgain(string $mode): void
    {
        $repo = $this->tracking($mode);

        $id = $repo->findOrCreateKeyword(self::AWKWARD);
        self::assertGreaterThan(0, $id);
        self::assertSame($id, $repo->findOrCreateKeyword(self::AWKWARD));
        self::assertSame(self::AWKWARD, self::value('SELECT keyword FROM 202_keywords WHERE keyword_id = ' . $id));

        $long = str_repeat('é', 200);
        $longId = $repo->findOrCreateKeyword($long);
        self::assertSame($longId, $repo->findOrCreateKeyword($long), 'a keyword past 150 characters is found again');
        self::assertSame($longId, $repo->findOrCreateKeyword(str_repeat('é', 150)));
        self::assertSame(
            str_repeat('é', 150),
            self::value('SELECT keyword FROM 202_keywords WHERE keyword_id = ' . $longId)
        );

        self::assertSame(0, $repo->findOrCreateKeyword(''), 'no keyword is no row');
        self::assertSame(2, self::rowsIn('202_keywords'));
    }

    /** @return array<string, array{string, string}> */
    public static function cValues(): array
    {
        $cases = [];
        foreach (self::modes() as $label => [$mode]) {
            foreach (['C1', 'C2', 'C3', 'C4'] as $c) {
                $cases[$c . ', ' . $label] = [$c, $mode];
            }
        }

        return $cases;
    }

    /** @dataProvider cValues */
    public function testACValueIsStoredAsSentAndFoundAgain(string $c, string $mode): void
    {
        $repo = $this->tracking($mode);
        $find = static fn (string $v): int => $repo->{'findOrCreate' . $c}($v);
        $table = '202_tracking_' . strtolower($c);
        $column = strtolower($c);

        $id = $find(self::AWKWARD);
        self::assertSame($id, $find(self::AWKWARD));
        self::assertSame(self::AWKWARD, self::value("SELECT $column FROM $table WHERE {$column}_id = $id"));

        // 351 characters: the column keeps 350, the last a two-byte one.
        $long = str_repeat('x', 349) . 'é' . 'y';
        $longId = $find($long);
        self::assertSame($longId, $find($long), 'a value past 350 characters is found again');
        self::assertSame($longId, $find(str_repeat('x', 349) . 'é'));
        self::assertSame(
            str_repeat('x', 349) . 'é',
            self::value("SELECT $column FROM $table WHERE {$column}_id = $longId")
        );
        // 350 four-byte characters fit the column; one fewer is another value.
        $emoji = $find(str_repeat('😀', 350));
        self::assertSame($emoji, $find(str_repeat('😀', 350) . 'x'));
        self::assertNotSame($emoji, $find(str_repeat('😀', 349) . 'x'));
        self::assertSame(str_repeat('😀', 350), self::value("SELECT $column FROM $table WHERE {$column}_id = $emoji"));

        $empty = $find('');
        self::assertGreaterThan(0, $empty, 'an empty value is a row of its own, as the click path stored it');
        self::assertSame($empty, $find(''));
        self::assertSame(5, self::rowsIn($table));
    }

    /** @dataProvider modes */
    public function testAUtmValueIsStoredInItsTypesTable(string $mode): void
    {
        $repo = $this->tracking($mode);

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $type) {
            $id = $repo->findOrCreateUtm(self::AWKWARD, $type);
            self::assertSame($id, $repo->findOrCreateUtm(self::AWKWARD, $type));
            self::assertSame(self::AWKWARD, self::value("SELECT $type FROM 202_$type WHERE {$type}_id = $id"));
            $long = $repo->findOrCreateUtm(str_repeat('u', 400), $type);
            self::assertSame($long, $repo->findOrCreateUtm(str_repeat('u', 400), $type));
            self::assertSame(2, self::rowsIn('202_' . $type));
        }

        $this->expectException(RuntimeException::class);
        $repo->findOrCreateUtm('x', 'utm_nope');
    }

    /** @dataProvider modes */
    public function testAVariableIsKeptPerTrafficSourceVariable(string $mode): void
    {
        $repo = $this->tracking($mode);

        $id = $repo->findOrCreateVariable(self::AWKWARD, 7);
        self::assertSame($id, $repo->findOrCreateVariable(self::AWKWARD, 7));
        self::assertNotSame($id, $repo->findOrCreateVariable(self::AWKWARD, 8));
        self::assertSame(
            self::AWKWARD,
            self::value('SELECT variable FROM 202_custom_variables WHERE custom_variable_id = ' . $id)
        );
        $long = $repo->findOrCreateVariable(str_repeat('v', 349) . '€', 7);
        self::assertSame($long, $repo->findOrCreateVariable(str_repeat('v', 349) . '€', 7));
        self::assertSame(3, self::rowsIn('202_custom_variables'));
    }

    /** @dataProvider modes */
    public function testAVariableSetWritesOneMappingRowPerVariable(string $mode): void
    {
        $repo = $this->tracking($mode);

        $set = $repo->findOrCreateVariableSet('3,1,2');
        self::assertSame($set, $repo->findOrCreateVariableSet('3,1,2'));
        $other = $repo->findOrCreateVariableSet('1,2');
        self::assertNotSame($set, $other);

        self::assertSame(
            [[$set, '1'], [$set, '2'], [$set, '3'], [$other, '1'], [$other, '2']],
            array_map(
                static fn (array $r): array => [(int) $r[0], (string) $r[1]],
                self::rows('SELECT variable_set_id, variables FROM 202_variable_sets2 ORDER BY 1, 2')
            )
        );
        self::assertSame(2, self::rowsIn('202_variable_sets'));

        foreach (['1,,2', '', '1,x', '0', '1, 2', '01,2'] as $malformed) {
            try {
                $repo->findOrCreateVariableSet($malformed);
                self::fail('"' . $malformed . '" was taken as a variable set');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(2, self::rowsIn('202_variable_sets'), 'a refused set writes nothing');
        self::assertSame(5, self::rowsIn('202_variable_sets2'));
    }

    /** @dataProvider modes */
    public function testAVariableSetWhoseMappingIsRefusedIsNotKept(string $mode): void
    {
        $repo = $this->tracking($mode);

        self::q('RENAME TABLE 202_variable_sets2 TO 202_variable_sets2_away');
        try {
            try {
                $repo->findOrCreateVariableSet('4,5');
                self::fail('a set whose mapping rows could not be written was answered');
            } catch (QueryException) {
            }
        } finally {
            self::q('RENAME TABLE 202_variable_sets2_away TO 202_variable_sets2');
        }
        self::assertSame(0, self::rowsIn('202_variable_sets'), 'the set row went with its mappings');

        $set = $repo->findOrCreateVariableSet('4,5');
        self::assertSame(
            [[$set, '4'], [$set, '5']],
            array_map(
                static fn (array $r): array => [(int) $r[0], (string) $r[1]],
                self::rows('SELECT variable_set_id, variables FROM 202_variable_sets2 ORDER BY 1, 2')
            )
        );
    }

    /** @dataProvider modes */
    public function testACustomVariableIsTheTrackingTableItNames(string $mode): void
    {
        $repo = $this->tracking($mode);

        self::assertSame($repo->findOrCreateC2('same'), $repo->findOrCreateCustomVar('c2', 'same'));
        self::assertSame(1, self::rowsIn('202_tracking_c2'));

        // The name is a table and column name. "c2\n" got past a `$` anchor
        // and reached the server as `SELECT c2\n_id …`, a column alias.
        foreach (['c2; DROP TABLE x', "c2\n", 'c2 '] as $name) {
            try {
                $repo->findOrCreateCustomVar($name, 'v');
                self::fail(var_export($name, true) . ' was taken as a custom variable name');
            } catch (RuntimeException $e) {
                self::assertStringStartsWith('Invalid custom variable name', $e->getMessage());
            }
        }
    }

    /** @return array<string, array{int}> */
    public static function reporting(): array
    {
        return [
            'a failed execute answers false' => [MYSQLI_REPORT_STRICT],
            'a failed execute throws' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT],
        ];
    }

    /**
     * Installs upgraded through 1.4.1 hold UNIQUE KEY (c1(191)). Two first
     * clicks with one new value both miss, and the later insert is refused as
     * a duplicate: it reads the earlier click's row, where it was a 500 and
     * no redirect. The read connection holds a snapshot from before that row,
     * as a racing request, or a replica behind the primary, sees the table.
     *
     * @dataProvider reporting
     */
    public function testAFirstClickThatLosesTheRaceForAValueReadsTheWinnersRow(int $reporting): void
    {
        self::q('ALTER TABLE 202_tracking_c1 ADD UNIQUE KEY c1_upgraded (c1(191))');
        $snapshot = self::connect();
        try {
            self::assertTrue($snapshot->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));
            self::q("INSERT INTO 202_tracking_c1 SET c1 = 'launch'");
            $winner = (int) self::value("SELECT c1_id FROM 202_tracking_c1 WHERE c1 = 'launch'");
            $seen = $snapshot->query("SELECT COUNT(*) FROM 202_tracking_c1 WHERE c1 = 'launch'");
            self::assertInstanceOf(\mysqli_result::class, $seen);
            self::assertSame([['0']], array_map(
                static fn (array $r): array => array_map('strval', $r),
                $seen->fetch_all(MYSQLI_NUM)
            ), 'the read connection does not see the other click\'s row');

            mysqli_report($reporting);
            $id = (new MysqlTrackingRepository(new Connection(self::db(), $snapshot)))->findOrCreateC1('launch');
            mysqli_report(MYSQLI_REPORT_STRICT);

            self::assertSame($winner, $id);
            self::assertSame(1, self::rowsIn('202_tracking_c1'));
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $snapshot->close();
            self::q('ALTER TABLE 202_tracking_c1 DROP KEY c1_upgraded');
        }
    }

    /**
     * A lookup that opens a transaction, asked inside one the caller opened
     * with Connection::transaction(), refuses before it begins: a nested
     * begin would have committed the caller's write, and the caller's
     * rollback undone nothing.
     */
    public function testALookupThatOpensATransactionRefusesInsideAnother(): void
    {
        self::q('CREATE TEMPORARY TABLE p202_caller_write (n INT) ENGINE=InnoDB');
        $lookups = [
            'an IPv6 address' => fn (): int => $this->location('')->findOrCreateIp('2001:db8::7b'),
            'a variable set' => fn (): int => $this->tracking('')->findOrCreateVariableSet('4,5'),
        ];
        foreach ($lookups as $what => $lookup) {
            try {
                self::conn()->transaction(static function () use ($lookup): void {
                    self::q('INSERT INTO p202_caller_write SET n = 1');
                    $lookup();
                });
                self::fail("$what was looked up inside the caller's transaction");
            } catch (\LogicException $e) {
                self::assertStringContainsString('inside another', $e->getMessage(), $what);
            }
            self::assertSame(0, self::rowsIn('p202_caller_write'), "$what: the caller's write was committed");
        }
        self::assertSame(0, self::rowsIn('202_ips_v6'));
        self::assertSame(0, self::rowsIn('202_variable_sets'));

        self::assertGreaterThan(0, $lookups['an IPv6 address'](), 'outside any transaction it runs');
        self::assertGreaterThan(0, $lookups['a variable set']());
    }

    /**
     * The database error page rolls back its caller's transaction before it
     * records, so its own lookups run outside any: an IPv6 visitor's address
     * is still stored when the failure happened inside
     * Connection::transaction(). Its own process: the recorder runs once.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheErrorPagesLookupsRunAfterItEndedTheCallersTransaction(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/mysql-error-args.php';
        self::q('CREATE TEMPORARY TABLE p202_caller_write (n INT) ENGINE=InnoDB');
        $db = self::db();
        $id = 0;
        try {
            self::conn()->transaction(static function () use ($db, &$id): void {
                self::q('INSERT INTO p202_caller_write SET n = 1');
                $recorded = p202RecordMysqlErrorRow($db, static function () use ($db, &$id): void {
                    $id = (new MysqlLocationRepository(new Connection($db)))->findOrCreateIp('2001:db8::7c');
                });
                self::assertTrue($recorded, 'the record\'s lookup ran');
                throw new \DomainException('the page ends the request here');
            });
        } catch (\DomainException) {
        }

        self::assertGreaterThan(0, $id);
        self::assertSame(1, self::rowsIn('202_ips_v6'));
        self::assertSame(0, self::rowsIn('p202_caller_write'), 'the page rolled the caller\'s write back');
    }

    /** A value only the key's 191-character prefix refuses is found by no select: the refusal stands. */
    public function testAValueOnlyTheKeysPrefixRefusesIsStillRefused(): void
    {
        self::q('ALTER TABLE 202_tracking_c1 ADD UNIQUE KEY c1_upgraded (c1(191))');
        try {
            $repo = $this->tracking('');
            $repo->findOrCreateC1(str_repeat('p', 191) . '1');
            try {
                $repo->findOrCreateC1(str_repeat('p', 191) . '2');
                self::fail('a value the unique key refused was answered');
            } catch (QueryException $e) {
                self::assertStringContainsString('[errno 1062]', $e->getMessage());
            }
            self::assertSame(1, self::rowsIn('202_tracking_c1'));
        } finally {
            self::q('ALTER TABLE 202_tracking_c1 DROP KEY c1_upgraded');
        }
    }

    // ── Location ────────────────────────────────────────────────────

    /** @dataProvider modes */
    public function testACountryIsFoundByItsCode(string $mode): void
    {
        $repo = $this->location($mode);

        $id = $repo->findOrCreateCountry("Côte d'Ivoire", 'CI');
        self::assertSame($id, $repo->findOrCreateCountry('Ivory Coast', 'CI'));
        self::assertSame(
            "Côte d'Ivoire",
            self::value('SELECT country_name FROM 202_locations_country WHERE country_id = ' . $id)
        );

        $long = $repo->findOrCreateCountry(str_repeat('n', 60), 'USAX');
        self::assertSame(
            $long,
            $repo->findOrCreateCountry(str_repeat('n', 60), 'USAX'),
            'a code past 3 characters is found again'
        );
        self::assertSame(
            ['USA', str_repeat('n', 50)],
            array_map('strval', self::rows(
                'SELECT country_code, country_name FROM 202_locations_country WHERE country_id = ' . $long
            )[0])
        );
        self::assertSame(2, self::rowsIn('202_locations_country'));
    }

    /** @dataProvider modes */
    public function testCityRegionAndIspNamesPastTheirColumnAreFoundAgain(string $mode): void
    {
        $repo = $this->location($mode);
        $country = $repo->findOrCreateCountry('Germany', 'DE');
        $otherCountry = $repo->findOrCreateCountry('Austria', 'AT');
        $long = str_repeat('é', 49) . '€x';

        foreach (
            [
                ['202_locations_city', 'city_name', 'city_id', $repo->findOrCreateCity(...)],
                ['202_locations_region', 'region_name', 'region_id', $repo->findOrCreateRegion(...)],
            ] as [$table, $column, $idColumn, $find]
        ) {
            $id = $find(self::AWKWARD_NAME, $country);
            self::assertSame($id, $find(self::AWKWARD_NAME, $country));
            self::assertNotSame($id, $find(self::AWKWARD_NAME, $otherCountry));
            self::assertSame(self::AWKWARD_NAME, self::value("SELECT $column FROM $table WHERE $idColumn = $id"));
            $longId = $find($long, $country);
            self::assertSame($longId, $find($long, $country), $column . ' past 50 characters is found again');
            self::assertSame(
                str_repeat('é', 49) . '€',
                self::value("SELECT $column FROM $table WHERE $idColumn = $longId")
            );
            self::assertSame(3, self::rowsIn($table));
        }

        $isp = $repo->findOrCreateIsp(self::AWKWARD_NAME);
        self::assertSame($isp, $repo->findOrCreateIsp(self::AWKWARD_NAME));
        $longIsp = $repo->findOrCreateIsp($long);
        self::assertSame($longIsp, $repo->findOrCreateIsp($long));
        self::assertSame(2, self::rowsIn('202_locations_isp'));
    }

    /** @dataProvider modes */
    public function testAnIpv4AddressIsARowByItsText(string $mode): void
    {
        $repo = $this->location($mode);

        $id = $repo->findOrCreateIp('203.0.113.9');
        self::assertSame($id, $repo->findOrCreateIp('203.0.113.9'));
        self::assertSame('203.0.113.9', self::value('SELECT ip_address FROM 202_ips WHERE ip_id = ' . $id));
        self::assertGreaterThan(0, $repo->findOrCreateIp('0.0.0.0'), 'AUTH::client_ip()\'s stand-in for none');
        self::assertSame(0, $repo->findOrCreateIp(''));
        self::assertSame([$id], StoredAddressIds::of(self::conn(), '203.0.113.9'));

        foreach (['12345', 'not an address', '203.0.113.9 ', '203.0.113.256'] as $notAnAddress) {
            try {
                $repo->findOrCreateIp($notAnAddress);
                self::fail('"' . $notAnAddress . '" was stored as an address');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(2, self::rowsIn('202_ips'));
    }

    /**
     * Packed, these hold a double quote (::22), a backslash (::5c), a
     * backslash then a letter (::5c41) and trailing NULs (2001:db8::), the
     * bytes the hand-escaped statement got wrong.
     *
     * @dataProvider modes
     */
    public function testAnIpv6AddressIsStoredOnceAndFoundAgain(string $mode): void
    {
        $repo = $this->location($mode);
        $addresses = ['2001:db8::22', '2001:db8::5c', '2001:db8::5c41', '2001:db8::', '::1', '2001:db8::27:ff00'];

        $ids = [];
        foreach ($addresses as $address) {
            $ids[$address] = $repo->findOrCreateIp($address);
        }
        foreach ($addresses as $address) {
            self::assertSame($ids[$address], $repo->findOrCreateIp($address), $address . ' is found again');
            self::assertSame([$ids[$address]], StoredAddressIds::of(self::conn(), $address));
            $v6 = self::value('SELECT 202_ips_v6.ip_id FROM 202_ips_v6 WHERE ip_address = UNHEX(\''
                . bin2hex((string) inet_pton($address)) . '\')');
            self::assertSame($v6, self::value('SELECT ip_address FROM 202_ips WHERE ip_id = ' . $ids[$address]));
        }
        self::assertCount(count($addresses), array_unique($ids));
        self::assertSame(count($addresses), self::rowsIn('202_ips_v6'));
        self::assertSame(count($addresses), self::rowsIn('202_ips'));
    }

    /**
     * An IPv6 address's 202_ips row holds its 202_ips_v6 id in decimal. The
     * join compared that id with every 202_ips.ip_address as a number, and
     * '1.0.0.5' is 1: once 1.0.0.5 was stored, the address whose v6 row is
     * 1 was answered with 1.0.0.5's row (the newer of the two).
     *
     * @dataProvider modes
     */
    public function testAnIpv4RowIsNeverAnIpv6Address(string $mode): void
    {
        $repo = $this->location($mode);

        $v6 = $repo->findOrCreateIp('2001:db8::1');
        self::assertSame('1', self::value('SELECT ip_address FROM 202_ips WHERE ip_id = ' . $v6), 'its v6 row is 1');
        $v4 = $repo->findOrCreateIp('1.0.0.5');

        self::assertSame($v6, $repo->findOrCreateIp('2001:db8::1'));
        self::assertSame([$v6], StoredAddressIds::of(self::conn(), '2001:db8::1'));
        self::assertSame([$v4], StoredAddressIds::of(self::conn(), '1.0.0.5'));
        self::assertSame(2, self::rowsIn('202_ips'));
    }

    /** @dataProvider modes */
    public function testAnIpv6AddressWhoseAddressRowIsRefusedIsNotKept(string $mode): void
    {
        $repo = $this->location($mode);

        // This session's own 202_ips, with no id of its own to give: the
        // address row's INSERT is refused (strict) or makes no row id.
        self::q('CREATE TEMPORARY TABLE 202_ips'
            . ' (ip_id INT UNSIGNED NOT NULL, ip_address VARCHAR(45) NOT NULL, location_id INT NOT NULL DEFAULT 0)');
        try {
            try {
                $repo->findOrCreateIp('2001:db8::77');
                self::fail('an IPv6 address whose 202_ips row could not be written was answered');
            } catch (QueryException) {
            }
        } finally {
            self::q('DROP TEMPORARY TABLE 202_ips');
        }
        self::assertSame(0, self::rowsIn('202_ips_v6'), 'the 202_ips_v6 row went with its address row');

        $id = $repo->findOrCreateIp('2001:db8::77');
        self::assertSame($id, $repo->findOrCreateIp('2001:db8::77'));
        self::assertSame(1, self::rowsIn('202_ips_v6'));
    }

    /**
     * An install upgraded through the 1.9.50 → 1.9.51 rung has the 202_ips_v6
     * that rung made (functions-upgrade.php), whose location_id has no
     * default: under a strict sql_mode a row that leaves it out is refused
     * (1364), and every new IPv6 visitor's click failed with it.
     */
    public function testAnIpv6AddressIsStoredInTheTableTheUpgradeMade(): void
    {
        // This session's own 202_ips_v6, shadowing the installed one.
        self::q('CREATE TEMPORARY TABLE `202_ips_v6` ('
            . ' `ip_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,'
            . " `ip_address` varbinary(16) NOT NULL DEFAULT '',"
            . ' `location_id` mediumint(8) unsigned NOT NULL,'
            . ' PRIMARY KEY (`ip_id`), KEY `ip_address` (`ip_address`), KEY `location_id` (`location_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        try {
            $repo = $this->location(self::STRICT);
            self::assertFalse(
                self::db()->query("INSERT INTO 202_ips_v6 SET ip_address = 'probe'"),
                'the table under test takes a row with no location_id'
            );
            self::assertSame(1364, self::db()->errno);

            $id = $repo->findOrCreateIp('2001:db8::7');

            self::assertSame($id, $repo->findOrCreateIp('2001:db8::7'));
            self::assertSame([$id], StoredAddressIds::of(self::conn(), '2001:db8::7'));
            self::assertSame('0', self::value('SELECT location_id FROM 202_ips_v6'));
            self::assertSame('0', self::value('SELECT location_id FROM 202_ips WHERE ip_id = ' . $id));
        } finally {
            self::q('DROP TEMPORARY TABLE `202_ips_v6`');
        }
    }

    /** @dataProvider modes */
    public function testASiteUrlIsFiledUnderItsDomain(string $mode): void
    {
        $repo = $this->location($mode);

        $url = 'https://www.example.com/a?b=1&c=' . rawurlencode(self::AWKWARD) . "&d='\"";
        $id = $repo->findOrCreateSiteUrl($url);
        self::assertSame($id, $repo->findOrCreateSiteUrl($url));
        $domain = $repo->findOrCreateSiteDomain($url);
        self::assertSame(
            'example.com',
            self::value('SELECT site_domain_host FROM 202_site_domains WHERE site_domain_id = ' . $domain)
        );
        self::assertSame(
            [$domain, $url],
            [(int) self::rows('SELECT site_domain_id FROM 202_site_urls WHERE site_url_id = ' . $id)[0][0],
                self::value('SELECT site_url_address FROM 202_site_urls WHERE site_url_id = ' . $id)]
        );
        $sibling = $repo->findOrCreateSiteUrl('http://example.com/other');
        self::assertNotSame($id, $sibling);
        self::assertSame($domain, $repo->findOrCreateSiteDomain('http://example.com/other'));

        // No host: filed under domain 0, and found again there.
        $hostless = $repo->findOrCreateSiteUrl('/just/a/path');
        self::assertSame($hostless, $repo->findOrCreateSiteUrl('/just/a/path'));
        self::assertSame('0', self::value('SELECT site_domain_id FROM 202_site_urls WHERE site_url_id = ' . $hostless));
        self::assertSame(0, $repo->findOrCreateSiteDomain('/just/a/path'));

        self::assertSame(0, $repo->findOrCreateSiteUrl(''));
        self::assertSame(0, $repo->findOrCreateSiteDomain(''));
        self::assertSame(3, self::rowsIn('202_site_urls'));
        self::assertSame(1, self::rowsIn('202_site_domains'));
    }

    /** @dataProvider modes */
    public function testADomainPastItsColumnIsFoundAgain(string $mode): void
    {
        $repo = $this->location($mode);
        $host = str_repeat('a', 120) . '.example';

        $domain = $repo->findOrCreateSiteDomain('https://' . $host . '/x');
        self::assertSame($domain, $repo->findOrCreateSiteDomain('https://' . $host . '/y'));
        $url = $repo->findOrCreateSiteUrl('https://' . $host . '/x');
        self::assertSame($url, $repo->findOrCreateSiteUrl('https://' . $host . '/x'));
        self::assertSame(str_repeat('a', 100), self::value('SELECT site_domain_host FROM 202_site_domains'));
        self::assertSame(1, self::rowsIn('202_site_domains'));
        self::assertSame(1, self::rowsIn('202_site_urls'));
    }

    /**
     * site_url_address is a TEXT: 65535 bytes.
     *
     * @dataProvider modes
     */
    public function testASiteUrlPastItsColumnIsFoundAgain(string $mode): void
    {
        $repo = $this->location($mode);
        $url = 'https://example.com/?q=' . str_repeat('q', 65511) . 'é';

        $id = $repo->findOrCreateSiteUrl($url);
        self::assertSame($id, $repo->findOrCreateSiteUrl($url));
        self::assertSame(
            substr($url, 0, 65534),
            self::value('SELECT site_url_address FROM 202_site_urls WHERE site_url_id = ' . $id),
            'cut to 65535 bytes, less the character the cut splits'
        );
        self::assertSame(1, self::rowsIn('202_site_urls'));
    }

    // ── Device ──────────────────────────────────────────────────────

    /** @dataProvider modes */
    public function testDeviceNamesAreStoredAsSentAndFoundAgain(string $mode): void
    {
        $repo = new MysqlDeviceRepository($this->connection($mode));
        $long = str_repeat('d', 49) . '😀x';

        foreach (
            [
                ['202_browsers', 'browser_name', 'browser_id', $repo->findOrCreateBrowser(...)],
                ['202_platforms', 'platform_name', 'platform_id', $repo->findOrCreatePlatform(...)],
                [
                    '202_device_models', 'device_name', 'device_id',
                    static fn (string $name): int => $repo->findOrCreateDevice($name, DeviceType::Tablet),
                ],
            ] as [$table, $column, $idColumn, $find]
        ) {
            $id = $find(self::AWKWARD_NAME);
            self::assertSame($id, $find(self::AWKWARD_NAME));
            self::assertSame(self::AWKWARD_NAME, self::value("SELECT $column FROM $table WHERE $idColumn = $id"));
            $longId = $find($long);
            self::assertSame($longId, $find($long), $column . ' past 50 characters is found again');
            self::assertSame(
                str_repeat('d', 49) . '😀',
                self::value("SELECT $column FROM $table WHERE $idColumn = $longId")
            );
            self::assertSame(0, $find(''));
            self::assertSame(2, self::rowsIn($table));
        }
        self::assertSame([['3'], ['3']], self::rows('SELECT device_type FROM 202_device_models'));
    }

    /**
     * A device model is its name and its type: Chrome on Android names every
     * phone and every tablet "K". Rows written before 1.9.78 hold one type
     * per name (the model was found by its name alone), and each is found by
     * the type it holds.
     *
     * @dataProvider modes
     */
    public function testADeviceModelIsOneRowPerType(string $mode): void
    {
        $repo = new MysqlDeviceRepository($this->connection($mode));
        self::q("INSERT INTO 202_device_models SET device_name = 'K', device_type = 2");
        $existing = (int) self::db()->insert_id;

        self::assertSame($existing, $repo->findOrCreateDevice('K', DeviceType::Mobile));
        $tablet = $repo->findOrCreateDevice('K', DeviceType::Tablet);
        self::assertNotSame($existing, $tablet);
        self::assertSame($tablet, $repo->findOrCreateDevice('K', DeviceType::Tablet));

        $ids = [];
        foreach (DeviceType::cases() as $type) {
            $ids[] = $repo->findOrCreateDevice('Each', $type);
        }
        self::assertSame($ids, array_values(array_unique($ids)));

        $long = str_repeat('Model', 16);
        $phone = $repo->findOrCreateDevice($long, DeviceType::Mobile);
        self::assertSame($phone, $repo->findOrCreateDevice($long, DeviceType::Mobile));
        $longTablet = $repo->findOrCreateDevice($long, DeviceType::Tablet);
        self::assertSame($longTablet, $repo->findOrCreateDevice($long, DeviceType::Tablet));

        $cut = substr($long, 0, 50);
        self::assertSame(
            [
                ['K', '2'], ['K', '3'],
                ['Each', '1'], ['Each', '2'], ['Each', '3'], ['Each', '4'],
                [$cut, '2'], [$cut, '3'],
            ],
            self::rows('SELECT device_name, device_type FROM 202_device_models ORDER BY device_id')
        );
    }

    // ── Cached over MySQL ───────────────────────────────────────────

    public function testTheSecondLookupIsAnsweredByTheCache(): void
    {
        $cache = new ArrayIdCache();
        $tracking = new CachedTrackingRepository($this->tracking(''), $cache, 'ns');
        $location = new CachedLocationRepository($this->location(''), $cache, 'ns');

        $c1 = $tracking->findOrCreateC1('cached');
        $ip = $location->findOrCreateIp('2001:db8::9');
        self::q('TRUNCATE TABLE 202_tracking_c1');
        self::q('TRUNCATE TABLE 202_ips');

        self::assertSame($c1, $tracking->findOrCreateC1('cached'));
        self::assertSame($ip, $location->findOrCreateIp('2001:db8::9'));
        self::assertSame(0, self::rowsIn('202_tracking_c1'), 'the database was not asked');
        self::assertSame(0, self::rowsIn('202_ips'));
        self::assertSame([md5('c1-idcachedns'), md5('ip6-id2001:db8::9ns')], $cache->writtenKeys());
    }

    public function testACachedDeviceModelAnswersOnlyForItsType(): void
    {
        $cache = new ArrayIdCache();
        $devices = new CachedDeviceRepository(new MysqlDeviceRepository($this->connection('')), $cache, 'ns');

        $phone = $devices->findOrCreateDevice('K', DeviceType::Mobile);
        $tablet = $devices->findOrCreateDevice('K', DeviceType::Tablet);
        self::q('TRUNCATE TABLE 202_device_models');

        self::assertNotSame($phone, $tablet);
        self::assertSame($phone, $devices->findOrCreateDevice('K', DeviceType::Mobile));
        self::assertSame($tablet, $devices->findOrCreateDevice('K', DeviceType::Tablet));
        self::assertSame(0, self::rowsIn('202_device_models'), 'the database was not asked');
        self::assertSame([md5('device-idK|2ns'), md5('device-idK|3ns')], $cache->writtenKeys());
    }

    public function testAnEntryThatIsNotAnIdIsLookedUpInTheDatabase(): void
    {
        $key = md5('c1-id' . 'v' . 'ns');
        foreach ([0, '0', '', 'junk', '-4', false] as $entry) {
            self::q('TRUNCATE TABLE 202_tracking_c1');
            $store = [$key => $entry];
            $cache = new MemcacheIdCache(
                static function (string $k) use (&$store): mixed {
                    return $store[$k] ?? false;
                },
                static function (string $k, int $id, int $ttl) use (&$store): bool {
                    $store[$k] = $id;
                    return true;
                },
            );
            $repo = new CachedTrackingRepository($this->tracking(''), $cache, 'ns');

            $id = $repo->findOrCreateC1('v');

            self::assertGreaterThan(0, $id, var_export($entry, true) . ' was answered as the id');
            self::assertSame($id, (int) self::value("SELECT c1_id FROM 202_tracking_c1 WHERE c1 = 'v'"));
            self::assertSame($id, $store[$key], 'the found id replaced ' . var_export($entry, true));
        }
    }

    public function testNoValueIsNeverCached(): void
    {
        $cache = new ArrayIdCache();
        $location = new CachedLocationRepository($this->location(''), $cache, 'ns');
        $tracking = new CachedTrackingRepository($this->tracking(''), $cache, 'ns');

        self::assertSame(0, $location->findOrCreateIp(''));
        self::assertSame(0, $location->findOrCreateSiteUrl(''));
        self::assertSame(0, $location->findOrCreateSiteDomain('/no/host'));
        self::assertSame(0, $tracking->findOrCreateKeyword(''));
        self::assertSame([], $cache->writes);
    }

    /**
     * The factory's cache, on the click path, is getCache()/setCache()
     * (functions-shared.php): a miss reads the database and writes its id
     * through setCache() for 30 days, and the next lookup is answered from it.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheFactoryCachesThroughTheBootstrapHelpers(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/tests/Config/fixtures/lift-function.php';
        define('P202_CLICK_PATH', true);
        eval(lift_function($root . '/202-config/functions-shared.php', 'p202OnTheClickPath'));
        // The real getCache() (functions-shared.php), which asks only a
        // Memcache or a Memcached: one that answers an id as the string
        // memcache hands back.
        eval(lift_function($root . '/202-config/functions-shared.php', 'getCache'));
        $GLOBALS['memcache'] = new class () extends \Memcached {
            public function get(string $key, ?callable $cache_cb = null, int $get_flags = 0): mixed
            {
                $value = $GLOBALS['p202TestCache'][$key] ?? false;

                return is_int($value) ? (string) $value : $value;
            }
        };
        eval('function setCache($key, $value, $exp = null) {'
            . ' $GLOBALS["p202TestCache"][$key] = $value;'
            . ' $GLOBALS["p202TestCacheWrites"][] = [$key, $value, $exp]; return true; }');
        eval('function systemHash(): string { return "install-a"; }');
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['p202TestCache'] = [];
        $GLOBALS['p202TestCacheWrites'] = [];
        self::q("SET SESSION sql_mode = ''");

        $tracking = LookupRepositoryFactory::tracking(self::db());
        $id = $tracking->findOrCreateC1('through the factory');
        self::q('TRUNCATE TABLE 202_tracking_c1');

        self::assertSame($id, $tracking->findOrCreateC1('through the factory'));
        self::assertSame(0, self::rowsIn('202_tracking_c1'), 'the second lookup was the cache\'s');
        self::assertSame(
            [[md5('c1-id' . 'through the factory' . 'install-a'), $id, 2592000]],
            $GLOBALS['p202TestCacheWrites']
        );
    }

    /**
     * connect2.php's PLATFORMS takes its ids from the factory's repository,
     * on the click path's own connection: a phone and a tablet that name one
     * model are each their type, and a model name past its column is one
     * row, where it was a new row on every click.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPlatformsStoresEachClicksModelThroughTheFactory(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/DeviceDetect.php';
        require_once $root . '/tests/Config/fixtures/lift-function.php';
        eval('use UAParser\Parser; ' . lift_class($root . '/202-config/connect2.php', 'PLATFORMS'));
        self::q("SET SESSION sql_mode = ''");
        $agent = static fn (string $model, string $mobile): string => 'Mozilla/5.0 (Linux; Android 10; ' . $model
            . ') AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 ' . $mobile . 'Safari/537.36';
        $long = str_repeat('Model', 16);

        $parsed = [];
        foreach (
            [$agent('K', 'Mobile '), $agent('K', ''), $agent('K', 'Mobile '), $agent($long . ' Build/TP1A', 'Mobile '),
                $agent($long . ' Build/TP1A', 'Mobile ')] as $ua
        ) {
            $info = \PLATFORMS::parseUserAgentInfo(self::db(), (new \DeviceDetect())->setUserAgent($ua));
            $parsed[] = [$info['browser'], $info['platform'], $info['device'], $info['type']];
        }

        self::assertSame([
            [1, 1, 1, '2'],
            [2, 1, 2, '3'],
            [1, 1, 1, '2'],
            [1, 1, 3, '2'],
            [1, 1, 3, '2'],
        ], $parsed);
        self::assertSame(
            [['1', 'K', '2'], ['2', 'K', '3'], ['3', substr($long, 0, 50), '2']],
            self::rows('SELECT device_id, device_name, device_type FROM 202_device_models ORDER BY device_id')
        );
        self::assertSame(
            [['1', 'Chrome Mobile'], ['2', 'Chrome']],
            self::rows('SELECT browser_id, browser_name FROM 202_browsers ORDER BY browser_id')
        );
        self::assertSame([['1', 'Android']], self::rows('SELECT platform_id, platform_name FROM 202_platforms'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function connection(string $mode): Connection
    {
        self::q("SET SESSION sql_mode = '" . $mode . "'");

        return self::conn();
    }

    private function tracking(string $mode): MysqlTrackingRepository
    {
        return new MysqlTrackingRepository($this->connection($mode));
    }

    private function location(string $mode): MysqlLocationRepository
    {
        return new MysqlLocationRepository($this->connection($mode));
    }

    private static function connect(): mysqli
    {
        $db = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) getenv('P202_TEST_DB_NAME'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(mysqli::class, $db);

        return $db;
    }

    private static function db(): mysqli
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }

    private static function conn(): Connection
    {
        self::assertNotNull(self::$conn);

        return self::$conn;
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::db()->query($sql), self::db()->error . ' in ' . $sql);
    }

    /** @return list<list<mixed>> */
    private static function rows(string $sql): array
    {
        $result = self::db()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::db()->error . ' in ' . $sql);

        return array_values($result->fetch_all(MYSQLI_NUM));
    }

    private static function value(string $sql): ?string
    {
        $rows = self::rows($sql);
        self::assertCount(1, $rows, $sql);
        $value = $rows[0][0];

        return $value === null ? null : (string) $value;
    }

    private static function rowsIn(string $table): int
    {
        return (int) self::value('SELECT COUNT(*) FROM ' . $table);
    }
}

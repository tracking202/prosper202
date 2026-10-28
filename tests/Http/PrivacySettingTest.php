<?php

declare(strict_types=1);

namespace Tests\Http;

use Prosper202\Database\Connection;
use Prosper202\Http\PrivacySetting;
use Prosper202\Http\StoredVisitorIp;
use Tests\Apps\Apple\CapturingMysqli;
use Tests\TestCase;

/**
 * The privacy setting the app intakes apply, and the address they store
 * under it (StoredVisitorIp::forAccount()): the strictest of the install's
 * setting (user 1's, the one the click path reads) and the owning
 * account's; an unreadable one holds back (CLAUDE.md #11).
 */
final class PrivacySettingTest extends TestCase
{
    use CapturingMysqli;

    private string $log = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-privacy-log');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
        parent::tearDown();
    }

    /**
     * @param list<array{user_id: int, user_pref_privacy: mixed}> $rows
     */
    private function conn(array $rows): Connection
    {
        return new Connection($this->capturingDb(['FROM 202_users_pref' => $rows]));
    }

    /** @return iterable<string, array{list<array{user_id: int, user_pref_privacy: mixed}>, ?int, string}> */
    public static function settings(): iterable
    {
        $row = static fn (int $user, mixed $setting): array => ['user_id' => $user, 'user_pref_privacy' => $setting];
        yield 'the install disabled, no owner' => [[$row(1, 'disabled')], null, 'disabled'];
        yield 'the install all, no owner' => [[$row(1, 'all')], null, 'all'];
        yield 'the owner holds back where the install does not' => [[$row(1, 'disabled'), $row(2, 'all')], 2, 'all'];
        yield 'the install holds back where the owner does not' => [[$row(1, 'eu'), $row(2, 'disabled')], 2, 'eu'];
        yield 'all beats eu' => [[$row(1, 'eu'), $row(2, 'all')], 2, 'all'];
        yield 'no preferences row: the column default' => [[], 2, 'disabled'];
        yield 'a stored value that is not a setting holds back' => [[$row(1, 'disabled'), $row(2, 'EU')], 2, 'all'];
        yield 'an empty stored value holds back' => [[$row(1, '')], null, 'all'];
        yield 'a NULL stored value holds back' => [[$row(1, null)], null, 'all'];
    }

    /**
     * @dataProvider settings
     * @param list<array{user_id: int, user_pref_privacy: mixed}> $rows
     */
    public function testTheStrictestReadableSettingGoverns(array $rows, ?int $owner, string $expected): void
    {
        self::assertSame($expected, PrivacySetting::forAccount($this->conn($rows), $owner));
    }

    public function testItAsksForTheInstallAndTheOwnerOnly(): void
    {
        PrivacySetting::forAccount($this->conn([]), 7);
        self::assertSame([1, 7], $this->captured[0]['values']);
        $this->captured = [];
        PrivacySetting::forAccount($this->conn([]), 1);
        self::assertSame([1], $this->captured[0]['values'], 'the install\'s own account is asked once');
        $this->captured = [];
        PrivacySetting::forAccount($this->conn([]), null);
        self::assertSame([1], $this->captured[0]['values'], 'no owner: the install\'s setting alone');
    }

    public function testASettingThatCannotBeReadHoldsBackAndSaysSo(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('prepare')->willReturn(false);

        self::assertSame('all', PrivacySetting::forAccount(new Connection($db), 2));
        self::assertStringContainsString('user_pref_privacy could not be read', (string) file_get_contents($this->log));
    }

    public function testACorruptRowIsNamedByItsUser(): void
    {
        PrivacySetting::forAccount($this->conn([['user_id' => 9, 'user_pref_privacy' => 'sometimes']]), 9);
        self::assertStringContainsString(
            "user 9's user_pref_privacy is 'sometimes'",
            (string) file_get_contents($this->log)
        );
    }

    /** @return iterable<string, array{string, bool, string, string}> setting, may be European, address, stored */
    public static function stored(): iterable
    {
        yield 'disabled: as it arrived' => ['disabled', true, '203.0.113.77', '203.0.113.77'];
        yield 'all: masked' => ['all', false, '203.0.113.77', '203.0.113.0'];
        yield 'all: IPv6 masked to /48' => ['all', false, '2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'];
        yield 'eu, possibly European: masked' => ['eu', true, '203.0.113.77', '203.0.113.0'];
        yield 'eu, outside Europe: as it arrived' => ['eu', false, '203.0.113.77', '203.0.113.77'];
        yield 'canonical form' => ['disabled', true, '2001:DB8:0::1', '2001:db8::1'];
        yield 'not an address' => ['disabled', true, 'unknown', ''];
        yield 'a forwarding chain is not one address' => ['disabled', true, '203.0.113.77, 10.0.0.1', ''];
        yield 'nothing' => ['all', true, '', ''];
    }

    /** @dataProvider stored */
    public function testTheAddressAnIntakeStores(
        string $setting,
        bool $european,
        string $address,
        string $expected
    ): void {
        $asked = [];
        $mayBeEuropean = static function (string $a) use ($european, &$asked): bool {
            $asked[] = $a;

            return $european;
        };
        $conn = $this->conn([['user_id' => 1, 'user_pref_privacy' => $setting]]);

        self::assertSame($expected, StoredVisitorIp::forAccount($conn, $address, 1, $mayBeEuropean));
        if ($setting === 'eu' && $expected !== '') {
            self::assertSame([$address], $asked, 'the GeoIP question is asked of the address itself');
        } else {
            self::assertSame([], $asked, 'GeoIP is asked only under eu');
        }
    }
}

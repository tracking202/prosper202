<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;

/**
 * An install row keeps the device's address as the click path keeps a
 * visitor's: masked (/24, /48) when the install's privacy setting (user
 * 1's, the one the click path reads) or the owning account's holds back
 * for it, as it arrived otherwise. The intake stored it as it arrived
 * whatever the setting said.
 *
 * @group integration
 */
final class InstallStoresMaskedAddressIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private androidSetUp;
    }

    private const ORGANIC = 'utm_source=google-play&utm_medium=organic';

    protected function setUp(): void
    {
        $this->androidSetUp();
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
    }

    private function privacy(int $userId, string $setting): void
    {
        self::fixture("REPLACE INTO 202_users_pref SET user_id=$userId, user_pref_privacy='$setting'");
    }

    private function storedAddress(string $uuid): string
    {
        $row = self::$db->query("SELECT remote_ip FROM 202_app_installs WHERE install_uuid = '$uuid'")->fetch_assoc();
        self::assertIsArray($row, 'the install was stored');

        return (string) $row['remote_ip'];
    }

    /** @return iterable<string, array{string, string, string, string}> install's, owner's, token, stored */
    public static function settings(): iterable
    {
        yield 'privacy off' => ['disabled', 'disabled', 'summit', '203.0.113.9'];
        yield 'the install holds back for all' => ['all', 'disabled', 'summit', '203.0.113.0'];
        yield 'the install holds back for all, another account\'s app' => ['all', 'disabled', 'other', '203.0.113.0'];
        yield 'the owning account holds back for all' => ['disabled', 'all', 'other', '203.0.113.0'];
        yield 'eu: an address GeoIP cannot place is held back' => ['eu', 'disabled', 'summit', '203.0.113.0'];
    }

    /** @dataProvider settings */
    public function testTheDevicesAddressIsStoredUnderThePrivacySetting(
        string $install,
        string $owner,
        string $app,
        string $stored
    ): void {
        $this->privacy(1, $install);
        $this->privacy(2, $owner);
        $uuid = '00000000-0000-4000-8000-0000000000a' . ($app === 'summit' ? '1' : '2');
        $body = $app === 'summit'
            ? self::body($uuid, self::ORGANIC)
            : self::body($uuid, self::ORGANIC, ['app_key' => 'com.other.app']);

        $answer = $this->install($body, $app === 'summit' ? self::TOKEN : self::OTHER_TOKEN);

        self::assertSame(200, $answer['status'], (string) json_encode($answer['body']));
        self::assertSame($stored, $this->storedAddress($uuid));
    }
}

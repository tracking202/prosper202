<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppInstallsController;
use PHPUnit\Framework\TestCase;

/**
 * A click of this account whose campaign is another account's (a tracker
 * could name one before the API checked linked ids, 229df10) reads as a
 * click with no campaign on the install paths: that campaign's app link
 * neither refuses this account's install nor names the other account's
 * registration, and its payout never pays this account's install goal.
 *
 * @group integration
 */
final class ForeignCampaignIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-0000000000f1';
    private const U2 = '00000000-0000-4000-8000-0000000000f2';

    /** @return array<string, mixed>|null */
    private static function installRow(string $uuid): ?array
    {
        $stmt = self::$db->prepare('SELECT * FROM 202_app_installs WHERE install_uuid = ?');
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function testAnotherAccountsCampaignLinkDoesNotRefuseTheInstall(): void
    {
        // Account 2's campaign 31, linked to account 2's app (registration
        // 6); account 1's click 100 names it.
        $this->campaign(31, 6, 'accumulate', '1.00', 2);
        $this->click(100, 31);

        $token = (new AppInstallsController(self::$db, 1))->installToken(5, ['click_id' => '100']);
        self::assertSame(100, $token['data']['click_id'], "another account's campaign link refused the token");

        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame('attributed', $r['body']['data']['match'], (string) json_encode($r));
        $row = self::installRow(self::U1);
        self::assertNotNull($row);
        self::assertStringNotContainsString('registration 6', (string) $row['match_reason'], "another account's registration is not named");
    }

    public function testAnotherAccountsCampaignDoesNotPayTheInstall(): void
    {
        // Account 2's campaign 32 links no app and pays 7.77 by default;
        // account 1's click 101 names it.
        $this->campaign(32, null, 'accumulate', '7.77', 2);
        $this->click(101, 32);

        $r = $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)));
        self::assertSame('attributed', $r['body']['data']['match'], (string) json_encode($r));
        $payouts = array_column(self::$db->query('SELECT click_payout FROM 202_conversion_logs WHERE click_id = 101')->fetch_all(MYSQLI_ASSOC), 'click_payout');
        self::assertNotEmpty($payouts, 'the install is the install goal\'s conversion on its click');
        self::assertNotContains('7.77000', $payouts, "another account's campaign payout paid this account's install");
        self::assertSame(['0.00000'], array_values(array_unique($payouts)), 'a click with no campaign of its own pays the install nothing by default');
    }
}

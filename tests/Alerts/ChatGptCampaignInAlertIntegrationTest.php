<?php

declare(strict_types=1);

namespace Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\ChatGptCampaignsOfCampaign;
use Prosper202\Alerts\AccountActivity;
use Prosper202\Alerts\AlertSettings;
use Prosper202\Alerts\CampaignActivity;
use Prosper202\Alerts\MysqlAlertStore;
use Prosper202\Alerts\SpendWithoutConversionsDetector;
use Tests\Apps\Android\AndroidDatabase;

/**
 * A spend alert names the ChatGPT Ads campaign that sent the clicks and
 * where to pause it, read from each click's campaign_id variable on a
 * traffic source account linked to an ad connection, against a real server.
 * Another account's source, a variable of another source and a value that
 * is not an OpenAI id name nothing.
 *
 * @group integration
 */
final class ChatGptCampaignInAlertIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_ad_connections', '202_clicks_variable', '202_variable_sets2', '202_custom_variables', '202_ppc_network_variables', '202_ppc_networks', '202_ppc_accounts'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        // User 1's ChatGPT source (network 5, account 50) with its campaign_id variable (60), and an
        // unlinked source of its own (network 6, account 51, variable 61, also named campaign_id).
        self::fixture("INSERT INTO 202_ppc_networks (ppc_network_id, user_id, ppc_network_name, ppc_network_time) VALUES (5, 1, 'ChatGPT Ads', 1), (6, 1, 'Search', 1), (7, 2, 'ChatGPT Ads', 1)");
        self::fixture("INSERT INTO 202_ppc_accounts (ppc_account_id, user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES (50, 1, 5, 'Main', 1), (51, 1, 6, 'Search', 1), (52, 2, 7, 'Theirs', 1)");
        self::fixture("INSERT INTO 202_ppc_network_variables (ppc_variable_id, ppc_network_id, name, parameter, placeholder) VALUES (60, 5, 'Campaign ID', 'campaign_id', '{campaign_id}'), (61, 6, 'Campaign', 'campaign_id', '{x}'), (62, 7, 'Campaign ID', 'campaign_id', '{campaign_id}'), (63, 5, 'Ad ID', 'ad_id', '{ad_id}')");
        self::fixture("INSERT INTO 202_ad_connections (connection_id, user_id, platform, ppc_account_id, account_ref, name, currency, timezone, credentials, setup, status, created_at, updated_at)
            VALUES (3, 1, 'openai_ads', 50, 'adacct_123', 'Northwind Outfitters', 'USD', 'America/New_York', '', '{}', 'connected', 1, 1),
                   (4, 2, 'openai_ads', 52, 'adacct_999', 'Other shop', 'USD', 'America/New_York', '', '{}', 'connected', 1, 1)");
        $this->click(9001, 1, 30, 50, ['cmpn_101' => 60, 'ad_301' => 63]);
        $this->click(9002, 1, 30, 50, ['cmpn_101' => 60]);
        $this->click(9003, 1, 30, 50, ['cmpn_102' => 60]);
        // A typed value, another source's variable, and yesterday's click name nothing.
        $this->click(9004, 1, 30, 50, ['{campaign_id}' => 60]);
        $this->click(9005, 1, 30, 51, ['cmpn_555' => 61]);
        $this->click(9006, 1, 30, 50, ['cmpn_777' => 60], self::NOW - 2 * 86400);
        // Another account's click on campaign 30 from its own source.
        $this->click(9007, 2, 30, 52, ['cmpn_888' => 62]);
    }

    /** @param array<string, int> $values value => ppc_variable_id */
    private function click(int $clickId, int $user, int $campaign, int $ppcAccount, array $values, int $time = self::NOW - 600): void
    {
        self::fixture("INSERT INTO 202_clicks SET click_id = $clickId, user_id = $user, aff_campaign_id = $campaign, ppc_account_id = $ppcAccount, click_cpc = 1, click_time = $time");
        self::fixture("INSERT INTO 202_clicks_variable SET click_id = $clickId, variable_set_id = $clickId");
        foreach ($values as $value => $variableId) {
            self::fixture("INSERT INTO 202_custom_variables SET ppc_variable_id = $variableId, variable = '" . self::$db->real_escape_string($value) . "'");
            $id = (int) self::$db->query('SELECT MAX(custom_variable_id) AS id FROM 202_custom_variables')->fetch_assoc()['id'];
            self::fixture('INSERT INTO 202_variable_sets2 SET variable_set_id = ' . $clickId . ', variables = ' . $id);
        }
    }

    public function testTheClicksNameTheirChatGptCampaignsMostClicksFirst(): void
    {
        self::assertSame(
            [['connection_id' => 3, 'campaign_id' => 'cmpn_101', 'clicks' => 2], ['connection_id' => 3, 'campaign_id' => 'cmpn_102', 'clicks' => 1]],
            ChatGptCampaignsOfCampaign::since($this->conn, 1, 30, self::NOW - 86400)
        );
        self::assertSame([['connection_id' => 4, 'campaign_id' => 'cmpn_888', 'clicks' => 1]], ChatGptCampaignsOfCampaign::since($this->conn, 2, 30, self::NOW - 86400), 'each account its own');
    }

    public function testTheSpendAlertSaysWhereToPauseIt(): void
    {
        $activity = new AccountActivity(1, self::NOW, 'UTC', self::NOW - 3600, 'USD', [
            30 => new CampaignActivity(30, 'Spring sale', clicksToday: 3, costToday: 150.0),
        ]);
        $alerts = (new SpendWithoutConversionsDetector())->detect($this->conn, $activity, new AlertSettings(dailyCostCap: '100.00'));

        self::assertCount(1, $alerts);
        self::assertStringContainsString('Its clicks come from ChatGPT Ads campaigns cmpn_101, cmpn_102: pause them at Setup › Destinations › ChatGPT Ads, or run p202 ad-campaign pause 3 cmpn_101.', $alerts[0]->message);
        self::assertSame(['chatgpt_campaigns' => '3:cmpn_101,3:cmpn_102', 'chatgpt_campaign_ids' => 'cmpn_101,cmpn_102'], array_intersect_key($alerts[0]->facts, array_flip(['chatgpt_campaigns', 'chatgpt_campaign_ids'])));
        self::assertArrayNotHasKey('chatgpt_connection_id', $alerts[0]->facts, 'no one connection stands for every campaign');

        self::$db->query('TRUNCATE TABLE 202_ad_connections');
        $plain = (new SpendWithoutConversionsDetector())->detect($this->conn, $activity, new AlertSettings(dailyCostCap: '100.00'));
        self::assertStringEndsWith('or pause it.', $plain[0]->message, 'no connection: the alert as it was');
        self::assertArrayNotHasKey('chatgpt_campaign_ids', $plain[0]->facts);
    }

    public function testTheInAppAlertKeepsAPauseForEachCampaignAndOffersIt(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-alerts-ui.php';
        self::$db->query('DELETE FROM 202_alert_log WHERE user_id = 1');
        $activity = new AccountActivity(1, self::NOW, 'UTC', self::NOW - 3600, 'USD', [
            30 => new CampaignActivity(30, 'Spring sale', clicksToday: 3, costToday: 150.0),
        ]);
        $alert = (new SpendWithoutConversionsDetector())->detect($this->conn, $activity, new AlertSettings(dailyCostCap: '100.00'))[0];
        $store = new MysqlAlertStore($this->conn);
        $store->logSeen($alert, self::NOW);

        $listed = $store->recent(1, self::NOW - 60, 10);
        self::assertSame([
            ['action' => 'chatgpt_pause', 'connection_id' => 3, 'campaign_id' => 'cmpn_101'],
            ['action' => 'chatgpt_pause', 'connection_id' => 3, 'campaign_id' => 'cmpn_102'],
        ], $listed[0]['actions']);
        $html = p202_alerts_list($listed, 'https://track.example.com/', self::NOW);
        self::assertStringContainsString('tracking202/setup/destinations.php?ad_campaigns=3&amp;preview_pause=cmpn_101#ad-campaigns', $html);
        self::assertStringContainsString('Pause cmpn_102 in ChatGPT Ads', $html);

        self::$db->query("UPDATE 202_alert_log SET actions = 'not json' WHERE user_id = 1");
        self::assertSame([], $store->recent(1, self::NOW - 60, 10)[0]['actions'], 'unreadable actions: the alert is still shown, without buttons');
    }

    /** Two connections feeding one Prosper202 campaign: each campaign's pause goes through the connection that reaches it. */
    public function testEachCampaignKeepsTheConnectionThatReachesIt(): void
    {
        self::fixture("INSERT INTO 202_ppc_networks (ppc_network_id, user_id, ppc_network_name, ppc_network_time) VALUES (8, 1, 'ChatGPT Ads EU', 1)");
        self::fixture("INSERT INTO 202_ppc_accounts (ppc_account_id, user_id, ppc_network_id, ppc_account_name, ppc_account_time) VALUES (53, 1, 8, 'EU', 1)");
        self::fixture("INSERT INTO 202_ppc_network_variables (ppc_variable_id, ppc_network_id, name, parameter, placeholder) VALUES (64, 8, 'Campaign ID', 'campaign_id', '{campaign_id}')");
        self::fixture("INSERT INTO 202_ad_connections (connection_id, user_id, platform, ppc_account_id, account_ref, name, currency, timezone, credentials, setup, status, created_at, updated_at)
            VALUES (5, 1, 'openai_ads', 53, 'adacct_456', 'Northwind EU', 'EUR', 'Europe/Berlin', '', '{}', 'connected', 1, 1)");
        $this->click(9010, 1, 30, 53, ['cmpn_eu1' => 64]);
        $activity = new AccountActivity(1, self::NOW, 'UTC', self::NOW - 3600, 'USD', [
            30 => new CampaignActivity(30, 'Spring sale', clicksToday: 4, costToday: 150.0),
        ]);
        $alert = (new SpendWithoutConversionsDetector())->detect($this->conn, $activity, new AlertSettings(dailyCostCap: '100.00'))[0];

        self::assertSame([
            ['action' => 'chatgpt_pause', 'connection_id' => 3, 'campaign_id' => 'cmpn_101'],
            ['action' => 'chatgpt_pause', 'connection_id' => 3, 'campaign_id' => 'cmpn_102'],
            ['action' => 'chatgpt_pause', 'connection_id' => 5, 'campaign_id' => 'cmpn_eu1'],
        ], MysqlAlertStore::actionsOf($alert));
    }
}

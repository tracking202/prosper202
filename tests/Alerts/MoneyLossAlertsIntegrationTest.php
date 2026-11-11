<?php

declare(strict_types=1);

namespace Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Prosper202\Alerts\AccountActivity;
use Prosper202\Alerts\AlertNotifier;
use Prosper202\Alerts\AlertRun;
use Prosper202\Alerts\AlertSettings;
use Prosper202\Alerts\MysqlAlertStore;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Notifications\SlackWebhook;

/**
 * The money-loss alerts against a real server: the account's conversions,
 * report rows and campaigns as the click path and the postbacks leave them,
 * the detectors, the account's own Slack webhook (through SlackWebhook and
 * WebhookSender, with only curl replaced), its email (mail() replaced), and
 * its webhooks' delivery queue.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST, ...); it
 * installs the schema there and writes users 7811-7813, campaigns
 * 781101-781112, clicks 978110000 on, and their conversions, settings and
 * webhooks. 7811 has settings of its own, 7812 turned alerts off, and 7813
 * has no settings row (alerts on) and nowhere to send them.
 *
 * @group integration
 */
final class MoneyLossAlertsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 7811;
    private const OTHER = 7812;
    private const QUIET = 7813;
    private const USERS = '7811, 7812, 7813';
    private const SETTINGS_URL = 'https://p202.example.com/202-account/account.php#money-loss-alerts';
    private const STOPPED = 781101;
    private const FLOWING = 781102;
    private const PAUSED = 781103;
    private const FEW = 781104;
    private const OVER_CAP = 781105;
    private const CONVERTED_TODAY = 781106;
    private const OTHERS = 781107;
    private const QUIET_STOPPED = 781108;
    private const BOT_TAIL = 781109;
    private const BOT_FLOOD = 781110;
    private const UPLOADED = 781111;
    private const BULK_API = 781112;
    private const SLACK_ADDRESS = '203.0.113.10';
    private const OWNER_SLACK = 'https://hooks.slack.com/services/T0/B0/ownerSecretPath';
    private const OTHER_SLACK = 'https://hooks.slack.com/services/T0/B0/otherSecretPath';
    private const ZONE = 'Asia/Kolkata';

    private static int $now = 0;
    private static int $clickId = 978110000;
    private static int $convId = 0;

    /** @var list<array<int, mixed>> */
    private array $posted = [];
    /** @var list<array{string, string, string, string}> */
    private array $mailed = [];
    private int $slackStatus = 200;
    private string $log = '';
    private string|false $savedLog = false;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-alerts-ui.php';
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::$now = time();
        self::user(self::OWNER, self::ZONE);
        self::user(self::OTHER);
        self::user(self::QUIET);
        self::q("UPDATE 202_users SET user_email = '' WHERE user_id = " . self::QUIET);
        self::row('202_users_pref', ['user_id' => self::OWNER, 'user_slack_incoming_webhook' => self::OWNER_SLACK,
            'user_tracking_domain' => 'https://track.example.com', 'user_account_currency' => 'EUR']);
        self::row('202_users_pref', ['user_id' => self::OTHER, 'user_slack_incoming_webhook' => self::OTHER_SLACK]);
        foreach (
            [
            self::STOPPED => [self::OWNER, 'Spring Sale'],
            self::FLOWING => [self::OWNER, 'Trial Signups'],
            self::PAUSED => [self::OWNER, 'Paused Promo'],
            self::FEW => [self::OWNER, 'Webinar'],
            self::OVER_CAP => [self::OWNER, 'Quote Requests'],
            self::CONVERTED_TODAY => [self::OWNER, 'Demo Requests'],
            self::OTHERS => [self::OTHER, 'Other Shop'],
            self::QUIET_STOPPED => [self::QUIET, 'Quiet Shop'],
            self::BOT_TAIL => [self::OWNER, 'Bot Tail'],
            self::BOT_FLOOD => [self::OWNER, 'Bot Flood'],
            self::UPLOADED => [self::OWNER, 'Uploaded Daily'],
            self::BULK_API => [self::OWNER, 'Bulk API'],
            ] as $id => [$user, $name]
        ) {
            self::row('202_aff_campaigns', ['aff_campaign_id' => $id, 'user_id' => $user, 'aff_network_id' => 1,
                'aff_campaign_name' => $name, 'aff_campaign_url' => 'https://shop.example.com/',
                'aff_campaign_payout' => 10,
                'aff_campaign_time' => 0, 'aff_campaign_foreign_payout' => 0]);
        }

        $now = self::$now;
        $hour = 3600;
        // Twenty conversions an hour apart, the last 11 hours ago; a click
        // every six minutes for 30 hours, up to five minutes ago.
        self::conversions(self::OWNER, self::STOPPED, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::OWNER, self::STOPPED, range($now - 30 * $hour, $now - 300, 360));
        // Another account's conversions naming the campaign do not keep it alive.
        self::conversions(self::OTHER, self::STOPPED, [$now - 3600, $now - 1800]);
        // Still converting.
        self::conversions(self::OWNER, self::FLOWING, range($now - 30 * $hour, $now - 1800, $hour));
        self::clicks(self::OWNER, self::FLOWING, range($now - 30 * $hour, $now - 300, 360));
        // Stopped converting and stopped buying clicks: paused.
        self::conversions(self::OWNER, self::PAUSED, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::OWNER, self::PAUSED, range($now - 30 * $hour, $now - 10 * $hour, 360));
        // Five conversions is not steady.
        self::conversions(self::OWNER, self::FEW, range($now - 30 * $hour, $now - 26 * $hour, $hour));
        self::clicks(self::OWNER, self::FEW, range($now - 30 * $hour, $now - 300, 360));
        // The other account's campaign, the same as the stopped one, with alerts off.
        self::conversions(self::OTHER, self::OTHERS, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::OTHER, self::OTHERS, range($now - 30 * $hour, $now - 300, 360));
        // Rows that are not a conversion arriving, an hour ago: if any
        // counted, the stopped campaign would read as converting.
        $last = self::conversions(self::OWNER, self::STOPPED, [$now - 30 * $hour]);
        self::conversions(self::OWNER, self::STOPPED, [$now - 3600], "reverses_conv_id = $last");
        self::conversions(self::OWNER, self::STOPPED, [$now - 3600], 'deleted = 1');
        self::conversions(self::OWNER, self::STOPPED, [$now - 3600], "source = 'legacy_baseline'");
        self::conversions(self::OWNER, self::STOPPED, [$now - 3600], "source = 'subid_upload'");
        // Stopped, real clicks until three hours ago, then only filtered
        // ones: no real click lately, so not alerted.
        self::conversions(self::OWNER, self::BOT_TAIL, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::OWNER, self::BOT_TAIL, range($now - 30 * $hour, $now - 3 * $hour, 360));
        self::clicks(self::OWNER, self::BOT_TAIL, range($now - 2 * $hour + 60, $now - 60, 120), 0.0, 1);
        // Stopped, one real click since and a flood of filtered ones: too
        // few real clicks since to be alerted.
        self::conversions(self::OWNER, self::BOT_FLOOD, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::OWNER, self::BOT_FLOOD, range($now - 30 * $hour, $now - 11 * $hour, 360));
        self::clicks(self::OWNER, self::BOT_FLOOD, [$now - 300]);
        self::clicks(self::OWNER, self::BOT_FLOOD, range($now - 11 * $hour + 60, $now - 60, 60), 0.0, 1);
        // Update Subids once a day, 12 conversions in one second each time,
        // the last 13 hours ago, and steady real clicks: no live rhythm.
        // The same through the API: three arrivals, not 36, so not steady.
        foreach ([self::UPLOADED => "source = 'subid_upload'", self::BULK_API => "source = 'api'"] as $id => $source) {
            foreach ([61, 37, 13] as $ago) {
                self::conversions(self::OWNER, $id, array_fill(0, 12, $now - $ago * $hour), $source);
            }
            self::clicks(self::OWNER, $id, range($now - 70 * $hour, $now - 300, 360));
        }

        // An account with no email, no Slack and no webhook.
        self::conversions(self::QUIET, self::QUIET_STOPPED, range($now - 30 * $hour, $now - 11 * $hour, $hour));
        self::clicks(self::QUIET, self::QUIET_STOPPED, range($now - 30 * $hour, $now - 300, 360));

        // Cost today in the account's zone, and a bigger cost the day before.
        $dayStart = AccountActivity::dayStart($now, self::ZONE);
        $today = $dayStart + intdiv($now - $dayStart, 2);
        self::clicks(self::OWNER, self::OVER_CAP, [$dayStart - 60], 500.0);
        self::clicks(self::OWNER, self::OVER_CAP, [$today, $today, $today], 40.0);
        // A filtered click was paid for too.
        self::clicks(self::OWNER, self::OVER_CAP, [$today], 30.0, 1);
        self::clicks(self::OWNER, self::CONVERTED_TODAY, [$today, $today, $today, $today], 40.0);
        self::conversions(self::OWNER, self::CONVERTED_TODAY, [$today]);

        // The owner's webhook takes every event; the other's takes alerts too.
        $subscriptions = [
            self::OWNER => '',
            self::OTHER => 'alert.conversions_stopped,alert.spend_without_conversions',
        ];
        foreach ($subscriptions as $user => $events) {
            self::row('202_ltv_webhooks', ['user_id' => $user, 'webhook_url' => 'https://hooks.example.com/p202',
                'webhook_secret' => 's', 'subscribed_events' => $events, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-alerts-log-');
        $this->savedLog = ini_get('error_log');
        ini_set('error_log', $this->log);
        $tables = ['202_alert_state', '202_alert_log', '202_ltv_webhook_deliveries', '202_alert_settings'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USERS . ')');
        }
        self::q('DELETE FROM 202_alert_state WHERE user_id = 0');
        $this->settings(self::OWNER, new AlertSettings(true, true, true, true, 5, 10, '100.00'));
        $this->settings(self::OTHER, new AlertSettings(false, true, true, true, 5, 10, '100.00'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedLog === false ? '' : $this->savedLog);
        @unlink($this->log);
    }

    public function testASteadyCampaignThatStoppedAndOnePastItsCapAreToldOnEveryChannelOnce(): void
    {
        $report = $this->runAlerts(self::$now);

        self::assertSame(['accounts' => 2, 'sent' => 2, 'failed' => 0, 'unsent' => 1, 'errors' => 0], $report);

        self::assertCount(2, $this->posted);
        foreach ($this->posted as $options) {
            self::assertSame(self::OWNER_SLACK, $options[CURLOPT_URL], 'the alert goes to its own account\'s Slack');
        }
        $texts = array_map(
            static fn (array $o): string => self::slackText((string) $o[CURLOPT_POSTFIELDS]),
            $this->posted
        );
        self::assertStringStartsWith(
            '*Conversions stopped*: Spring Sale (campaign #' . self::STOPPED . ') has had no conversion for 11 h',
            $texts[0]
        );
        self::assertStringContainsString('usual gap of 1 h', $texts[0]);
        self::assertStringStartsWith(
            '*Spend with no conversions*: Quote Requests (campaign #' . self::OVER_CAP . ') has cost EUR 150.00 today',
            $texts[1]
        );
        self::assertStringContainsString('daily cap of EUR 100.00', $texts[1]);

        self::assertCount(2, $this->mailed);
        self::assertSame('rp' . self::OWNER . '@example.com', $this->mailed[0][0]);
        $subject = iconv_mime_decode($this->mailed[0][1], 0, 'UTF-8');
        self::assertSame('[Prosper202] Conversions stopped: Spring Sale', $subject);
        self::assertStringContainsString('From: Prosper202 <prosper202@track.example.com>', $this->mailed[0][3]);

        $deliveries = self::deliveries();
        self::assertSame([self::OWNER, self::OWNER], array_map('intval', array_column($deliveries, 'user_id')));
        self::assertSame(
            ['alert.conversions_stopped', 'alert.spend_without_conversions'],
            array_column($deliveries, 'event_name')
        );
        $stopped = json_decode((string) $deliveries[0]['payload'], true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(self::STOPPED, $stopped['campaign_id']);
        self::assertSame(self::$now - 11 * 3600, $stopped['last_conversion_at']);
        self::assertSame(3600, $stopped['usual_gap_seconds']);
        self::assertSame(21, $stopped['conversions_last_7_days']);
        $since = array_filter(
            range(self::$now - 30 * 3600, self::$now - 300, 360),
            static fn (int $t): bool => $t > self::$now - 11 * 3600
        );
        self::assertSame(count($since), $stopped['clicks_since_last_conversion']);
        $cap = json_decode((string) $deliveries[1]['payload'], true, 512, JSON_THROW_ON_ERROR)['data'];
        $cost = (int) round($cap['cost_today']);
        self::assertSame(150, $cost, 'today\'s cost in the account\'s zone, filtered clicks too');
        self::assertSame('EUR', $cap['currency']);
        self::assertSame(self::ZONE, $cap['timezone']);

        // The next hour's run finds the same incidents and sends nothing.
        $again = $this->runAlerts(self::$now + 60);
        self::assertSame(0, $again['sent']);
        self::assertCount(2, $this->posted);
        self::assertCount(2, self::deliveries());
    }

    public function testAConversionReArmsTheAlertAndTheNextSilenceIsToldAgain(): void
    {
        $this->runAlerts(self::$now);
        self::assertCount(2, $this->posted);

        // A conversion an hour after the last: a new incident, still silent
        // ten hours later while the clicks go on.
        $id = self::conversions(self::OWNER, self::STOPPED, [self::$now - 10 * 3600]);
        try {
            $report = $this->runAlerts(self::$now + 60);
            self::assertSame(1, $report['sent']);
            self::assertCount(3, $this->posted);
            $text = self::slackText((string) $this->posted[2][CURLOPT_POSTFIELDS]);
            self::assertStringContainsString('Spring Sale', $text);
        } finally {
            self::q('DELETE FROM 202_conversion_logs WHERE conv_id = ' . $id);
        }
    }

    public function testAnAlertNoChannelTookIsSentAgainNextRun(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, false, false, 5, 10, '100.00'));
        $this->slackStatus = 500;

        $first = $this->runAlerts(self::$now);
        self::assertSame(['accounts' => 2, 'sent' => 0, 'failed' => 2, 'unsent' => 1, 'errors' => 0], $first);
        $logged = (string) file_get_contents($this->log);
        self::assertStringContainsString(
            'campaign ' . self::STOPPED . ' of account ' . self::OWNER
                . ' was not sent by slack: the webhook answered 500',
            $logged
        );
        self::assertStringNotContainsString('ownerSecretPath', $logged, 'the webhook\'s path is its secret');

        $this->slackStatus = 200;
        $second = $this->runAlerts(self::$now + 60);
        self::assertSame(2, $second['sent']);
        self::assertCount(4, $this->posted);
        self::assertSame([], $this->mailed, 'email is off');
        self::assertSame([], self::deliveries(), 'webhooks are off');
    }

    public function testAnIncidentEveryChannelFailsForIsTriedAtMostThreeTimes(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, false, false, 5, 10, '0.00'));
        $this->slackStatus = 500;

        foreach ([1, 2, 3] as $run) {
            self::assertSame(1, $this->runAlerts(self::$now + $run)['failed'], "run $run tries again");
        }
        self::assertCount(3, $this->posted);

        $this->slackStatus = 200;
        self::assertSame(0, $this->runAlerts(self::$now + 4)['sent'], 'the fourth run leaves the incident alone');
        self::assertCount(3, $this->posted);
    }

    public function testAnAccountWithNoSettingsRowIsAlertedByEmailAndItsOwnSlack(): void
    {
        self::q('DELETE FROM 202_alert_settings WHERE user_id = ' . self::OWNER);

        $report = $this->runAlerts(self::$now);

        self::assertSame(['accounts' => 2, 'sent' => 1, 'failed' => 0, 'unsent' => 1, 'errors' => 0], $report);
        self::assertSame(['rp' . self::OWNER . '@example.com'], array_column($this->mailed, 0));
        self::assertStringContainsString('Spring Sale', $this->mailed[0][2]);
        self::assertSame([self::OWNER_SLACK], array_column($this->posted, CURLOPT_URL));
        $events = array_column(self::deliveries(), 'event_name');
        self::assertSame(['alert.conversions_stopped'], $events, 'no cap by default');
        // The account with nowhere to send its alert is told nothing and logs nothing.
        self::assertStringNotContainsString('account ' . self::QUIET, (string) file_get_contents($this->log));
        self::assertArrayHasKey(
            'conversions_stopped:' . self::QUIET_STOPPED,
            (new MysqlAlertStore(new Connection(self::$db)))->sentIncidents(self::QUIET),
            'recorded, so it is not tried every hour'
        );
    }

    public function testAnAccountThatTurnedAlertsOffGetsNone(): void
    {
        $this->runAlerts(self::$now);

        self::assertNotContains(self::OTHER_SLACK, array_column($this->posted, CURLOPT_URL));
        self::assertNotContains('rp' . self::OTHER . '@example.com', array_column($this->mailed, 0));
        $queuedFor = array_values(array_unique(array_map('intval', array_column(self::deliveries(), 'user_id'))));
        self::assertSame([self::OWNER], $queuedFor);
        self::assertSame([], (new MysqlAlertStore(new Connection(self::$db)))->sentIncidents(self::OTHER));

        // Without its row the same account is alerted: the row is what keeps it quiet.
        self::q('DELETE FROM 202_alert_settings WHERE user_id = ' . self::OTHER);
        $this->runAlerts(self::$now + 60);
        self::assertContains(self::OTHER_SLACK, array_column($this->posted, CURLOPT_URL));
        self::assertContains('rp' . self::OTHER . '@example.com', array_column($this->mailed, 0));
    }

    public function testEveryMessageSaysHowToTurnAlertsOff(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, true, false, 5, 10, '0.00'));

        $this->runAlerts(self::$now, self::SETTINGS_URL);

        self::assertStringEndsWith(
            "\nTo turn these alerts off: <" . self::SETTINGS_URL . '|Account › Personal settings › Money-loss alerts>.',
            self::slackText((string) $this->posted[0][CURLOPT_POSTFIELDS])
        );
        self::assertStringContainsString(
            'To turn these alerts off: Account › Personal settings › Money-loss alerts (' . self::SETTINGS_URL . ').',
            $this->mailed[0][2]
        );

        // Where the install's address is not known, the place is named without a link.
        self::q('DELETE FROM 202_alert_state WHERE user_id = ' . self::OWNER);
        $this->runAlerts(self::$now);
        self::assertStringEndsWith(
            "\nTo turn these alerts off: Account › Personal settings › Money-loss alerts.",
            self::slackText((string) $this->posted[1][CURLOPT_POSTFIELDS])
        );
        self::assertStringContainsString(
            'To turn these alerts off: Account › Personal settings › Money-loss alerts.',
            $this->mailed[1][2]
        );
        self::assertNull(
            AlertNotifier::settingsUrl(new Connection(self::$db), ['argv' => []], dirname(__DIR__, 2)),
            'a command-line run does not know the install\'s path'
        );
    }

    public function testNoCapMeansNoSpendAlert(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, true, true, 5, 10, '0.00'));

        self::assertSame(1, $this->runAlerts(self::$now)['sent']);
        self::assertSame(['alert.conversions_stopped'], array_column(self::deliveries(), 'event_name'));
    }

    public function testAHigherMultipleWaitsLonger(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, true, true, 12, 10, '0.00'));

        self::assertSame(0, $this->runAlerts(self::$now)['sent'], 'eleven hours is less than twelve times an hour');
    }

    public function testUploadsAndBurstsAreNotARhythm(): void
    {
        $this->runAlerts(self::$now);

        $named = array_map(
            static fn (array $o): string => self::slackText((string) $o[CURLOPT_POSTFIELDS]),
            $this->posted
        );
        foreach (['Uploaded Daily', 'Bulk API', 'Bot Tail', 'Bot Flood', 'Paused Promo'] as $quiet) {
            foreach ($named as $text) {
                self::assertStringNotContainsString($quiet, $text);
            }
        }
        $stopped = json_decode((string) self::deliveries()[0]['payload'], true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(self::STOPPED, $stopped['campaign_id']);
        self::assertSame(self::$now - 11 * 3600, $stopped['last_conversion_at'], 'no row an hour ago counted');
        self::assertSame(21, $stopped['conversions_last_7_days']);
        self::assertSame(20, $stopped['converting_hours_last_7_days'], 'two arrivals in one hour are one');
    }

    public function testEachRunStartsAfterTheAccountTheLastOneReached(): void
    {
        $store = new MysqlAlertStore(new Connection(self::$db));
        $store->saveCursor(self::OWNER, self::$now);

        $this->runAlerts(self::$now);
        self::assertSame(self::OWNER, $store->cursor(), 'the quiet account came first, then the owner');

        $store->saveCursor(self::QUIET, self::$now);
        $this->runAlerts(self::$now + 60);
        self::assertSame(self::QUIET, $store->cursor(), 'from the quiet account, the owner first');

        $conn = new Connection(self::$db);
        $none = (new AlertRun($conn, new AlertNotifier($conn)))->run(self::$now, time() - 1);
        self::assertSame(0, $none['accounts'], 'a run out of time reaches nobody');
        self::assertSame(self::QUIET, $store->cursor(), 'and moves nothing');
    }

    public function testEveryAlertIsListedInTheAppWithItsPageAndWhatEachChannelDid(): void
    {
        $this->runAlerts(self::$now, self::SETTINGS_URL);
        $store = new MysqlAlertStore(new Connection(self::$db));

        $listed = $store->recent(self::OWNER, self::$now - 60, 10);
        self::assertSame(
            ['spend_without_conversions', 'conversions_stopped'],
            array_map(static fn (array $a): string => $a['kind']->stateKey(), $listed),
            'the latest first'
        );
        foreach ($listed as $alert) {
            self::assertSame(
                [MysqlAlertStore::SENT, 1, ['slack', 'email', 'webhook'], '', self::$now, self::$now],
                [$alert['delivery'], $alert['attempts'], $alert['sent_by'], $alert['delivery_note'],
                    $alert['first_seen_at'], $alert['last_seen_at']]
            );
        }
        self::assertStringStartsWith('Spring Sale (campaign #' . self::STOPPED . ')', $listed[1]['message']);

        // Each message links the page that shows the problem.
        $log = 'https://p202.example.com/tracking202/setup/inbound_log.php';
        $campaign = 'https://p202.example.com/tracking202/setup/aff_campaigns.php?edit_aff_campaign_id='
            . self::OVER_CAP;
        self::assertStringContainsString(
            "\n<$log|See what its postbacks and pixels last sent (Setup › Inbound Log)>\n",
            self::slackText((string) $this->posted[0][CURLOPT_POSTFIELDS])
        );
        self::assertStringContainsString("(Setup › Inbound Log): $log\n", $this->mailed[0][2]);
        self::assertStringContainsString("Open the campaign (Setup › Campaigns): $campaign\n", $this->mailed[1][2]);
        $payloads = array_map(
            static fn (array $d): array => json_decode((string) $d['payload'], true, 512, JSON_THROW_ON_ERROR)['data'],
            self::deliveries()
        );
        self::assertSame([$log, $campaign], array_column($payloads, 'url'));

        // An hour on the problems still hold: nothing is sent again, and each row says it is still going on.
        $this->runAlerts(self::$now + 3600, self::SETTINGS_URL);
        $later = $store->recent(self::OWNER, self::$now - 60, 10);
        self::assertCount(2, $later, 'one row per incident');
        self::assertSame(
            [[self::$now, self::$now + 3600], [self::$now, self::$now + 3600]],
            array_map(static fn (array $a): array => [$a['first_seen_at'], $a['last_seen_at']], $later)
        );
        self::assertCount(2, $this->mailed);

        $html = p202_alerts_list($later, 'https://p202.example.com/', self::$now + 3600);
        self::assertSame(2, substr_count($html, 'p202-pill--bad">Going on'));
        self::assertStringContainsString('href="' . $log . '"', $html);
        self::assertStringContainsString('href="' . htmlspecialchars($campaign) . '"', $html);
        self::assertStringContainsString('Sent by Slack, Email, Webhooks.', $html);
        self::assertSame(
            2,
            substr_count(p202_alerts_list($later, 'https://p202.example.com/', self::$now + 4 * 3600), '>Earlier<'),
            'a problem no run has seen lately is no longer going on'
        );

        // The account with nowhere to send it still has it in the app, with why.
        $quiet = $store->recent(self::QUIET, self::$now - 60, 10);
        self::assertSame(
            [[MysqlAlertStore::NOWHERE, []]],
            array_map(static fn (array $a): array => [$a['delivery'], $a['sent_by']], $quiet)
        );
        self::assertStringContainsString('Email: the account has no valid email address', $quiet[0]['delivery_note']);
        self::assertStringContainsString(
            'Shown here only: no channel had anywhere to send it.',
            p202_alerts_list($quiet, 'https://p202.example.com/', self::$now)
        );
        self::assertSame([], $store->recent(self::OTHER, 0, 10), 'an account with alerts off has none');

        // Home's panel is the same list, and nothing for an account with no alerts.
        $panel = p202_alerts_home_panel(self::$db, self::OWNER, 'https://p202.example.com/', self::$now + 3600);
        self::assertStringContainsString('id="money-loss-alerts"', $panel);
        self::assertSame(2, substr_count($panel, 'Going on'));
        self::assertSame('', p202_alerts_home_panel(self::$db, self::OTHER, 'https://p202.example.com/', self::$now));

        // The log keeps an alert LOG_DAYS days after its problem was last seen.
        $this->runAlerts(self::$now + 3600 + AlertRun::LOG_DAYS * 86400 - 60);
        self::assertCount(2, $store->recent(self::OWNER, self::$now, 10));
    }

    public function testAnAlertEveryChannelFailedForSaysSoInTheApp(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, true, false, false, 5, 10, '0.00'));
        $this->slackStatus = 500;
        $store = new MysqlAlertStore(new Connection(self::$db));

        $this->runAlerts(self::$now);
        $first = $store->recent(self::OWNER, 0, 10);
        self::assertSame([[MysqlAlertStore::FAILED, 1]], array_map(
            static fn (array $a): array => [$a['delivery'], $a['attempts']],
            $first
        ));
        self::assertStringContainsString('Slack: the webhook answered 500', $first[0]['delivery_note']);
        self::assertStringNotContainsString('ownerSecretPath', $first[0]['delivery_note']);
        self::assertStringContainsString(
            'Not sent yet (try 1 of 3).',
            p202_alerts_list($first, 'https://p202.example.com/', self::$now)
        );

        $this->runAlerts(self::$now + 1);
        $this->runAlerts(self::$now + 2);
        $this->runAlerts(self::$now + 3);
        $last = $store->recent(self::OWNER, 0, 10);
        self::assertSame([[MysqlAlertStore::FAILED, 3, self::$now + 3]], array_map(
            static fn (array $a): array => [$a['delivery'], $a['attempts'], $a['last_seen_at']],
            $last
        ));
        $html = p202_alerts_list($last, 'https://p202.example.com/', self::$now + 3);
        self::assertStringContainsString(
            'class="text-danger">Never sent, after 3 tries. Slack: the webhook answered 500',
            $html
        );
    }

    public function testATestAlertGoesWhereARealOneWouldAndSaysWhatEachChannelDid(): void
    {
        $conn = new Connection(self::$db);
        $root = dirname(__DIR__, 2);

        $linked = $this->notifier($conn, self::SETTINGS_URL);
        [$kind, $text] = p202_alerts_send_test(self::$db, self::OWNER, [], $root, self::$now, $linked);
        self::assertSame('ok', $kind);
        self::assertStringContainsString('Slack took it', $text);
        self::assertStringContainsString('accepted it for rp' . self::OWNER . '@example.com', $text);
        self::assertStringContainsString('queued as alert.test', $text);
        self::assertSame([self::OWNER_SLACK], array_column($this->posted, CURLOPT_URL));
        $slack = self::slackText((string) $this->posted[0][CURLOPT_POSTFIELDS]);
        self::assertStringStartsWith('*Test alert*: This is a test of your Prosper202 money-loss alerts', $slack);
        self::assertStringContainsString('<' . self::SETTINGS_URL . '|Open your alert settings>', $slack);
        self::assertSame(
            '[Prosper202] Test alert: your alerts reach you',
            iconv_mime_decode($this->mailed[0][1], 0, 'UTF-8')
        );
        $deliveries = self::deliveries();
        self::assertSame(['alert.test'], array_column($deliveries, 'event_name'));
        $data = json_decode((string) $deliveries[0]['payload'], true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['test', 0, 1], [$data['alert'], $data['campaign_id'], $data['test']]);
        $store = new MysqlAlertStore($conn);
        self::assertSame([], $store->recent(self::OWNER, 0, 10), 'a test is not an alert');
        self::assertSame([], $store->sentIncidents(self::OWNER));

        // A webhook subscribed to the alerts by name hears the test too; one that takes none of them does not.
        $this->settings(self::OTHER, new AlertSettings(true, false, false, true, 5, 10, '0.00'));
        [$kind, $text] = $this->sendTest(self::OTHER);
        self::assertSame('ok', $kind, $text);
        self::assertSame([self::OWNER, self::OTHER], array_map('intval', array_column(self::deliveries(), 'user_id')));
        self::q("UPDATE 202_ltv_webhooks SET subscribed_events = 'revenue.recorded' WHERE user_id = " . self::OTHER);
        try {
            [$kind, $text] = $this->sendTest(self::OTHER);
            self::assertSame('bad', $kind);
            self::assertStringContainsString('reached no channel', $text);
            self::assertStringContainsString('Webhooks: not tried, no webhook subscribes to the alerts.', $text);
        } finally {
            self::q('UPDATE 202_ltv_webhooks SET subscribed_events ='
                . " 'alert.conversions_stopped,alert.spend_without_conversions' WHERE user_id = " . self::OTHER);
        }
        self::assertCount(2, self::deliveries());

        // A channel that fails is named with why, beside the ones that took it.
        $this->slackStatus = 500;
        [$kind, $text] = $this->sendTest(self::OWNER);
        self::assertSame('warn', $kind);
        self::assertStringContainsString('Slack: failed, the webhook answered 500', $text);

        // With alerts off nothing is sent, and the answer says why.
        $this->settings(self::OTHER, new AlertSettings(false, true, true, true, 5, 10, '0.00'));
        $mailed = count($this->mailed);
        [$kind, $text] = $this->sendTest(self::OTHER);
        self::assertSame('warn', $kind);
        self::assertStringContainsString('money-loss alerts are off', $text);
        self::assertCount($mailed, $this->mailed);
    }

    public function testACommandLineRunLinksOnceABrowserHasShownWhereTheInstallIs(): void
    {
        $conn = new Connection(self::$db);
        $root = dirname(__DIR__, 2);
        $cli = ['argv' => []];
        $under = '/' . basename($root) . '/';
        $page = $under . '202-account/account.php#money-loss-alerts';
        $browser = ['DOCUMENT_ROOT' => dirname($root), 'HTTPS' => 'on', 'HTTP_HOST' => 'elsewhere.example.net'];
        self::q('DELETE FROM 202_users_pref WHERE user_id = 1');
        self::row('202_users_pref', ['user_id' => 1, 'user_tracking_domain' => 'alerts.example.com']);
        try {
            self::assertNull(AlertNotifier::settingsUrl($conn, $cli, $root), 'nothing has shown the path yet');

            AlertNotifier::rememberWebAddress($conn, $cli, $root, self::$now);
            self::assertNull(AlertNotifier::settingsUrl($conn, $cli, $root), 'a command-line run shows nothing');

            AlertNotifier::rememberWebAddress($conn, $browser, $root, self::$now);
            self::assertSame(
                'https://alerts.example.com' . $page,
                AlertNotifier::settingsUrl($conn, $cli, $root),
                'the stored domain, never the request\'s host, at the path and scheme the browser found'
            );

            // The hourly run from the command line now links its messages.
            $this->settings(self::OWNER, new AlertSettings(true, false, true, false, 5, 10, '0.00'));
            $this->runAlerts(self::$now, AlertNotifier::settingsUrl($conn, $cli, $root));
            self::assertStringContainsString(
                'https://alerts.example.com' . $under . 'tracking202/setup/inbound_log.php' . "\n",
                $this->mailed[0][2]
            );

            // A domain stored with its scheme keeps it; one without takes the scheme the browser came on.
            AlertNotifier::rememberWebAddress($conn, ['HTTPS' => 'off'] + $browser, $root, self::$now);
            self::assertSame('http://alerts.example.com' . $page, AlertNotifier::settingsUrl($conn, $cli, $root));
            self::q("UPDATE 202_users_pref SET user_tracking_domain = 'https://alerts.example.com' WHERE user_id = 1");
            self::assertSame('https://alerts.example.com' . $page, AlertNotifier::settingsUrl($conn, $cli, $root));

            // An install that no longer sits at the remembered path is not linked to a guess.
            (new MysqlAlertStore($conn))->saveInstallAddress(true, '/moved-away/', self::$now);
            self::assertNull(AlertNotifier::settingsUrl($conn, $cli, $root));

            // No stored domain is no address, whatever a request says.
            AlertNotifier::rememberWebAddress($conn, $browser, $root, self::$now);
            self::q("UPDATE 202_users_pref SET user_tracking_domain = '' WHERE user_id = 1");
            self::assertNull(AlertNotifier::settingsUrl($conn, $cli, $root));
            self::assertNull(AlertNotifier::settingsUrl($conn, $browser, $root));
        } finally {
            self::q('DELETE FROM 202_users_pref WHERE user_id = 1');
        }
    }

    public function testAlertsOnWithEveryChannelOffAreRaisedAndShownInTheAppOnly(): void
    {
        $this->settings(self::OWNER, new AlertSettings(true, false, false, false, 5, 10, '100.00'));

        $report = $this->runAlerts(self::$now);

        self::assertSame(['accounts' => 2, 'sent' => 0, 'failed' => 0, 'unsent' => 3, 'errors' => 0], $report);
        self::assertSame([[], [], []], [$this->posted, $this->mailed, self::deliveries()]);
        $listed = (new MysqlAlertStore(new Connection(self::$db)))->recent(self::OWNER, 0, 10);
        self::assertSame(
            [[MysqlAlertStore::NOWHERE, ''], [MysqlAlertStore::NOWHERE, '']],
            array_map(static fn (array $a): array => [$a['delivery'], $a['delivery_note']], $listed)
        );
        $this->runAlerts(self::$now + 3600);
        self::assertSame([], $this->mailed, 'and not tried again');

        [$kind, $text] = $this->sendTest(self::OWNER);
        self::assertSame('warn', $kind);
        self::assertStringContainsString('shown only on Home and on this card', $text);
    }

    /** @return array{0: string, 1: string} */
    private function sendTest(int $user): array
    {
        $notifier = $this->notifier(new Connection(self::$db));

        return p202_alerts_send_test(self::$db, $user, [], dirname(__DIR__, 2), self::$now, $notifier);
    }

    /** @return array{accounts: int, sent: int, failed: int, unsent: int, errors: int} */
    private function runAlerts(int $now, ?string $settingsUrl = null): array
    {
        $conn = new Connection(self::$db);

        return (new AlertRun($conn, $this->notifier($conn, $settingsUrl)))->run($now, time() + 60);
    }

    private function notifier(Connection $conn, ?string $settingsUrl = null): AlertNotifier
    {
        $transport = function (array $options): array {
            $this->posted[] = $options;

            return ['status' => $this->slackStatus, 'error' => null, 'primary_ip' => self::SLACK_ADDRESS,
                'location' => null, 'body' => $this->slackStatus === 200 ? 'ok' : 'internal_error'];
        };
        $resolve = static fn (string $host): array => $host === 'hooks.slack.com' ? [self::SLACK_ADDRESS] : [];
        // A documentation address stands in for Slack's, admitted as an operator's own network would be.
        $guard = new WebhookGuard($resolve, self::SLACK_ADDRESS . '/32');
        $slack = new SlackWebhook(new WebhookSender($guard, $transport));
        $mailer = function (string $to, string $subject, string $body, string $headers): bool {
            $this->mailed[] = [$to, $subject, $body, $headers];

            return true;
        };

        return new AlertNotifier($conn, $slack, $mailer, $settingsUrl);
    }

    private function settings(int $user, AlertSettings $settings): void
    {
        (new MysqlAlertStore(new Connection(self::$db)))->saveSettings($user, $settings);
    }

    private static function slackText(string $body): string
    {
        self::assertStringStartsWith('payload=', $body);

        return (string) json_decode(rawurldecode(substr($body, 8)), true, 512, JSON_THROW_ON_ERROR)['text'];
    }

    /** @return list<array<string, mixed>> */
    private static function deliveries(): array
    {
        $result = self::$db->query('SELECT user_id, event_name, payload FROM 202_ltv_webhook_deliveries'
            . ' WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ') ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * One conversion per time, each on a click of its own; the last id.
     *
     * @param list<int> $times
     * @param string $set more columns, as SQL (`deleted = 1`)
     */
    private static function conversions(int $user, int $campaign, array $times, string $set = ''): int
    {
        foreach ($times as $time) {
            $click = self::$clickId++;
            self::$convId = self::insert('INSERT INTO 202_conversion_logs SET click_id = ' . $click
                . ', campaign_id = ' . $campaign . ', click_payout = 10, user_id = ' . $user
                . ', click_time = ' . ($time - 60) . ', conv_time = ' . $time
                . ", time_difference = '', pixel_type = 0, user_agent = '', dedupe_key = 'k$click'"
                . ($set !== '' ? ', ' . $set : ''));
        }

        return self::$convId;
    }

    /**
     * One report row per time, a click costing $cost, real unless $filtered.
     *
     * @param list<int> $times
     */
    private static function clicks(int $user, int $campaign, array $times, float $cost = 0.0, int $filtered = 0): void
    {
        $rows = [];
        foreach ($times as $time) {
            $rows[] = '(' . $user . ', ' . self::$clickId++ . ', ' . $time . ', 1, ' . $campaign
                . ', 1, 1, ' . $filtered . ', 0, ' . $cost . ')';
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
                . ' landing_page_id, clicks, click_filtered, payout, cost) VALUES ' . implode(', ', $chunk));
        }
    }

    private static function cleanUp(): void
    {
        $users = self::USERS;
        foreach (
            ['202_alert_state', '202_alert_log', '202_alert_settings', '202_ltv_webhook_deliveries',
            '202_ltv_webhooks', '202_conversion_logs', '202_dataengine', '202_aff_campaigns', '202_users_pref',
            '202_users'] as $table
        ) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }
}

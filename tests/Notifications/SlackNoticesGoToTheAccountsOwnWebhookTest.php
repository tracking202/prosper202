<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * 974K: the pages read the webhook joined on `2up.user_id = 1`, so every account's
 * notices went to account 1's Slack. Each now asks for the session account's own.
 */
final class SlackNoticesGoToTheAccountsOwnWebhookTest extends TestCase
{
    /** A statement that picks account 1's preferences row. */
    private const USER_ONE = '/\buser_id\s*=\s*\'?1\'?(?![0-9])/i';

    /** @var array<string, string> file => why its statement may read account 1's webhook */
    private const USER_ONE_ALLOWED = [
        '202-cronjobs/index.php' => 'Clear Old Clicks deletes on the install-wide setting stored on account 1,'
            . ' and the notice says so to the install\'s owner; it is about no one account\'s change',
    ];

    /**
     * The served files whose notices are for an account other than the
     * session's: file => the account expression its Slack::forAccount() names.
     */
    private const OTHER_ACCOUNT = [
        '202-login.php' => "(int) \$user_row['user_id']",
        '202-cronjobs/index.php' => '1',
        'tracking202/static/cb202.php' => '$outcome->accountId',
    ];

    /** The pages that announce an account's changes, each through Slack::forAccount(). */
    private const PAGES = [
        '202-account/account.php', '202-account/administration.php', '202-account/api-integrations.php',
        '202-account/user-management.php', 'tracking202/ajax/delete_tracker.php',
        'tracking202/ajax/generate_tracking_link.php', 'tracking202/ajax/get_adv_landing_code.php',
        'tracking202/ajax/get_landing_code.php', 'tracking202/ajax/rotator.php',
        'tracking202/setup/_base/SetupController.php', 'tracking202/setup/aff_campaigns.php',
        'tracking202/setup/aff_networks.php', 'tracking202/setup/landing_pages.php',
        'tracking202/setup/ppc_accounts.php', 'tracking202/setup/rotator.php', 'tracking202/setup/text_ads.php',
    ];

    public function testNoStatementReadsAccountOnesWebhookUnlessListed(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('202-account/account.php', $files, 'the scan read the tree');

        $found = [];
        $listedSeen = [];
        foreach ($files as $path => $source) {
            if (stripos($source, 'user_slack_incoming_webhook') === false) {
                continue;
            }
            foreach (AssembledStrings::of($source) as $string) {
                $text = $string['text'];
                if (
                    stripos($text, 'user_slack_incoming_webhook') === false
                    || preg_match(self::USER_ONE, $text) !== 1
                ) {
                    continue;
                }
                if (isset(self::USER_ONE_ALLOWED[$path])) {
                    $listedSeen[$path] = true;
                    continue;
                }
                $found[] = $path . ':' . $string['line'];
            }
        }
        self::assertSame([], $found, 'an account\'s notices go to its own webhook (SlackWebhook::ofAccount()),'
            . ' never account 1\'s');
        foreach (array_keys(self::USER_ONE_ALLOWED) as $path) {
            self::assertArrayHasKey($path, $listedSeen, "$path is listed but reads no webhook of account 1's");
        }
    }

    public function testTheUserOnePatternMatchesTheReadsItReplaced(): void
    {
        $join = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u"
            . " INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '\x00'";
        $where = "SELECT 202_users.user_name AS username, 202_users_pref.user_slack_incoming_webhook AS url"
            . " FROM 202_users_pref LEFT JOIN 202_users ON (202_users.user_id = '\x00')"
            . " WHERE 202_users_pref.user_id='1'";
        self::assertSame(1, preg_match(self::USER_ONE, $join));
        self::assertSame(1, preg_match(self::USER_ONE, $where));
        $bound = 'SELECT user_slack_incoming_webhook FROM 202_users_pref WHERE user_id = ? LIMIT 1';
        self::assertSame(0, preg_match(self::USER_ONE, $bound));
        self::assertSame(0, preg_match(self::USER_ONE, 'WHERE user_id = 12'));
    }

    /**
     * A served file builds its notifier with Slack::forAccount(), never from
     * a URL it read itself: the account's own webhook, and its notices
     * queued (SlackNoticeQueue), so no request waits on Slack.
     */
    public function testNoServedFileBuildsASlackFromAUrl(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_starts_with($path, 'tests/') || $path === '202-config/Slack.class.php') {
                continue;
            }
            if (preg_match('/\bnew\s+\\\\?Slack\s*\(/i', $source) === 1) {
                $found[] = $path;
            }
        }
        self::assertSame([], $found, 'use Slack::forAccount($db, $_SESSION[\'user_id\'])');
    }

    public function testEachPageAsksForTheSessionAccountsOwnWebhook(): void
    {
        $files = SourceScan::phpFiles();
        foreach (self::PAGES as $page) {
            self::assertArrayHasKey($page, $files);
            self::assertStringContainsString('Slack::forAccount(', $files[$page], $page);
        }
        $calls = 0;
        $otherSeen = [];
        foreach ($files as $path => $source) {
            if (str_starts_with($path, 'tests/')) {
                continue;
            }
            $at = 0;
            while (($at = strpos($source, 'Slack::forAccount(', $at)) !== false) {
                $end = strpos($source, ';', $at);
                $call = substr($source, $at, $end === false ? null : $end - $at);
                $at += 1;
                if (isset(self::OTHER_ACCOUNT[$path])) {
                    self::assertStringStartsWith(
                        'Slack::forAccount($db, ' . self::OTHER_ACCOUNT[$path] . ')',
                        $call,
                        "$path: the account its notice is for"
                    );
                    $otherSeen[$path] = true;
                    continue;
                }
                self::assertStringContainsString("\$_SESSION['user_id']", $call, "$path: the account is the session's");
                $calls++;
            }
        }
        self::assertGreaterThanOrEqual(count(self::PAGES), $calls);
        foreach (array_keys(self::OTHER_ACCOUNT) as $path) {
            self::assertArrayHasKey($path, $otherSeen, "$path is listed, asks for no account's Slack");
        }
    }
}

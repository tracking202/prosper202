<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;

/** Account › API keys says what each AI connection's watcher watches, in one plain sentence. */
final class AccountWatcherRuleTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    /**
     * @param array<string, mixed> $watcher
     * @dataProvider rules
     */
    public function testTheRuleReadsAsASentence(array $watcher, string $sentence): void
    {
        self::assertSame($sentence, p202_account_watcher_rule($watcher));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function rules(): array
    {
        return [
            'below breakeven' => [
                ['breakdown' => 'landing_page', 'window' => 'since_start', 'rule' => [
                    'metric' => 'epc', 'comparator' => null, 'threshold' => 30, 'thresholdKind' => 'pctBelowBreakeven',
                    'minClicks' => 500, 'action' => 'notify', 'checkEveryMinutes' => 60,
                ]],
                'Each landing page\'s EPC 30% or more below breakeven, after 500 clicks, since the watcher started.'
                    . ' Checked every hour.',
            ],
            'an absolute ROI' => [
                ['breakdown' => 'ppc_account', 'window' => 'last7', 'rule' => [
                    'metric' => 'roi', 'comparator' => 'lte', 'threshold' => -12.5, 'thresholdKind' => 'absolute',
                    'minClicks' => 1, 'checkEveryMinutes' => 180,
                ]],
                'Each traffic source account\'s ROI at or below -12.5%, after 1 click, over the last 7 days.'
                    . ' Checked every 3 hours.',
            ],
            'the campaign as a whole' => [
                ['breakdown' => 'campaign', 'window' => 'today', 'rule' => [
                    'metric' => 'cvr', 'comparator' => 'gt', 'threshold' => 2, 'thresholdKind' => 'absolute',
                    'minClicks' => 0, 'checkEveryMinutes' => 15,
                ]],
                'The campaign\'s conversion rate above 2%, today. Checked every 15 minutes.',
            ],
            'words a newer server adds' => [
                ['breakdown' => 'ad_group', 'window' => 'last_90', 'rule' => [
                    'metric' => 'cpa', 'comparator' => 'lt', 'threshold' => 4, 'thresholdKind' => 'absolute',
                ]],
                'Each ad group\'s cpa below 4, last 90.',
            ],
            'nothing readable' => [[], 'What it watches was not given.'],
        ];
    }

    public function testTheListEscapesWhatTheServerSaysAndOffersStopOnlyForARunningWatcher(): void
    {
        $_SESSION['token'] = 'session-token';
        $running = [
            'id' => 'wch_0123456789abcdef01234567', 'campaignId' => 42, 'breakdown' => 'campaign', 'window' => 'today',
            'rule' => ['metric' => 'roi', 'comparator' => 'lt', 'threshold' => 0], 'endsAt' => 1762419200000,
            'status' => 'running', 'statusReason' => '', 'alerts' => 1, 'lastAlert' => '<script>x</script>',
        ];
        $failed = ['id' => 'wch_fedcba9876543210fedcba98', 'campaignId' => 7, 'status' => 'failed',
            'statusReason' => 'the key was refused', 'alerts' => 0, 'lastAlert' => null, 'endsAt' => null];

        $names = [42 => 'Spring <sale>'];
        $html = p202_account_render_watchers('k"ey', [$running, $failed], $names, '/a.php#api-keys');

        self::assertStringContainsString('Spring &lt;sale&gt;', $html);
        self::assertStringContainsString('Campaign #7', $html, 'a campaign the account does not own is named by id');
        self::assertStringContainsString('latest: &lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('title="the key was refused"', $html);
        self::assertSame(1, substr_count($html, 'name="stop_ai_watcher"'), 'Stop only for the running one');
        self::assertStringContainsString('name="watcher_id" value="wch_0123456789abcdef01234567"', $html);
        self::assertStringContainsString('name="rest_api_key" value="k&quot;ey"', $html);
        self::assertStringContainsString('name="token" value="session-token"', $html);
        self::assertStringContainsString('0 alerts', $html);
        self::assertStringContainsString('action="/a.php#api-keys"', $html);

        $unread = p202_account_render_watchers('k', null, [], '/a');
        self::assertStringContainsString("Couldn't load watchers right now", $unread);
        self::assertStringContainsString('No watchers.', p202_account_render_watchers('k', [], [], '/a'));
    }
}

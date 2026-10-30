<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every curl_init() outside vendor/ and tests/, listed with whose URL it
 * calls and what makes that safe (8PPJ).
 *
 * Traffic-source postbacks were sent to any address a pixel named, private
 * and metadata ones included, while the export webhooks two directories
 * away resolved, refused and pinned: nothing listed where this server makes
 * a request, so nothing asked each site whose URL it is. A request this
 * server makes to a URL a user or a remote party chose goes through
 * WebhookGuard (checkPostback() or check()) and is pinned; one to a fixed
 * host, or to an operator's own constant, says so
 * here. The sweep's last unguarded entry, the Slack notices, sends through
 * WebhookSender now (SlackWebhook), so none is marked UNGUARDED; a new one
 * is a finding, not an entry.
 *
 * A new site fails until it is listed with its reason; a site removed, or a
 * count changed, fails until the list follows.
 */
final class OutboundCurlCallsAreListedTest extends TestCase
{
    private const FIXED = 'a fixed vendor host (my.tracking202.com) over verified TLS';

    /** @var array<string, array{0: int, 1: string}> path => [curl_init() calls, whose URL, what keeps it safe] */
    private const LISTED = [
        '202-config/Notifications/PostbackSender.php' => [
            1,
            'a traffic source pixel: WebhookGuard::checkPostback() at every hop, pinned, no proxy',
        ],
        '202-config/Attribution/WebhookSender.php' => [
            1,
            'an export webhook, an LTV webhook delivery or a Slack notice (SlackWebhook): WebhookGuard::check(),'
            . ' pinned, no proxy, no redirects',
        ],
        '202-config/GeoIp/GuardedCurlTransport.php' => [
            1,
            "MaxMind's download and each URL it redirects to: WebhookGuard::check() at every hop, PostbackSender's pin, no proxy, no redirects",
        ],
        '202-cronjobs/process_dataengine_job.php' => [2, "this server's own listener: DataEngine\\SelfCall's pin"],
        'tracking202/Apps/StoreListing.php' => [
            1,
            'the stores: fixed origins and icon hosts, https, no redirects; the override is loopback only',
        ],
        'api/v3/Apps/Android/Integrity/GooglePlayIntegrityClient.php' => [
            1,
            "Google's pinned endpoints; the override is loopback only",
        ],
        '202-config/Lpo/PairingClient.php' => [1, 'the operator constant LPO_SAAS_BASE_URL, https only, no redirects'],
        '202-config/Messaging/MessagingClient.class.php' => [
            1,
            'the operator constant MESSAGING_API_URL, https (http only to loopback)',
        ],
        '202-config/Messaging/ChatPassClient.class.php' => [
            1,
            'the operator constant CHAT_PASS_URL, https (http only to loopback)',
        ],
        'cli/ApiClient.php' => [1, "the PHP CLI's own configured server, on the operator's machine"],
        '202-config/FraudDetectionIPQS.class.php' => [1, 'a fixed host, www.ipqualityscore.com'],
        '202-config/User/ExchangeRates.php' => [1, 'a fixed host, ExchangeRates::ENDPOINT'],
        '202-config/License/ClickServerKeyValidator.php' => [1, self::FIXED . ', each redirect held to that host'],
        '202-config/functions-tracking202.php' => [
            25,
            self::FIXED . ', except getData($url): redirects followed, no address check; called with fixed'
            . " URLs and with the update feed's own link (functions.php's auto-upgrade)",
        ],
        '202-config/functions.php' => [3, self::FIXED],
        '202-config/clickserver_api_management.php' => [3, self::FIXED],
        '202-config/functions-tracking202api.php' => [
            1,
            'getUrl(): redirects followed, no address check; called only with a fixed my.tracking202.com URL',
        ],
        '202-cronjobs/dni.php' => [1, self::FIXED],
        '202-cronjobs/daily-email.php' => [1, self::FIXED],
        '202-appstore/index.php' => [1, self::FIXED],
        '202-resources/index.php' => [1, self::FIXED],
        'api/v3/Support/RemoteApiClient.php' => [
            1,
            "a sync profile's url: WebhookGuard::checkSync() at every request, pinned, no proxy, no redirects",
        ],
    ];

    public function testEveryCurlInitIsListedWithWhatKeepsItsUrlSafe(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $calls = self::curlInits($source);
            if ($calls > 0) {
                $found[$path] = $calls;
            }
        }
        self::assertArrayHasKey('202-config/Notifications/PostbackSender.php', $found, 'the scan read the tree');

        self::assertSame([], array_diff_key($found, self::LISTED), 'curl_init() here is not listed: say whose URL'
            . ' it calls and what keeps it safe (a URL someone else chose goes through WebhookGuard,'
            . ' pinned)');
        $listed = array_map(static fn (array $entry): int => $entry[0], self::LISTED);
        ksort($listed);
        ksort($found);
        self::assertSame($listed, $found, 'a listed file gained, lost or no longer has a curl_init(): update the list');
    }

    public function testTheScanCountsEverySpellingOfTheCall(): void
    {
        $source = '<?php $a = curl_init(); $b = \\curl_init($u); $c = CURL_INIT(); '
            . '$d->curl_init(); X::curl_init(); function curl_init_x() {} $e = "curl_init()";';
        self::assertSame(3, self::curlInits($source));
    }

    private static function curlInits(string $source): int
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        $calls = 0;
        foreach ($tokens as $i => $token) {
            if (SourceScan::callableName($token->id, $token->text) !== 'curl_init') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (($tokens[$i + 1] ?? null)?->text !== '(' || ($before !== null && $before->is($notACall))) {
                continue;
            }
            $calls++;
        }

        return $calls;
    }
}

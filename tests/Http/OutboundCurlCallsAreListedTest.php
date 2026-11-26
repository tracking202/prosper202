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
 * WebhookGuard (checkPostback() or check()) and is pinned, or tunnelled
 * to the checked address through the egress proxy; one to a fixed
 * host, or to an operator's own constant, says so
 * here. The sweep's last unguarded entry, the Slack notices, sends through
 * WebhookSender now (SlackWebhook), so none is marked UNGUARDED; a new one
 * is a finding, not an entry.
 *
 * Each entry also names its route (4KFZ): a guarded sender's is
 * EgressProxy::routeOptions(), a fixed host's is FixedHostRoute::exec(),
 * which caps the answer and takes the egress proxy when one is set; only
 * the rebuild's call to this server and the PHP CLI on an operator's machine
 * take neither.
 *
 * A new site fails until it is listed with its reason; a site removed, or a
 * count changed, fails until the list follows.
 */
final class OutboundCurlCallsAreListedTest extends TestCase
{
    private const FIXED = 'a fixed vendor host (my.tracking202.com) over verified TLS';
    private const ROUTED = 'pinned or tunnelled to the checked address (EgressProxy::routeOptions())';

    /** How each call reaches its host. */
    private const GUARDED = 'WebhookGuard, then EgressProxy::routeOptions()';
    private const FIXED_ROUTE = 'FixedHostRoute::exec(): capped, and through the egress proxy when one is set';
    private const SELF_CALL = "direct to this server's own listener (SelfCall), never through the egress proxy";
    private const OPERATOR_MACHINE = "the PHP CLI on the operator's machine, which has no install's settings";

    /** @var array<string, array{0: int, 1: string, 2: string}> path => [curl_init() calls, whose URL and what keeps it safe, its route] */
    private const LISTED = [
        '202-config/Notifications/PostbackSender.php' => [
            1,
            'a traffic source pixel: WebhookGuard::checkPostback() at every hop, ' . self::ROUTED . ',',
            self::GUARDED,
        ],
        '202-config/Setup/LandingPageCheck.php' => [
            1,
            "a landing page's saved URL (Get LP Code's Check my page): WebhookGuard::checkPage() at every hop,"
            . " PostbackSender's options (" . self::ROUTED . '), body capped',
            self::GUARDED,
        ],
        '202-config/Attribution/WebhookSender.php' => [
            1,
            'an export webhook, an LTV webhook delivery or a Slack notice (SlackWebhook): WebhookGuard::check(), '
            . self::ROUTED . ', no redirects',
            self::GUARDED,
        ],
        '202-config/GeoIp/GuardedCurlTransport.php' => [
            1,
            "MaxMind's download and each URL it redirects to: WebhookGuard::check() at every hop, PostbackSender's"
            . ' options (' . self::ROUTED . '), no redirects',
            self::GUARDED,
        ],
        'api/v3/Support/RemoteApiClient.php' => [
            1,
            "a sync profile's url: WebhookGuard::checkSync() at every request, " . self::ROUTED . ', no redirects',
            self::GUARDED,
        ],
        '202-cronjobs/process_dataengine_job.php' => [1, "this server's own listener: DataEngine\\SelfCall's pin", self::SELF_CALL],
        'cli/ApiClient.php' => [1, "the PHP CLI's own configured server, on the operator's machine", self::OPERATOR_MACHINE],
        'tracking202/Apps/StoreListing.php' => [
            1,
            'the stores: fixed origins and icon hosts, https, no redirects; the override is loopback only',
            self::FIXED_ROUTE,
        ],
        'api/v3/Apps/Android/Integrity/GooglePlayIntegrityClient.php' => [
            1,
            "Google's pinned endpoints; the override is loopback only",
            self::FIXED_ROUTE,
        ],
        '202-config/Update/ReleaseSignature.php' => [
            1,
            'the fixed host my.tracking202.com, https only, redirects only to that host',
            self::FIXED_ROUTE,
        ],
        '202-config/Lpo/PairingClient.php' => [
            1,
            'the operator constant LPO_SAAS_BASE_URL, https only, no redirects',
            self::FIXED_ROUTE,
        ],
        '202-config/Messaging/MessagingClient.class.php' => [
            1,
            'the operator constant MESSAGING_API_URL, https (http only to loopback)',
            self::FIXED_ROUTE,
        ],
        '202-config/Messaging/ChatPassClient.class.php' => [
            1,
            'the operator constant CHAT_PASS_URL, https (http only to loopback)',
            self::FIXED_ROUTE,
        ],
        '202-config/FraudDetectionIPQS.class.php' => [1, 'a fixed host, www.ipqualityscore.com', self::FIXED_ROUTE],
        '202-config/Fraud/IpqsClient.php' => [
            1,
            'a fixed host, www.ipqualityscore.com (IpqsClient::ORIGIN), https only, no redirects; the override is'
            . ' loopback only',
            self::FIXED_ROUTE,
        ],
        '202-config/User/ExchangeRates.php' => [
            1,
            'a fixed host, ExchangeRates::ENDPOINT, https, no redirects; the override (P202_EXCHANGE_RATES_ORIGIN) is'
            . ' loopback only',
            self::FIXED_ROUTE,
        ],
        '202-config/License/ClickServerKeyValidator.php' => [1, self::FIXED . ', each redirect held to that host', self::FIXED_ROUTE],
        '202-config/functions-tracking202.php' => [21, self::FIXED, self::FIXED_ROUTE],
        '202-config/Http/VendorFetch.php' => [
            1,
            'getData() and getUrl(): only https://my.tracking202.com (ReleaseSignature::allowedUrl()), each redirect'
            . ' followed by hand and held to that host, the answer capped',
            self::FIXED_ROUTE,
        ],
        '202-config/functions.php' => [3, self::FIXED, self::FIXED_ROUTE],
        '202-config/clickserver_api_management.php' => [3, self::FIXED, self::FIXED_ROUTE],
        '202-appstore/index.php' => [1, self::FIXED, self::FIXED_ROUTE],
        '202-resources/index.php' => [1, self::FIXED, self::FIXED_ROUTE],
        'api/v3/Support/AiWatchers.php' => [
            1,
            "Tracking202's MCP server: AiConnect::relayOrigin(), the fixed https://mcp.tracking202.com or the"
            . ' operator constant P202_MCP_RELAY_ORIGIN, https (http only to loopback), no redirects',
            self::FIXED_ROUTE,
        ],
    ];

    /**
     * Every listed call sends the way its route says: a fixed-host file
     * ends each request in FixedHostRoute::exec() and never calls
     * curl_exec() itself; a guarded one never calls FixedHostRoute (its
     * route is the guard's); the rebuild's self-call and the PHP CLI call
     * neither.
     */
    public function testEveryListedCallTakesTheRouteItNames(): void
    {
        $files = SourceScan::phpFiles();
        foreach (self::LISTED as $path => [$calls, , $route]) {
            self::assertArrayHasKey($path, $files, $path);
            $exec = self::calls($files[$path], 'curl_exec');
            $fixed = substr_count(self::code($files[$path]), 'FixedHostRoute::exec(');
            if ($route === self::FIXED_ROUTE) {
                self::assertSame(0, $exec, "$path calls curl_exec() itself; end the request in FixedHostRoute::exec()");
                self::assertGreaterThanOrEqual(1, $fixed, "$path does not send through FixedHostRoute::exec()");
                self::assertLessThanOrEqual($calls, $fixed, "$path: more FixedHostRoute::exec() than curl_init()");
            } else {
                self::assertSame(0, $fixed, "$path is listed as $route, not as a fixed host");
            }
        }
        self::assertSame(21, substr_count(self::code($files['202-config/functions-tracking202.php']), 'FixedHostRoute::exec('));
    }

    /** Calls of a function by name, read as the scan reads curl_init(). */
    private static function calls(string $source, string $name): int
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        $n = 0;
        foreach ($tokens as $i => $token) {
            if (SourceScan::callableName($token->id, $token->text) !== $name) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (($tokens[$i + 1] ?? null)?->text === '(' && ($before === null || !$before->is($notACall))) {
                $n++;
            }
        }

        return $n;
    }

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

    /**
     * An entry that says WebhookGuard keeps its URL safe names the check, and
     * its file makes that check and takes its route from the one place
     * (EgressProxy::routeOptions(), directly or through
     * PostbackSender::curlOptions()); a file that makes a guard check beside
     * its curl_init() says so. Naming the guard is not being guarded by it
     * (CLAUDE.md #21).
     */
    public function testEveryEntryNamingTheGuardIsGuarded(): void
    {
        $files = SourceScan::phpFiles();
        $guarded = 0;
        foreach (self::LISTED as $path => [, $why, ]) {
            self::assertArrayHasKey($path, $files, $path);
            $code = self::code($files[$path]);
            $checks = preg_match_all('/WebhookGuard::(check(?:Postback|Page|Sync)?)\(\)/', $why, $m) ?: 0;
            $calls = preg_match_all('/->(check(?:Postback|Page|Sync)?)\(/', $code, $c) ?: 0;
            if ($checks === 0) {
                self::assertSame(0, $calls, "$path checks a URL with WebhookGuard but its entry does not say so");
                continue;
            }
            $guarded++;
            foreach ($m[1] as $method) {
                self::assertContains($method, $c[1], "$path's entry names WebhookGuard::$method(), which it does not call");
            }
            self::assertTrue(
                str_contains($code, 'EgressProxy::routeOptions(') || str_contains($code, 'PostbackSender::curlOptions('),
                "$path takes its route from neither EgressProxy::routeOptions() nor PostbackSender::curlOptions()"
            );
        }
        self::assertSame(5, $guarded, 'the guarded senders: WebhookSender, PostbackSender, LandingPageCheck, GuardedCurlTransport, RemoteApiClient');
    }

    private static function code(string $source): string
    {
        $code = '';
        foreach (\PhpToken::tokenize($source) as $t) {
            if (!$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $code .= $t->text;
            }
        }

        return $code;
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

<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Three senders pin an outbound call to a URL someone else chose, each
 * checked by WebhookGuard: Attribution\WebhookSender (export webhooks,
 * every LTV webhook delivery through Ltv\DeliverySender, Slack notices),
 * Notifications\PostbackSender (traffic-source postbacks) and
 * Api\V3\Support\RemoteApiClient (a sync's two remotes: GET, PUT and
 * DELETE as well as POST, answers of megabytes). Each names the hardening
 * set itself, and takes the route (the pin, the proxy or the absence of
 * one) from one place, Http\EgressProxy::routeOptions(), laid over its
 * options last so nothing it sets can loosen them (4KFZ). No other file
 * names CURLOPT_RESOLVE, CURLOPT_CONNECT_TO or CURLOPT_HTTPPROXYTUNNEL, and
 * the three never name the proxy options themselves: the proxy pair was the
 * drift once already (one sender had it and the LTV webhooks' own guard did
 * not, and a proxy in the environment then resolves the host and ignores
 * the pin), and the egress proxy is a second way to get it wrong.
 * DataEngine\SelfCall (the report rebuild's call to this server's own
 * listener, which no SSRF guard would approve, which may be plain http, and
 * which never goes out through the egress proxy) pins itself and names the
 * whole set. Presence only; the values are in WebhookSenderTest,
 * PostbackSenderTest, RemoteApiClientTest, SelfCallTest and EgressProxyTest.
 *
 * The LTV webhooks had a guard and an option set of their own
 * (Validation\OutboundUrlGuard), which approved 168.63.129.16 and IPv6
 * spellings of loopback and the metadata address (J0F7). They send through
 * WebhookSender now, and nothing LTV sets a curl option: a second set beside
 * the sender's is how the two came apart. The rebuild's cron sets nothing
 * but the URL beside SelfCall's pin: its own defaults were merged in front
 * of the pin and turned redirects on.
 *
 * Setup\LandingPageCheck (Get LP Code's Check my page) and
 * GeoIp\GuardedCurlTransport (the MaxMind download) take every option from
 * PostbackSender::curlOptions(), the egress proxy passed in, and add none of
 * the route's.
 */
final class PinnedRequestsCarryTheHardeningSetTest extends TestCase
{
    /** The senders whose target WebhookGuard approved. */
    private const GUARDED = [
        '202-config/Attribution/WebhookSender.php',
        '202-config/Notifications/PostbackSender.php',
        'api/v3/Support/RemoteApiClient.php',
    ];

    private const ROUTE_FILE = '202-config/Http/EgressProxy.php';
    private const SELF_CALL = '202-config/DataEngine/SelfCall.php';

    /**
     * Test stand-ins that move a pinned loopback connection to their port,
     * set only when an environment variable names the stand-in. path => option
     */
    private const STAND_INS = [
        '202-config/AdPlatforms/OpenAiAds/AdvertiserApi.php' => 'CURLOPT_CONNECT_TO',
    ];

    /** Options only the route sets. */
    private const ROUTE_ONLY = ['CURLOPT_RESOLVE', 'CURLOPT_CONNECT_TO', 'CURLOPT_HTTPPROXYTUNNEL'];

    /** Options the route sets and a guarded sender must not. */
    private const ROUTE = ['CURLOPT_RESOLVE', 'CURLOPT_CONNECT_TO', 'CURLOPT_HTTPPROXYTUNNEL', 'CURLOPT_PROXY', 'CURLOPT_NOPROXY'];

    /** Where each sender hands the proxy it read to its options: a default of none is never taken by omission. */
    private const PROXY_PASSED = [
        '202-config/Attribution/WebhookSender.php' => 'self::curlOptions($target,$body??\'\',$headers,$left,$proxy)',
        '202-config/Notifications/PostbackSender.php' => 'self::curlOptions($target,$left,$proxy)',
        'api/v3/Support/RemoteApiClient.php' => 'self::curlOptions($target,$method,$url,$headers,$body,$left,$proxy)',
        '202-config/Setup/LandingPageCheck.php' => 'self::curlOptions($target,$left,$proxy)',
    ];

    private const HARDENING = [
        'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT',
    ];

    public function testEveryPinnedDispatcherCarriesTheWholeHardeningSet(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('202-cronjobs/ltv_webhooks.php', $files, 'the scan read the tree');

        foreach ($files as $path => $source) {
            if ($path === self::ROUTE_FILE || $path === self::SELF_CALL) {
                continue;
            }
            foreach (self::ROUTE_ONLY as $option) {
                if (isset(self::curlNames($source)[$option]) && (self::STAND_INS[$path] ?? null) !== $option) {
                    self::fail("$path sets $option itself; send through WebhookSender (or PostbackSender) so the"
                        . ' route comes from EgressProxy::routeOptions() and the hardening cannot diverge.');
                }
            }
        }

        self::assertArrayHasKey(self::ROUTE_FILE, $files, self::ROUTE_FILE . ' is gone; update this test');
        $route = self::curlNames($files[self::ROUTE_FILE]);
        foreach (self::ROUTE as $option) {
            self::assertArrayHasKey($option, $route, self::ROUTE_FILE . " does not set $option");
        }

        foreach (self::GUARDED as $path) {
            self::assertArrayHasKey($path, $files, "$path is gone; update this test");
            $names = self::curlNames($files[$path]);
            foreach (self::HARDENING as $option) {
                self::assertArrayHasKey($option, $names, "$path does not set $option");
            }
            foreach (self::ROUTE as $option) {
                self::assertArrayNotHasKey($option, $names, "$path sets $option beside EgressProxy::routeOptions()");
            }
            $code = self::code($files[$path]);
            self::assertSame(1, substr_count($code, 'EgressProxy::routeOptions('), "$path takes its route once");
            self::assertStringContainsString(
                'returnarray_replace($options,EgressProxy::routeOptions($target,$proxy));',
                $code,
                "$path lays the route over its options last"
            );
            self::assertStringContainsString('$proxy=EgressProxy::configured();', $code, "$path reads the egress proxy");
        }

        self::assertArrayHasKey(self::SELF_CALL, $files, self::SELF_CALL . ' is gone; update this test');
        $names = self::curlNames($files[self::SELF_CALL]);
        foreach ([...self::HARDENING, 'CURLOPT_RESOLVE', 'CURLOPT_PROXY', 'CURLOPT_NOPROXY'] as $option) {
            self::assertArrayHasKey($option, $names, self::SELF_CALL . " does not set $option");
        }

        foreach ([
            '202-config/Setup/LandingPageCheck.php' => 'PostbackSender::curlOptions($target,min(self::TIMEOUT,$timeout),$proxy)',
            '202-config/GeoIp/GuardedCurlTransport.php' => 'PostbackSender::curlOptions($guard->check($url,$ports),$timeout,$proxy)',
        ] as $path => $call) {
            self::assertArrayHasKey($path, $files, "$path is gone; update this test");
            $code = self::code($files[$path]);
            self::assertStringContainsString($call, $code, $path . " pins through PostbackSender's options");
            self::assertStringContainsString('$proxy=EgressProxy::configured();', $code, "$path reads the egress proxy");
            $names = self::curlNames($files[$path]);
            foreach (self::ROUTE as $option) {
                self::assertArrayNotHasKey($option, $names, "$path sets $option beside PostbackSender's route");
            }
        }

        foreach (self::PROXY_PASSED as $path => $call) {
            self::assertStringContainsString($call, self::code($files[$path]), "$path hands the egress proxy to its options");
        }

        $cron = '202-cronjobs/process_dataengine_job.php';
        self::assertArrayHasKey($cron, $files, "$cron is gone; update this test");
        self::assertStringContainsString('SelfCall::curlOptions(', $files[$cron], $cron . " uses SelfCall's options");
        $set = array_filter(
            self::curlNames($files[$cron]),
            static fn (string $name): bool => str_starts_with($name, 'CURLOPT_'),
            ARRAY_FILTER_USE_KEY
        );
        self::assertSame(['CURLOPT_URL'], array_keys($set), $cron . " sets options beside SelfCall's pin");
    }

    /**
     * Every LTV webhook request is WebhookSender's: no file of the LTV code
     * or its cron names a curl function or option, and the dispatcher (which
     * both cron entry points run) sends each delivery through DeliverySender,
     * which hands it to WebhookSender::post().
     */
    public function testTheLtvWebhooksSendOnlyThroughTheWebhookSender(): void
    {
        $files = SourceScan::phpFiles();
        $ltv = array_filter(
            $files,
            static fn (string $path): bool
                => str_starts_with($path, '202-config/Ltv/') || $path === '202-cronjobs/ltv_webhooks.php',
            ARRAY_FILTER_USE_KEY
        );
        self::assertArrayHasKey('202-config/Ltv/DeliverySender.php', $ltv, 'the scan read the LTV code');
        foreach ($ltv as $path => $source) {
            $curl = array_filter(
                self::curlNames($source),
                static fn (string $name): bool => str_starts_with($name, 'curl_') || str_starts_with($name, 'CURL'),
                ARRAY_FILTER_USE_KEY
            );
            self::assertSame([], array_keys($curl), $path . ' calls curl; DeliverySender sends through WebhookSender');
        }

        $code = self::code($files['202-config/Ltv/DeliverySender.php']);
        self::assertStringContainsString('$this->sender??=newWebhookSender();$sent=$this->sender->post(', $code);
        self::assertStringContainsString('MysqlWebhookRepository::PORTS);', $code);
        $dispatcher = self::code($files['202-config/Ltv/WebhookDispatcher.php']);
        self::assertStringContainsString('return(newDeliverySender())->post($delivery);', $dispatcher);
        self::assertStringContainsString('return DeliverySender::headers($delivery);', self::spaced($files['202-config/Ltv/WebhookDispatcher.php']));
        self::assertStringContainsString('self::post(...)', $dispatcher, 'the run sends through post()');
    }

    /**
     * The names a source uses as code, case-folded the way PHP calls a
     * function (curl_* lower, CURLOPT_* as written): a docblock that names
     * an option sets nothing.
     *
     * @return array<string, true>
     */
    private static function curlNames(string $source): array
    {
        $names = [];
        foreach (\PhpToken::tokenize($source) as $t) {
            if ($t->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($t->text, '\\');
                $names[stripos($name, 'curl_') === 0 ? strtolower($name) : $name] = true;
            }
        }

        return $names;
    }

    private static function spaced(string $source): string
    {
        return (string) preg_replace('/\s+/', ' ', $source);
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
}

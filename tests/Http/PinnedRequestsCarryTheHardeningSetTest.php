<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Three implementations pin an outbound call to a URL someone else chose:
 * Attribution\WebhookSender (export webhooks, and every LTV webhook
 * delivery, through Ltv\DeliverySender), Notifications\PostbackSender
 * (traffic-source postbacks) and DataEngine\SelfCall (the report rebuild's
 * call to this server's own listener, which no SSRF guard would approve and
 * which may be plain http). What keeps them from drifting is this: no other
 * file pins curl itself, and each names every option in the hardening set.
 * Presence only; the values are in WebhookSenderTest, DeliverySenderTest,
 * PostbackSenderTest and SelfCallTest. The proxy pair is in the set because
 * it was the drift: one sender had it and the LTV webhooks' guard did not,
 * and without it a proxy in the environment resolves the host and the pin is
 * ignored.
 *
 * The LTV webhooks had a guard and an option set of their own
 * (Validation\OutboundUrlGuard), which approved 168.63.129.16 and IPv6
 * spellings of loopback and the metadata address (J0F7). They send through
 * WebhookSender now, and nothing LTV sets a curl option: a second set beside
 * the sender's is how the two came apart. The rebuild's cron sets nothing
 * but the URL beside SelfCall's pin: its own defaults were merged in front
 * of the pin and turned redirects on.
 */
final class PinnedRequestsCarryTheHardeningSetTest extends TestCase
{
    private const SANCTIONED = [
        '202-config/Attribution/WebhookSender.php',
        '202-config/Notifications/PostbackSender.php',
        '202-config/DataEngine/SelfCall.php',
    ];

    private const HARDENING = [
        'CURLOPT_RESOLVE', 'CURLOPT_PROXY', 'CURLOPT_NOPROXY', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS',
        'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT',
    ];

    public function testEveryPinnedDispatcherCarriesTheWholeHardeningSet(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('202-cronjobs/ltv_webhooks.php', $files, 'the scan read the tree');

        foreach ($files as $path => $source) {
            if (isset(self::curlNames($source)['CURLOPT_RESOLVE']) && !in_array($path, self::SANCTIONED, true)) {
                self::fail("$path sets CURLOPT_RESOLVE itself; send through WebhookSender (or PostbackSender)"
                    . ' so the pin and the rest of the hardening cannot diverge.');
            }
        }

        foreach (self::SANCTIONED as $path) {
            self::assertArrayHasKey($path, $files, "$path is gone; update this test");
            $names = self::curlNames($files[$path]);
            foreach (self::HARDENING as $option) {
                self::assertArrayHasKey($option, $names, "$path does not set $option");
            }
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

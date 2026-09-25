<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Sends one export to its webhook, safely (plan §6.3 "Exports", §7.1).
 *
 * The URL is checked again here, at send time, by WebhookGuard — which
 * resolves the host and checks every address — and the connection is then
 * made to the address the guard approved, with the host pinned through
 * CURLOPT_RESOLVE, so a DNS answer that changes after the check cannot
 * move the request. TLS is verified against the host name, as a browser
 * would. Nothing else is left to the environment either: no proxy (an
 * inherited https_proxy would resolve the name itself and undo the pin),
 * https only, redirects never followed, a connect and a total timeout, a
 * cap on the body sent and on the response read.
 *
 * After the transfer, the address curl actually connected to is compared
 * with the pinned one; a difference is reported as a failure, because it
 * would mean the pin did not hold.
 *
 * The body is the export's CSV, signed: `X-P202-Signature: sha256=<hex>`,
 * the HMAC-SHA256 of "<X-P202-Timestamp>.<body>" under the export's secret.
 * A receiver recomputes it and refuses a timestamp more than a few minutes
 * old, which stops a captured request being replayed.
 */
final class WebhookSender
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 15;
    /** Largest CSV sent as a webhook body; a larger export is downloaded instead. */
    public const MAX_BODY_BYTES = 5 * 1024 * 1024;
    /** Response bytes read before the transfer is cut off. */
    public const MAX_RESPONSE_BYTES = 65536;
    /** Response bytes kept for the error message. */
    private const KEEP_RESPONSE_BYTES = 300;

    private ?WebhookGuard $guard;
    /** @var callable(array<int, mixed>): array{status: int, error: string|null, primary_ip: string|null, location: string|null, body: string} */
    private $transport;

    /**
     * @param (callable(array<int, mixed>): array{status: int, error: string|null, primary_ip: string|null, location: string|null, body: string})|null $transport
     */
    public function __construct(?WebhookGuard $guard = null, ?callable $transport = null)
    {
        // Built at send time when not given: a malformed operator allowlist
        // must fail the webhook deliveries (loudly, per job), not the
        // construction of every export run.
        $this->guard = $guard;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Deliver the body. Never throws for a delivery failure: the result says
     * what happened, and whether trying again could change it.
     */
    public function send(string $url, string $secret, string $body, int $exportId, int $attempt, ?int $timestamp = null): WebhookResult
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return WebhookResult::failed(null, 'The export is ' . strlen($body) . ' bytes, more than the '
                . self::MAX_BODY_BYTES . ' a webhook carries; download it instead.', false);
        }
        try {
            $this->guard ??= new WebhookGuard();
            $target = $this->guard->check($url);
        } catch (WebhookRefused $e) {
            return WebhookResult::failed(null, 'Not sent: ' . $e->getMessage(), false);
        } catch (\UnexpectedValueException $e) {
            return WebhookResult::failed(null, 'Not sent: ' . $e->getMessage(), false);
        }

        $timestamp ??= time();
        $headers = [
            'Content-Type: text/csv; charset=utf-8',
            'User-Agent: Prosper202-Webhook/1',
            'X-P202-Event: attribution.export.completed',
            'X-P202-Export-Id: ' . $exportId,
            'X-P202-Delivery-Attempt: ' . $attempt,
            'X-P202-Timestamp: ' . $timestamp,
            'X-P202-Signature: ' . self::signature($secret, $timestamp, $body),
            // No "Expect: 100-continue" round trip.
            'Expect:',
        ];

        $response = ($this->transport)(self::curlOptions($target, $body, $headers));
        $status = $response['status'];

        if ($response['primary_ip'] !== null && $response['primary_ip'] !== '' && !self::sameAddress($response['primary_ip'], $target->pinned())) {
            return WebhookResult::failed($status ?: null, 'The connection went to ' . $response['primary_ip']
                . ' instead of the checked address ' . $target->pinned() . '; treated as not delivered.', false);
        }
        if ($response['error'] !== null && $status === 0) {
            return WebhookResult::failed(null, 'Not delivered: ' . $response['error'], true);
        }
        if ($status >= 300 && $status < 400) {
            return WebhookResult::failed($status, 'The receiver answered ' . $status . ' (a redirect'
                . ($response['location'] !== null ? ' to ' . mb_substr($response['location'], 0, 200) : '')
                . '). Redirects are not followed; point the webhook at the final URL.', false);
        }
        if ($status >= 200 && $status < 300) {
            return WebhookResult::delivered($status);
        }

        $snippet = trim(mb_substr((string) preg_replace('/\s+/', ' ', $response['body']), 0, self::KEEP_RESPONSE_BYTES));

        return WebhookResult::failed(
            $status ?: null,
            'The receiver answered ' . $status . ($snippet !== '' ? ': ' . $snippet : '') . '.',
            $status >= 500 || $status === 408 || $status === 429
        );
    }

    /**
     * Every curl option the delivery uses, from the checked target. Pure, so
     * a test can read exactly what the connection is allowed to do.
     *
     * @param list<string> $headers
     * @return array<int, mixed>
     */
    public static function curlOptions(WebhookTarget $target, string $body, array $headers): array
    {
        $options = [
            CURLOPT_URL => $target->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_NOSIGNAL => true,
        ];
        $resolve = $target->resolveEntry();
        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }

        return $options;
    }

    /**
     * The real transfer. Reads at most MAX_RESPONSE_BYTES of the answer and
     * keeps the Location header of a redirect for the message.
     *
     * @param array<int, mixed> $options
     * @return array{status: int, error: string|null, primary_ip: string|null, location: string|null, body: string}
     */
    public static function curlTransport(array $options): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'error' => 'curl could not be initialised', 'primary_ip' => null, 'location' => null, 'body' => ''];
        }
        $body = '';
        $read = 0;
        $location = null;
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$read): int {
            $read += strlen($chunk);
            if ($read > self::MAX_RESPONSE_BYTES) {
                return 0; // abort: the answer is larger than anything a receiver needs to say
            }
            if (strlen($body) < self::KEEP_RESPONSE_BYTES) {
                $body .= substr($chunk, 0, self::KEEP_RESPONSE_BYTES - strlen($body));
            }

            return strlen($chunk);
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$location): int {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }

            return strlen($line);
        };
        if (!curl_setopt_array($ch, $options)) {
            $error = curl_error($ch);
            curl_close($ch);

            return ['status' => 0, 'error' => 'curl refused an option: ' . $error, 'primary_ip' => null, 'location' => null, 'body' => ''];
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = $ok === false ? curl_error($ch) : null;
        curl_close($ch);

        // A response cut off at the cap still carries its status line: the
        // receiver's answer is known, only its body was not read to the end.
        return [
            'status' => $status,
            'error' => $error,
            'primary_ip' => is_string($primary) && $primary !== '' ? $primary : null,
            'location' => $location,
            'body' => $body,
        ];
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $x = @inet_pton($a);
        $y = @inet_pton($b);

        return $x !== false && $y !== false && $x === $y;
    }
}

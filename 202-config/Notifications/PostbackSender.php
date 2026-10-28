<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Attribution\WebhookTarget;
use Prosper202\Attribution\WebhookUnresolved;

/**
 * The one sender of server-to-server traffic-source postbacks: the
 * notification outbox's worker (plan §5.2 step 6), which gpb.php and upx.php
 * send their conversions' postbacks through, and TrafficSourcePixels::fire().
 *
 * A postback URL is typed by anyone who can edit a traffic source account,
 * and gpb.php sends one for anybody who knows a click id, so the server
 * calls it on a stranger's word. Every URL is therefore checked when it is
 * sent by WebhookGuard::checkPostback() — the export webhooks' guard, with
 * http:// and any port allowed — which resolves the host and refuses
 * private, loopback, link-local, metadata and the other non-public
 * addresses (P202_WEBHOOK_ALLOW_NETWORKS admits an operator's own network),
 * and the connection is made to the address it approved, pinned with
 * CURLOPT_RESOLVE, with no proxy (one would resolve the name itself).
 *
 * Redirects are followed by hand, at most MAX_REDIRECTS, each hop checked
 * and pinned as the first was: curl's own following would resolve each new
 * name itself. They are followed at all because the sender always has, and
 * a network that moved its endpoint (http to https, a new host) answers its
 * old URL with a redirect; refusing those would stop postbacks that work.
 * The whole chain shares TIMEOUT, as it did when curl followed it.
 *
 * The answer says whether the network heard it (PostbackResult): delivered
 * on a 2xx or 3xx status, refused when a check said no, failed otherwise.
 *
 * @phpstan-type Answer array{status: int, error: string|null, primary_ip: string|null, redirect_url: string|null}
 */
final class PostbackSender
{
    public const USER_AGENT = 'Mozilla/5.0 Postback202-Bot v1.8';
    public const CONNECT_TIMEOUT = 5;
    /** Seconds for the whole postback, redirects included. */
    public const TIMEOUT = 10;
    public const MAX_REDIRECTS = 5;
    /** Response bytes read before the transfer is cut off; the status is all a postback needs. */
    public const MAX_RESPONSE_BYTES = 65536;

    private ?WebhookGuard $guard;
    /** @var callable(array<int, mixed>): Answer */
    private $transport;

    /** @param (callable(array<int, mixed>): Answer)|null $transport */
    public function __construct(?WebhookGuard $guard = null, ?callable $transport = null)
    {
        // Built at send time when not given: an allowlist that does not
        // parse fails each postback, not the construction of the outbox.
        $this->guard = $guard;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Never throws: a postback that cannot be sent fails on its own (CLAUDE.md #24). */
    public function send(string $url): PostbackResult
    {
        try {
            return $this->deliver($url);
        } catch (\Throwable $e) {
            return PostbackResult::failed('the postback sender failed: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function deliver(string $url): PostbackResult
    {
        try {
            $this->guard ??= new WebhookGuard();
        } catch (\UnexpectedValueException $e) {
            // The operator's setting: nothing is sent until it is fixed, and
            // the postback is kept to retry.
            return PostbackResult::failed('not sent: ' . $e->getMessage());
        }

        $deadline = microtime(true) + self::TIMEOUT;
        $hop = $url;
        for ($redirects = 0;; $redirects++) {
            $via = $redirects === 0 ? '' : 'redirected to ' . self::origin($hop) . ', which was not followed: ';
            try {
                $target = $this->guard->checkPostback($hop);
            } catch (WebhookUnresolved $e) {
                return PostbackResult::failed($via . $e->getMessage());
            } catch (WebhookRefused $e) {
                return PostbackResult::refused(($redirects === 0 ? 'not sent: ' : $via) . $e->getMessage());
            }

            $left = (int) ceil($deadline - microtime(true));
            if ($left < 1) {
                return PostbackResult::failed('not delivered: the postback took more than '
                    . self::TIMEOUT . ' seconds over ' . $redirects . ' redirects');
            }
            $response = ($this->transport)(self::curlOptions($target, $left));
            $status = $response['status'];
            $connected = $response['primary_ip'];

            if ($connected !== null && $connected !== '' && !self::sameAddress($connected, $target->pinned())) {
                return PostbackResult::refused('the connection to ' . self::origin($hop) . ' went to ' . $connected
                    . ' instead of the checked address ' . $target->pinned()
                    . '; the pin did not hold, so it is not tried again');
            }
            if ($status === 0) {
                return PostbackResult::failed('not delivered to ' . self::origin($hop) . ': '
                    . ($response['error'] ?? 'no answer'));
            }
            $location = $response['redirect_url'];
            if ($status >= 300 && $status < 400 && $location !== null && $location !== '') {
                if ($redirects >= self::MAX_REDIRECTS) {
                    $why = 'not delivered: more than ' . self::MAX_REDIRECTS . ' redirects';

                    return PostbackResult::failed($why, $status);
                }
                $hop = $location;
                continue;
            }
            if ($status >= 200 && $status < 400) {
                return PostbackResult::delivered($status);
            }

            return PostbackResult::failed(self::origin($hop) . ' answered ' . $status, $status);
        }
    }

    /**
     * Every curl option one hop uses, from the checked target. Pure, so a
     * test can read exactly what the connection is allowed to do.
     *
     * @return array<int, mixed>
     */
    public static function curlOptions(WebhookTarget $target, int $timeout = self::TIMEOUT): array
    {
        $options = [
            CURLOPT_URL => $target->url,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Each redirect is checked and pinned by send(), never by curl.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => max(1, min(self::CONNECT_TIMEOUT, $timeout)),
            CURLOPT_TIMEOUT => max(1, min(self::TIMEOUT, $timeout)),
            CURLOPT_NOSIGNAL => true,
        ];
        $resolve = $target->resolveEntry();
        if ($resolve !== null) {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }

        return $options;
    }

    /**
     * The real transfer: the status, where curl connected, and where a
     * redirect points (absolute, as curl resolves it), reading at most
     * MAX_RESPONSE_BYTES of the body.
     *
     * @param array<int, mixed> $options
     * @return Answer
     */
    public static function curlTransport(array $options): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return self::noAnswer('curl could not be initialised');
        }
        $read = 0;
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$read): int {
            $read += strlen($chunk);

            return $read > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
        };
        if (!curl_setopt_array($ch, $options)) {
            $error = curl_error($ch);
            curl_close($ch);

            return self::noAnswer('curl refused an option: ' . $error);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primary = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = $ok === false ? curl_error($ch) : null;
        curl_close($ch);

        // A body cut off at the cap still came with its status line.
        return [
            'status' => $status,
            'error' => $error,
            'primary_ip' => is_string($primary) && $primary !== '' ? $primary : null,
            'redirect_url' => is_string($redirect) && $redirect !== '' ? $redirect : null,
        ];
    }

    /**
     * What a save refuses in a postback pixel's code or its correction URLs
     * (space-separated URLs, [[tokens]] unfilled): what checkPostback()
     * would refuse at send time and can tell without a resolver — a scheme
     * other than http(s), a user name or password, an address or a local
     * name it never calls. Null when nothing. A URL $held already holds is
     * taken as it is, so a pixel stored before this check is not refused
     * for being sent back unchanged; the sender still refuses it.
     */
    public static function refusalAtSave(string $urls, string $held = ''): ?string
    {
        try {
            $guard = new WebhookGuard();
        } catch (\UnexpectedValueException) {
            // An allowlist that does not parse admits nothing.
            $guard = new WebhookGuard(null, '');
        }
        $kept = NotificationOutbox::destinations($held);
        $list = NotificationOutbox::destinations($urls);
        foreach ($list as $i => $url) {
            if (in_array($url, $kept, true)) {
                continue;
            }
            $why = $guard->postbackRefusal($url, true);
            if ($why !== null) {
                return (count($list) > 1 ? 'URL ' . ($i + 1) . ': ' : '') . $why;
            }
        }

        return null;
    }

    /**
     * A URL's scheme and host (and port), for a log line or a reason: never
     * its path or query, which can carry a network's key, nor a user name
     * or password.
     */
    public static function origin(string $url): string
    {
        $at = strpos($url, '://');
        if ($at === false) {
            return '(not a URL)';
        }
        $rest = substr($url, $at + 3);
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $credentials = strrpos($authority, '@');
        if ($credentials !== false) {
            $authority = substr($authority, $credentials + 1);
        }

        $origin = strtolower(substr($url, 0, $at)) . '://' . $authority;
        if (strlen($origin) > 200) {
            $origin = substr($origin, 0, 200) . '...';
        }

        // Printable, so a URL cannot write a line of its own into the log.
        return (string) preg_replace('/[^\x21-\x7E]/', '?', $origin);
    }

    /** @return Answer */
    private static function noAnswer(string $error): array
    {
        return ['status' => 0, 'error' => $error, 'primary_ip' => null, 'redirect_url' => null];
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $x = @inet_pton($a);
        $y = @inet_pton($b);

        return $x !== false && $y !== false && $x === $y;
    }
}

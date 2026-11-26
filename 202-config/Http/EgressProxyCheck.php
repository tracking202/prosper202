<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Attribution\WebhookUnresolved;

/**
 * Account › Settings' Test connection: one request as a webhook is sent,
 * checked by WebhookGuard, looked up by this server and tunnelled to the
 * address that passed through the proxy given, to a fixed public page of
 * my.tracking202.com. Any answer from that server, over TLS verified
 * against its name, says the proxy works; the reason it did not is said in
 * the words an admin can act on.
 */
final class EgressProxyCheck
{
    public const URL = 'https://my.tracking202.com/api/v2/premium-p202/version';
    private const HOST = 'my.tracking202.com';
    private const TIMEOUT = 10;

    /** @var callable(array<int, mixed>): array{status: int, error: string|null, primary_ip: string|null, location: string|null, body: string} */
    private $transport;

    /** @param (callable(array<int, mixed>): array{status: int, error: string|null, primary_ip: string|null, location: string|null, body: string})|null $transport */
    public function __construct(private readonly ?WebhookGuard $guard = null, ?callable $transport = null)
    {
        $this->transport = $transport ?? static fn (array $options): array => WebhookSender::curlTransport($options, 4096, 512);
    }

    /** @return array{ok: bool, message: string} */
    public function run(EgressProxy $proxy): array
    {
        $via = ' through ' . $proxy->label();
        try {
            $target = ($this->guard ?? new WebhookGuard())->check(self::URL, [443]);
        } catch (WebhookUnresolved $e) {
            return self::failed('This server could not look up ' . self::HOST . ' itself, so nothing was sent. The'
                . ' egress proxy carries the requests, but this server still looks up every host it sends to; it'
                . ' needs a working DNS resolver.');
        } catch (WebhookRefused | \UnexpectedValueException $e) {
            return self::failed('Nothing was sent: ' . $e->getMessage());
        }
        if (!EgressProxy::tunnels($target, $proxy)) {
            return self::failed(self::HOST . ' resolved to ' . $target->pinned() . ', an address on your own network,'
                . ' which is reached directly rather than' . $via . '; the proxy was not tested.');
        }
        $options = WebhookSender::curlOptions($target, '', ['Accept: application/json'], self::TIMEOUT, $proxy);
        unset($options[CURLOPT_POST], $options[CURLOPT_POSTFIELDS]);
        $options[CURLOPT_HTTPGET] = true;
        $answer = ($this->transport)($options);

        $peer = EgressProxy::expectedPeer($target, $proxy);
        $connected = $answer['primary_ip'];
        if ($peer !== null && $connected !== null && $connected !== '' && @inet_pton($connected) !== @inet_pton($peer)) {
            return self::failed('The connection went to ' . $connected . ' instead of the proxy at ' . $peer . '.');
        }
        if ($answer['status'] > 0) {
            return ['ok' => true, 'message' => 'The proxy works: ' . self::HOST . ' answered HTTP ' . $answer['status']
                . $via . ', tunnelled to ' . $target->pinned() . ', the address this server looked up.'];
        }

        return self::failed(self::reason((string) $answer['error'], $proxy, $target->pinned()));
    }

    /** What curl's error means for an admin with a proxy to fix. */
    public static function reason(string $error, EgressProxy $proxy, string $address): string
    {
        $where = $proxy->label();
        if (preg_match('/CONNECT tunnel failed, response (\d{3})/', $error, $m) === 1) {
            return match ($m[1]) {
                '407' => 'The proxy at ' . $where . ' asked for a user name and password (HTTP 407).'
                    . ($proxy->hasCredentials() ? ' The ones stored were refused; check them.' : ' Add them under Advanced.'),
                '403', '405' => 'The proxy at ' . $where . ' refused to open a tunnel to ' . $address . ':443 (HTTP '
                    . $m[1] . '). Allow CONNECT to port 443 (and 80 for http postbacks) on the proxy.',
                default => 'The proxy at ' . $where . ' answered HTTP ' . $m[1] . ' when asked to open a tunnel to '
                    . $address . ':443.',
            };
        }
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, 'could not resolve proxy') => 'The proxy\'s host name in ' . $where
                . ' does not resolve from this server.',
            str_contains($lower, 'connection refused'), str_contains($lower, 'failed to connect')
                => 'Nothing answered at ' . $where . ': check the host and port, and that this server may reach it.',
            str_contains($lower, 'timed out'), str_contains($lower, 'timeout')
                => 'The request through ' . $where . ' timed out after ' . self::TIMEOUT . ' seconds.',
            str_contains($lower, 'ssl'), str_contains($lower, 'certificate')
                => 'The TLS connection through ' . $where . ' failed (' . $error . '). A proxy that replaces'
                    . ' certificates cannot carry these requests: they are verified against the real host.',
            default => 'The request through ' . $where . ' failed: ' . ($error !== '' ? $error : 'no answer') . '.',
        };
    }

    /** @return array{ok: false, message: string} */
    private static function failed(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}

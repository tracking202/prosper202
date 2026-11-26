<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookTarget;

/**
 * The egress proxy, for an install that reaches the internet only through
 * one: set by an admin on Account › Settings (EgressProxyStore), or by the
 * operator with `define('P202_EGRESS_PROXY', 'http://proxy.example:3128');`
 * in 202-config.php, which overrides the saved one. Not to be confused with
 * the trusted proxies in front of this server, which say who a visitor is;
 * this one is what the server's own requests go out through.
 *
 * routeOptions() is the one place a request to a URL someone else chose is
 * routed: the guard (WebhookGuard) has resolved the host and approved every
 * address on this server, and the connection must reach the address it
 * approved. Direct, that is CURLOPT_RESOLVE with every proxy turned off
 * (one from the environment would resolve the name itself). Through the
 * egress proxy, it is a CONNECT tunnel to the approved address
 * (CURLOPT_CONNECT_TO, any host, any port), so the proxy never resolves the
 * name and a DNS answer that changes after the check moves nothing; TLS is
 * still verified against the host name inside the tunnel. An address on
 * the operator's own network (one P202_WEBHOOK_ALLOW_NETWORKS admits) is
 * reached direct, as before there was a proxy (tunnels()). Plain-http
 * postbacks are tunnelled too, so the proxy must allow CONNECT to the ports
 * they name (80, and any other a network uses), not only 443. The calls to
 * fixed hosts route through FixedHostRoute, which pins them the same way.
 *
 * The guard still needs this server's resolver: the destination is checked
 * here, never left to the proxy. A value that does not read as
 * `http(s)://[user:password@]host:port`, or a saved one that cannot be
 * read, refuses every request it would route, naming the setting; it never
 * falls back to sending direct (CLAUDE.md #11).
 */
final class EgressProxy
{
    public const CONSTANT = 'P202_EGRESS_PROXY';
    public const FROM_CONSTANT = 'constant';
    public const FROM_SETTINGS = 'settings';
    private const IN_CONFIG = self::CONSTANT . ' in 202-config.php';
    private const IN_SETTINGS = 'The egress proxy saved on Account › Settings';
    private const HELD = 'nothing this server sends out is sent until it is fixed.';

    /** @var (callable(): ?StoredEgressProxy)|null the saved setting's reader; null for p202EgressProxyStored() */
    private static $storedReader = null;

    private function __construct(
        public readonly string $scheme,
        /** bracketed when an IPv6 address */
        public readonly string $host,
        public readonly int $port,
        #[\SensitiveParameter] private readonly ?string $user,
        #[\SensitiveParameter] private readonly ?string $password,
        public readonly string $source,
    ) {
    }

    /**
     * Where the saved setting is read from. By default it is
     * p202EgressProxyStored() (functions-shared.php, the install's database),
     * so every bootstrap that loads that file (connect.php, connect2.php, the
     * API) reads it; a process that loads none (the PHP CLI on an operator's
     * machine) has only the constant. A test names its own reader.
     *
     * @param (callable(): ?StoredEgressProxy)|null $reader null for the default
     */
    public static function readStoredFrom(?callable $reader): void
    {
        self::$storedReader = $reader;
    }

    /**
     * The proxy in force: the constant's when it is defined, else the one
     * saved on Account › Settings, else null.
     *
     * @throws \UnexpectedValueException when the setting does not read as a proxy URL, or the saved one cannot be read
     */
    public static function configured(): ?self
    {
        if (defined(self::CONSTANT)) {
            $value = constant(self::CONSTANT);
            if (!is_string($value)) {
                throw new \UnexpectedValueException(self::IN_CONFIG . ' is a ' . get_debug_type($value)
                    . ', not a proxy URL such as http://proxy.example:3128; ' . self::HELD);
            }

            return self::parse($value);
        }
        $reader = self::$storedReader ?? (function_exists('p202EgressProxyStored') ? 'p202EgressProxyStored' : null);
        if ($reader === null) {
            return null;
        }
        try {
            $stored = $reader();
        } catch (\Throwable $e) {
            throw new \UnexpectedValueException(self::IN_SETTINGS . ' could not be read (' . get_class($e) . ': '
                . $e->getMessage() . '); ' . self::HELD, 0, $e);
        }

        return $stored === null ? null : self::fromStored($stored);
    }

    /**
     * A saved row as a proxy. Its URL carries no credentials; they are its
     * own columns.
     *
     * @throws \UnexpectedValueException when the saved URL does not read
     */
    public static function fromStored(StoredEgressProxy $stored): self
    {
        if (str_contains($stored->url, '@')) {
            throw self::unreadable($stored->url, 'its URL carries a user name, which is stored on its own', self::IN_SETTINGS);
        }
        $proxy = self::parse($stored->url, self::IN_SETTINGS);
        if ($proxy === null) {
            throw self::unreadable($stored->url, 'it is empty', self::IN_SETTINGS);
        }
        [$user, $password] = $stored->credentials();

        return new self(
            $proxy->scheme,
            $proxy->host,
            $proxy->port,
            $stored->credentialsStored() ? $user : null,
            $stored->credentialsStored() ? $password : null,
            self::FROM_SETTINGS,
        );
    }

    public function hasCredentials(): bool
    {
        return $this->user !== null;
    }

    /**
     * '' is no proxy; anything else is a proxy URL or refused.
     *
     * @throws \UnexpectedValueException naming what does not read
     */
    public static function parse(string $value, string $named = self::IN_CONFIG): ?self
    {
        if ($value === '') {
            return null;
        }
        $pattern = '#^(?<scheme>[A-Za-z][A-Za-z0-9+.-]*)://'
            . '(?:(?<user>[A-Za-z0-9._~!$&\'()*+,;=%-]*):(?<password>[A-Za-z0-9._~!$&\'()*+,;=%-]*)@)?'
            . '(?:\[(?<v6>[0-9A-Fa-f:.]+)\]|(?<host>[A-Za-z0-9.-]+))'
            . '(?::(?<port>[0-9]+))?/?$#D';
        if (strlen($value) > 500 || preg_match($pattern, $value, $m) !== 1) {
            throw self::unreadable($value, 'it is not of the form http://host:port', $named);
        }
        $scheme = strtolower($m['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw self::unreadable($value, 'its scheme is ' . $scheme . '; only an http:// or https:// proxy is used', $named);
        }
        $port = $m['port'] ?? '';
        if ($port === '') {
            throw self::unreadable($value, 'it names no port (curl would use 1080, not 80 or 443)', $named);
        }
        if ($port[0] === '0' || strlen($port) > 5 || (int) $port > 65535) {
            throw self::unreadable($value, 'its port ' . $port . ' is not a port from 1 to 65535', $named);
        }
        if (($m['v6'] ?? '') !== '') {
            $binary = @inet_pton($m['v6']);
            if ($binary === false || strlen($binary) !== 16) {
                throw self::unreadable($value, '[' . $m['v6'] . '] is not an IPv6 address', $named);
            }
            $host = '[' . inet_ntop($binary) . ']';
        } else {
            $host = strtolower($m['host']);
            $labels = explode('.', $host);
            foreach ($labels as $label) {
                if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                    throw self::unreadable($value, $host . ' is not a host name or an IPv4 address', $named);
                }
            }
            if (preg_match('/^[0-9.]+$/D', $host) === 1
                && (count($labels) !== 4 || @inet_pton($host) === false || preg_match('/(^|\.)0[0-9]/', $host) === 1)
            ) {
                throw self::unreadable($value, $host . ' is not an IPv4 address in dotted form', $named);
            }
        }
        $hasCredentials = isset($m['user']) && $m['user'] !== '' || isset($m['password']) && $m['password'] !== '';

        return new self(
            $scheme,
            $host,
            (int) $port,
            $hasCredentials ? rawurldecode($m['user']) : null,
            $hasCredentials ? rawurldecode($m['password']) : null,
            $named === self::IN_CONFIG ? self::FROM_CONSTANT : self::FROM_SETTINGS,
        );
    }

    /** The proxy as a log line or a message may show it: never its credentials. */
    public function label(): string
    {
        return $this->scheme . '://' . $this->host . ':' . $this->port;
    }

    /** The proxy's address when it is written as one; null for a host name. */
    public function address(): ?string
    {
        $host = trim($this->host, '[]');

        return @inet_pton($host) !== false ? $host : null;
    }

    /**
     * The options that route one request to a checked target: the pin, and
     * the proxy or the absence of one. Set after every other option, so
     * nothing can loosen them (CLAUDE.md #24).
     *
     * @return array<int, mixed>
     */
    public static function routeOptions(WebhookTarget $target, ?self $proxy): array
    {
        if (!self::tunnels($target, $proxy)) {
            $options = [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
            $resolve = $target->resolveEntry();
            if ($resolve !== null) {
                $options[CURLOPT_RESOLVE] = [$resolve];
            }

            return $options;
        }

        $address = $target->pinned();
        $options = [
            CURLOPT_PROXY => $proxy->label(),
            CURLOPT_PROXYTYPE => $proxy->scheme === 'https' ? CURLPROXY_HTTPS : CURLPROXY_HTTP,
            // An environment's no_proxy would send the request past the proxy.
            CURLOPT_NOPROXY => '',
            CURLOPT_HTTPPROXYTUNNEL => true,
            // Any host and port: the tunnel goes to the approved address whatever curl takes the URL's host to be.
            CURLOPT_CONNECT_TO => ['::' . (str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $target->port],
        ];
        if ($proxy->scheme === 'https') {
            $options[CURLOPT_PROXY_SSL_VERIFYPEER] = true;
            $options[CURLOPT_PROXY_SSL_VERIFYHOST] = 2;
        }
        if ($proxy->user !== null) {
            $options[CURLOPT_PROXYUSERNAME] = $proxy->user;
            $options[CURLOPT_PROXYPASSWORD] = (string) $proxy->password;
        }

        return $options;
    }

    /**
     * The address curl must report having connected to: the approved one
     * direct, the proxy's through it. Null when that cannot be known (a
     * proxy named by host name), and then nothing is compared.
     */
    public static function expectedPeer(WebhookTarget $target, ?self $proxy): ?string
    {
        return $proxy !== null && self::tunnels($target, $proxy) ? $proxy->address() : $target->pinned();
    }

    /**
     * Whether the request goes through the proxy: every approved address
     * does but one on the operator's own network, which only
     * P202_WEBHOOK_ALLOW_NETWORKS admits and which is reached as it was
     * before there was a proxy.
     *
     * @phpstan-assert-if-true !null $proxy
     */
    public static function tunnels(WebhookTarget $target, ?self $proxy): bool
    {
        return $proxy !== null && (new WebhookGuard(null, ''))->refusal($target->pinned()) === null;
    }

    private static function unreadable(string $value, string $why, string $named = self::IN_CONFIG): \UnexpectedValueException
    {
        // A value carrying a password is never repeated.
        $shown = str_contains($value, '@')
            ? '(not shown: it has a user name or password)'
            : '"' . mb_substr((string) preg_replace('/[^\x21-\x7E]/', '?', $value), 0, 200) . '"';

        return new \UnexpectedValueException($named . ' is ' . $shown . ', which is not a proxy URL: '
            . $why . '. Write it as http://host:port; ' . self::HELD);
    }
}

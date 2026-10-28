<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Decides whether an export webhook may be sent to a URL (plan §6.3
 * "Exports", §7.1 "SSRF through MTA export webhooks").
 *
 * A webhook URL is typed by anyone who can create an export, and the server
 * then makes the request from inside the operator's network. So:
 *
 * - The URL must be `https://host[:port][/path]`, spelled plainly: no user
 *   info, no fragment, no whitespace or control characters, no backslash.
 *   A hand-rolled pattern rather than parse_url(), whose leniency is the
 *   classic way a guard and the HTTP client come to disagree about which
 *   host a URL names.
 * - The host is a DNS name whose last label starts with a letter, a
 *   canonical dotted-quad IPv4 address, or a bracketed IPv6 address. Every
 *   other spelling of a number — `2130706433`, `0x7f.1`, `127.1`,
 *   `0177.0.0.1`, `010.0.0.1` — is refused by name rather than handed to a
 *   resolver that would read it as 127.0.0.1. A single-label name
 *   (`localhost`, `intranet`) is refused: it only resolves through local
 *   search domains, which is what an internal name is.
 * - Every address the host resolves to is checked, IPv4 and IPv6, and one
 *   bad answer refuses the URL. An IPv4-mapped (`::ffff:a.b.c.d`) or NAT64
 *   (`64:ff9b::a.b.c.d`) IPv6 address is judged as the IPv4 address it
 *   carries. Refused: this-network, private, carrier-grade NAT, loopback,
 *   link-local (where cloud metadata answers), the IETF, documentation and
 *   benchmarking blocks, 6to4 and Teredo, multicast, reserved, and IPv6
 *   outside global unicast (unique-local, link-local, site-local, the
 *   discard prefix) — the IANA special-purpose registries' "not globally
 *   reachable" set.
 * - The sender re-runs this check at send time and connects to the address
 *   it approved, with the host pinned (WebhookTarget::resolveEntry), so a
 *   DNS answer that changes between the check and the connection (DNS
 *   rebinding) changes nothing. The check at save time exists so the person
 *   saving the export is told.
 *
 * Traffic-source postbacks are held to the same rules (checkPostback()),
 * with http:// and any port allowed, since that is what networks' postback
 * URLs are: one set of ranges, one resolver, one allowlist for every URL
 * this server calls on a stranger's word.
 *
 * The one relaxation is the operator's, never a request's: a constant
 * `P202_WEBHOOK_ALLOW_NETWORKS` in 202-config.php (comma- or
 * space-separated CIDRs) admits addresses in those networks, for an
 * operator whose receiver lives on their own network — export webhooks and
 * postbacks alike. It cannot admit the
 * addresses no receiver lives at — link-local and the named metadata
 * endpoints, this-network, multicast and reserved — and an entry that does
 * not parse refuses every URL rather than being skipped (CLAUDE.md error
 * pattern #11: a value that cannot be read must not widen anything).
 */
final class WebhookGuard
{
    public const MAX_URL_LENGTH = 500;

    /**
     * IPv4 blocks refused, with the sentence each gets. [network, prefix, why, never allowlisted]
     *
     * @var list<array{0: string, 1: int, 2: string, 3: bool}>
     */
    private const V4_BLOCKS = [
        ['0.0.0.0', 8, 'a "this network" address', true],
        ['10.0.0.0', 8, 'a private address', false],
        ['100.64.0.0', 10, 'a carrier-grade NAT address', false],
        ['127.0.0.0', 8, 'a loopback address', false],
        ['169.254.0.0', 16, 'a link-local address, where cloud metadata services answer', true],
        ['172.16.0.0', 12, 'a private address', false],
        ['192.0.0.0', 24, 'an IETF protocol-assignment address', false],
        ['192.0.2.0', 24, 'a documentation address', false],
        ['192.88.99.0', 24, 'a 6to4 relay address', false],
        ['192.168.0.0', 16, 'a private address', false],
        ['198.18.0.0', 15, 'a benchmarking address', false],
        ['198.51.100.0', 24, 'a documentation address', false],
        ['203.0.113.0', 24, 'a documentation address', false],
        ['224.0.0.0', 4, 'a multicast address', true],
        ['240.0.0.0', 4, 'a reserved address', true],
    ];

    /**
     * Single addresses that are cloud metadata or platform endpoints, refused
     * even where a block around them is allowlisted (Alibaba 100.100.100.200,
     * Oracle 192.0.0.192) or where they sit in public space (Azure's
     * WireServer 168.63.129.16).
     */
    private const V4_METADATA = ['100.100.100.200', '192.0.0.192', '168.63.129.16'];

    /**
     * IPv6 blocks refused inside global unicast (2000::/3), and the
     * prefixes whose embedded IPv4 address is judged instead.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const V6_GLOBAL_EXCEPTIONS = [
        ['2001::', 23, 'an IETF protocol-assignment address (Teredo and friends)'],
        ['2001:db8::', 32, 'a documentation address'],
        ['2002::', 16, 'a 6to4 address'],
        ['3fff::', 20, 'a documentation address'],
    ];

    /** @var list<array{0: string, 1: int, 2: string, 3: bool}> outside 2000::/3 */
    private const V6_NAMED = [
        ['::', 128, 'the unspecified address', true],
        ['::1', 128, 'a loopback address', false],
        ['64:ff9b:1::', 48, 'a local-use NAT64 address', false],
        ['100::', 64, 'a discard-only address', true],
        ['fc00::', 7, 'a unique-local (private) address', false],
        ['fe80::', 10, 'a link-local address, where cloud metadata services answer', true],
        ['fec0::', 10, 'a site-local address', false],
        ['ff00::', 8, 'a multicast address', true],
    ];

    /** AWS's IPv6 metadata endpoint, inside the unique-local block. */
    private const V6_METADATA = ['fd00:ec2::254'];

    /** @var callable(string): list<string> */
    private $resolver;

    /** @var list<array{0: string, 1: int}> binary network, prefix */
    private array $allowed;

    /**
     * @param (callable(string): list<string>)|null $resolver host => addresses; the system resolver by default
     * @param string|null $allowNetworks the operator's allowlist; null reads P202_WEBHOOK_ALLOW_NETWORKS
     * @throws \UnexpectedValueException when the allowlist does not parse
     */
    public function __construct(?callable $resolver = null, ?string $allowNetworks = null)
    {
        $this->resolver = $resolver ?? [self::class, 'systemResolve'];
        if ($allowNetworks === null) {
            $allowNetworks = defined('P202_WEBHOOK_ALLOW_NETWORKS') ? (string) constant('P202_WEBHOOK_ALLOW_NETWORKS') : '';
        }
        $this->allowed = self::parseAllowlist($allowNetworks);
    }

    /**
     * Check a URL, resolve its host, and check every address.
     *
     * @throws WebhookRefused with the sentence for the person who typed it
     */
    public function check(string $url): WebhookTarget
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new WebhookRefused('The webhook URL is longer than ' . self::MAX_URL_LENGTH . ' characters.');
        }
        if (preg_match('/^https:\/\//i', $url) !== 1) {
            throw new WebhookRefused('The webhook URL must start with https:// — exports are only sent over TLS.');
        }
        // host: a bracketed IPv6 literal or [A-Za-z0-9.-]; then an optional
        // port; then a path/query of printable ASCII without # or \.
        $pattern = '/^https:\/\/(?:\[(?<v6>[0-9A-Fa-f:.]+)\]|(?<host>[A-Za-z0-9.-]+))(?::(?<port>[0-9]{1,5}))?(?<path>\/[\x21-\x22\x24-\x5B\x5D-\x7E]*)?$/iD';
        if (preg_match($pattern, $url, $m) !== 1) {
            throw new WebhookRefused(
                'The webhook URL is not in the form https://host/path: user names, fragments (#), spaces, backslashes '
                . 'and non-ASCII characters are not accepted. Percent-encode anything else.'
            );
        }

        $target = $this->target($url, $m, 443, 'webhook', true);
        assert($target !== null);

        return $target;
    }

    /**
     * The same check for a traffic source's server-to-server postback
     * (Notifications\PostbackSender), at send time and at every redirect:
     * http:// as well as https://, since many networks' postback URLs are
     * plain http, on any port, with a query (or a fragment) straight after
     * the host. The host and every address it resolves to are held to the
     * rules above, the allowlist included.
     *
     * @throws WebhookUnresolved when the host resolves to nothing, which a later try may change
     * @throws WebhookRefused with the reason, which names the host and never the path or query
     */
    public function checkPostback(string $url): WebhookTarget
    {
        $m = self::postbackParts($url, false);
        assert($m !== null);
        $target = $this->target($url, $m, self::defaultPort($m['scheme']), 'postback', true);
        assert($target !== null);

        return $target;
    }

    /**
     * What checkPostback() refuses without asking a resolver, for the
     * places a postback URL is saved: null when nothing is, else why. With
     * $template, the URL is a pixel's code before its [[tokens]] are filled,
     * and a host made of tokens is left to the check at send time.
     */
    public function postbackRefusal(string $url, bool $template = false): ?string
    {
        try {
            $m = self::postbackParts($url, $template);
            if ($m !== null) {
                $this->target($url, $m, self::defaultPort($m['scheme']), 'postback', false);
            }
        } catch (WebhookRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * A postback URL's parts, or null for a template whose host is tokens.
     * The host ends at the first /, ? or #, as curl reads it, and a user
     * name or password before it is refused by name.
     *
     * @return array<string, string>|null
     * @throws WebhookRefused
     */
    private static function postbackParts(string $url, bool $template): ?array
    {
        if (preg_match('~^https?://~i', $url, $scheme) !== 1) {
            throw new WebhookRefused(
                'The postback URL must start with http:// or https://; the postback sender calls nothing else.'
            );
        }
        $rest = substr($url, strlen($scheme[0]));
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        if (str_contains($authority, '@')) {
            throw new WebhookRefused(
                'The postback URL has a user name or password before its host ("…@"), which is not accepted: '
                . 'put a network\'s key in the path or the query.'
            );
        }
        if ($template && str_contains($authority, '[[')) {
            return null;
        }
        $pattern = '~^(?<scheme>https?)://(?:\[(?<v6>[0-9A-Fa-f:.]+)\]|(?<host>[A-Za-z0-9.-]+))'
            . '(?::(?<port>[0-9]{1,5}))?(?<rest>[/?#][^\x00-\x20\x7F]*)?$~iD';
        if (preg_match($pattern, $url, $m) !== 1) {
            throw new WebhookRefused(
                'The postback URL is not in the form http(s)://host[:port]/path?query: spaces and control characters '
                . 'are not accepted, and the host is a name of letters, digits, dots and hyphens, an IPv4 address, '
                . 'or an IPv6 address in brackets.'
            );
        }

        return $m;
    }

    private static function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'http' ? 80 : 443;
    }

    /**
     * The same check for a sync profile's URL (Api\V3\Support\RemoteApiClient),
     * at every request a sync makes: the profile's API key goes with each one
     * and the answer is read back, so a URL the server should not call is a
     * read of whatever answers there, with a key handed to it.
     *
     * - https://, with a path (an install in a directory) and any port; no
     *   query, fragment, user name or password.
     * - Every address is held to the rules above, the allowlist included:
     *   two installs on one machine or one private network sync only where
     *   the operator admits the other's address with
     *   P202_WEBHOOK_ALLOW_NETWORKS, and link-local and metadata addresses
     *   never.
     * - http:// only when every address the host resolves to is one the
     *   allowlist names: the key crosses the operator's own network in plain
     *   text, never the internet.
     * - $operatorHosts are the names the operator's P202_URL_MAP sends a URL
     *   to (Docker service names): their spelling is the operator's, so the
     *   single-label and local-name rules are not applied to them; their
     *   addresses are checked as any other's.
     *
     * A refusal the allowlist could lift says so, naming the address.
     *
     * @param list<string> $operatorHosts lower-case host names
     * @throws WebhookUnresolved when the host resolves to nothing, which a later try may change
     * @throws WebhookRefused naming the host and never the path
     */
    public function checkSync(string $url, array $operatorHosts = []): WebhookTarget
    {
        $target = $this->syncTarget($url, $operatorHosts, true);
        assert($target !== null);

        return $target;
    }

    /**
     * What checkSync() refuses without asking a resolver, for a sync job's
     * save: null when nothing is, else why. A host name over http:// is
     * left to the send unless no allowlist is set, which no address of it
     * could then be in.
     *
     * @param list<string> $operatorHosts
     */
    public function syncRefusal(string $url, array $operatorHosts = []): ?string
    {
        try {
            $this->syncTarget($url, $operatorHosts, false);
        } catch (WebhookRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param list<string> $operatorHosts
     * @throws WebhookRefused
     */
    private function syncTarget(string $url, array $operatorHosts, bool $resolve): ?WebhookTarget
    {
        $m = self::syncParts($url);
        $port = self::defaultPort($m['scheme']);
        $host = strtolower($m['host'] ?? '');
        $operator = $host !== '' && in_array($host, $operatorHosts, true);
        try {
            $target = $operator
                ? $this->operatorTarget($url, $m, $host, $port, $resolve)
                : $this->target($url, $m, $port, 'sync', $resolve);
        } catch (WebhookUnresolved $e) {
            throw $e;
        } catch (WebhookRefused $e) {
            $hint = $this->allowlistWouldAdmit($url, $m, $port, $operator, $resolve);
            throw new WebhookRefused($e->getMessage() . $hint, 0, $e);
        }
        if (strtolower($m['scheme']) !== 'http') {
            return $target;
        }

        $why = 'http:// would send the profile\'s API key in plain text, so a sync uses https://, and http:// '
            . 'only to an address P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php names (an install on this '
            . 'server\'s own machine or network).';
        if ($target === null) {
            if ($this->allowed === []) {
                throw new WebhookRefused($why . ' P202_WEBHOOK_ALLOW_NETWORKS is not set.');
            }

            return null;
        }
        foreach ($target->addresses as $address) {
            if (!$this->allowlisted((string) inet_pton($address))) {
                $named = $target->ipLiteral ? $address : $target->host . ' resolves to ' . $address;
                throw new WebhookRefused($why . ' ' . $named . ', which it does not name.');
            }
        }

        return $target;
    }

    /**
     * A name the operator's own setting sends syncs to: its addresses
     * checked, its spelling taken as the operator wrote it.
     *
     * @param array<string, string> $m
     * @throws WebhookRefused
     */
    private function operatorTarget(string $url, array $m, string $host, int $port, bool $resolve): ?WebhookTarget
    {
        if (($m['port'] ?? '') !== '') {
            $port = (int) $m['port'];
        }

        return $resolve ? $this->resolvedTarget($url, $host, $port, 'sync') : null;
    }

    /**
     * The sentence a refusal gains when the operator's allowlist could lift
     * it: the same check, with every network admitted, passes. Link-local,
     * metadata and the other addresses nothing admits still fail it, and so
     * gain nothing.
     *
     * @param array<string, string> $m
     */
    private function allowlistWouldAdmit(string $url, array $m, int $port, bool $operator, bool $resolve): string
    {
        $open = new self($this->resolver, '0.0.0.0/0 ::/0');
        try {
            $target = $operator
                ? $open->operatorTarget($url, $m, strtolower($m['host']), $port, $resolve)
                : $open->target($url, $m, $port, 'sync', $resolve);
        } catch (WebhookRefused) {
            return '';
        }
        $address = $target !== null ? $target->pinned() : 'its address';

        return ' To sync with an install on this server\'s own machine or network, add ' . $address
            . ' (or its network) to P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php.';
    }

    /**
     * A sync URL's parts: scheme, host (v6 for a bracketed literal), port
     * and path, with every other spelling refused by name.
     *
     * @return array<string, string>
     * @throws WebhookRefused
     */
    private static function syncParts(string $url): array
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new WebhookRefused('The sync URL is longer than ' . self::MAX_URL_LENGTH . ' characters.');
        }
        if (preg_match('~^https?://~i', $url, $scheme) !== 1) {
            throw new WebhookRefused('The sync URL must start with https:// (http:// only to an address '
                . 'P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php names); a sync calls nothing else.');
        }
        $rest = substr($url, strlen($scheme[0]));
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        if (str_contains($authority, '@')) {
            throw new WebhookRefused('The sync URL has a user name or password before its host ("…@"), which is not '
                . 'accepted: a sync signs in with the profile\'s api_key.');
        }
        $pattern = '~^(?<scheme>https?)://(?:\[(?<v6>[0-9A-Fa-f:.]+)\]|(?<host>[A-Za-z0-9.-]+))'
            . '(?::(?<port>[0-9]{1,5}))?(?<path>/[\x21-\x22\x24-\x3E\x40-\x5B\x5D-\x7E]*)?$~iD';
        if (preg_match($pattern, $url, $m) !== 1) {
            throw new WebhookRefused('The sync URL is not in the form https://host[:port][/path]: a query (?), '
                . 'a fragment (#), spaces, backslashes and non-ASCII characters are not accepted, and the host is a '
                . 'name of letters, digits, dots and hyphens, an IPv4 address, or an IPv6 address in brackets.');
        }
        if (($m['port'] ?? '') !== '' && ($m['port'][0] === '0' || (int) $m['port'] > 65535)) {
            throw new WebhookRefused('The sync URL has port ' . $m['port'] . ', which is not a port from 1 to 65535.');
        }
        $host = strtolower($m['host'] ?? '');
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new WebhookRefused($host . ' names this server: write its address instead (http://127.0.0.1:port), '
                . 'and admit it with P202_WEBHOOK_ALLOW_NETWORKS in 202-config.php (127.0.0.1/32). Syncs are only '
                . 'sent to public addresses otherwise.');
        }

        return $m;
    }

    /**
     * The checks every kind of URL shares, from its parsed parts: the port,
     * the host's spelling, and every address the host is or resolves to.
     * Without $resolve a host name passes once its spelling does (null).
     *
     * @param array<string, string> $m
     * @throws WebhookRefused
     */
    private function target(string $url, array $m, int $port, string $kind, bool $resolve): ?WebhookTarget
    {
        if (($m['port'] ?? '') !== '') {
            if ($m['port'][0] === '0' || (int) $m['port'] < 1 || (int) $m['port'] > 65535) {
                throw new WebhookRefused(
                    'The ' . $kind . ' URL has port ' . $m['port'] . ', which is not a port from 1 to 65535.'
                );
            }
            $port = (int) $m['port'];
        }

        if (($m['v6'] ?? '') !== '') {
            $binary = @inet_pton($m['v6']);
            if ($binary === false || strlen($binary) !== 16) {
                throw new WebhookRefused('[' . $m['v6'] . '] is not an IPv6 address.');
            }
            $address = (string) inet_ntop($binary);
            $this->assertAllowed($address, $address, $kind);

            return new WebhookTarget($url, '[' . $address . ']', $port, true, [$address]);
        }

        $host = strtolower($m['host']);
        if (preg_match('/^[0-9.]+$/D', $host) === 1) {
            $address = self::canonicalDottedQuad($host);
            if ($address === null) {
                throw new WebhookRefused(
                    $host . ' is not an IPv4 address in dotted form (four numbers from 0 to 255, no leading zeros). '
                    . 'Other spellings of an address are refused because resolvers read them differently.'
                );
            }
            $this->assertAllowed($address, $host, $kind);

            return new WebhookTarget($url, $host, $port, true, [$address]);
        }

        self::assertHostname($host, $kind);
        if (!$resolve) {
            return null;
        }

        return $this->resolvedTarget($url, $host, $port, $kind);
    }

    /**
     * A host name's target: every address it resolves to, each checked.
     *
     * @throws WebhookUnresolved
     * @throws WebhookRefused
     */
    private function resolvedTarget(string $url, string $host, int $port, string $kind): WebhookTarget
    {
        $resolved = [];
        foreach (($this->resolver)($host) as $answer) {
            $binary = @inet_pton((string) $answer);
            if ($binary === false) {
                throw new WebhookRefused($host . ' resolved to "' . $answer . '", which is not an address.');
            }
            $resolved[] = (string) inet_ntop($binary);
        }
        $resolved = array_values(array_unique($resolved));
        if ($resolved === []) {
            throw new WebhookUnresolved($host . ' does not resolve to an address from this server.');
        }
        foreach ($resolved as $address) {
            $this->assertAllowed($address, $host, $kind);
        }

        return new WebhookTarget($url, $host, $port, false, $resolved);
    }

    /**
     * Why an address is refused, or null when it may be sent to.
     */
    public function refusal(string $address): ?string
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return 'it is not an address';
        }
        if (strlen($binary) === 16) {
            $embedded = self::embeddedV4($binary);
            if ($embedded !== null) {
                $why = $this->v4Refusal($embedded);

                return $why === null ? null : $why . ' (carried inside an IPv6 address)';
            }

            return $this->v6Refusal($binary);
        }

        return $this->v4Refusal($binary);
    }

    private function assertAllowed(string $address, string $named, string $kind): void
    {
        $why = $this->refusal($address);
        if ($why !== null) {
            throw new WebhookRefused(
                ($named === $address ? $address : $named . ' resolves to ' . $address) . ', ' . $why
                . '. ' . ucfirst($kind) . 's are only sent to public addresses.'
            );
        }
    }

    private function v4Refusal(string $binary): ?string
    {
        $text = (string) inet_ntop($binary);
        if (in_array($text, self::V4_METADATA, true)) {
            return 'which is a cloud metadata endpoint';
        }
        foreach (self::V4_BLOCKS as [$network, $prefix, $why, $never]) {
            if (self::inBlock($binary, (string) inet_pton($network), $prefix)) {
                if (!$never && $this->allowlisted($binary)) {
                    return null;
                }

                return 'which is ' . $why;
            }
        }

        return null;
    }

    private function v6Refusal(string $binary): ?string
    {
        $text = (string) inet_ntop($binary);
        if (in_array($text, self::V6_METADATA, true)) {
            return 'which is a cloud metadata endpoint';
        }
        foreach (self::V6_NAMED as [$network, $prefix, $why, $never]) {
            if (self::inBlock($binary, (string) inet_pton($network), $prefix)) {
                if (!$never && $this->allowlisted($binary)) {
                    return null;
                }

                return 'which is ' . $why;
            }
        }
        if (!self::inBlock($binary, (string) inet_pton('2000::'), 3)) {
            return $this->allowlisted($binary) ? null : 'which is not a global unicast IPv6 address';
        }
        foreach (self::V6_GLOBAL_EXCEPTIONS as [$network, $prefix, $why]) {
            if (self::inBlock($binary, (string) inet_pton($network), $prefix)) {
                return $this->allowlisted($binary) ? null : 'which is ' . $why;
            }
        }

        return null;
    }

    private function allowlisted(string $binary): bool
    {
        foreach ($this->allowed as [$network, $prefix]) {
            if (strlen($network) === strlen($binary) && self::inBlock($binary, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IPv4 address an IPv4-mapped (::ffff:0:0/96) or well-known NAT64
     * (64:ff9b::/96) IPv6 address carries, as 4 bytes; null for any other.
     */
    private static function embeddedV4(string $binary): ?string
    {
        foreach (['::ffff:0:0', '64:ff9b::'] as $prefix) {
            if (self::inBlock($binary, (string) inet_pton($prefix), 96)) {
                return substr($binary, 12, 4);
            }
        }

        return null;
    }

    private static function inBlock(string $address, string $network, int $prefix): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** a.b.c.d with each part 0-255 and no leading zero, or null. */
    private static function canonicalDottedQuad(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $part) {
            if (preg_match('/^(0|[1-9][0-9]{0,2})$/D', $part) !== 1 || (int) $part > 255) {
                return null;
            }
        }

        return $host;
    }

    private static function assertHostname(string $host, string $kind): void
    {
        if (strlen($host) > 253) {
            throw new WebhookRefused('The ' . $kind . ' host name is longer than 253 characters.');
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) {
                throw new WebhookRefused($host . ' is not a host name: each part between dots is letters, digits and inner hyphens, 1 to 63 characters.');
            }
        }
        if (count($labels) < 2) {
            throw new WebhookRefused($host . ' is a single-label name, which only resolves inside a local network. Use the receiver\'s public host name.');
        }
        $last = $labels[count($labels) - 1];
        if (preg_match('/^[a-z]/', $last) !== 1) {
            throw new WebhookRefused(
                $host . ' is neither a host name (its last part does not start with a letter) nor an IPv4 address in dotted form. '
                . 'Other spellings of an address are refused because resolvers read them differently.'
            );
        }
        if ($last === 'localhost' || $last === 'local' || $last === 'internal') {
            throw new WebhookRefused(
                $host . ' is a local name (.' . $last . '). ' . ucfirst($kind) . 's are only sent to public host names.'
            );
        }
    }

    /**
     * @return list<array{0: string, 1: int}>
     * @throws \UnexpectedValueException naming the entry that does not parse
     */
    public static function parseAllowlist(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $parts = explode('/', $entry);
            $binary = @inet_pton($parts[0]);
            $max = $binary !== false ? strlen($binary) * 8 : 0;
            if ($binary === false || count($parts) !== 2 || preg_match('/^(0|[1-9][0-9]{0,2})$/D', $parts[1]) !== 1 || (int) $parts[1] > $max) {
                throw new \UnexpectedValueException(
                    'P202_WEBHOOK_ALLOW_NETWORKS entry "' . $entry . '" is not a network in CIDR form (for example 10.20.0.0/16); '
                    . 'no webhook is sent until it is fixed.'
                );
            }
            $out[] = [$binary, (int) $parts[1]];
        }

        return $out;
    }

    /**
     * The system resolver: IPv4 through the C library (which reads
     * /etc/hosts as the rest of the server does), IPv6 from DNS AAAA
     * records. Nothing it returns is trusted; every answer is checked.
     *
     * @return list<string>
     */
    public static function systemResolve(string $host): array
    {
        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

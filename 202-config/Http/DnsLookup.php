<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Looks a host name up the way this server's C library does, within a time
 * limit the caller sets. gethostbynamel() and dns_get_record() take none: a
 * resolver that never answers held a request for resolv.conf's timeout x
 * attempts per nameserver, per address family, before any sender's own
 * deadline had started (backlog Y9Q3, D7NS).
 *
 * - /etc/hosts first, as nsswitch's "files dns" reads it: a name listed
 *   there answers with its listed addresses, both families, and DNS is not
 *   asked.
 * - Otherwise A and AAAA queries, sent together over UDP to resolv.conf's
 *   nameservers (at most three, 127.0.0.1 when it names none), each tried
 *   for its timeout: option, every one attempts: times, then over TCP for
 *   an answer that came back truncated. single-request(-reopen) sends them
 *   one after the other, use-vc over TCP only, no-aaaa asks A alone.
 * - Each nameserver in turn has its share of what is left, so a dead first
 *   one never takes the time a working second one needed. Once one family
 *   has addresses, the other is waited for GRACE_SECONDS more at most.
 * - resolv.conf's search list (LOCALDOMAIN, or the host name's domain when
 *   it names none) and ndots: (and RES_OPTIONS) are applied as res_nsearch()
 *   applies them, a server failure on one name going on to the next, so a
 *   name resolves here to what it resolves to elsewhere on the server.
 *
 * Nothing waits past the limit: when it runs out, or every nameserver has
 * been tried and none answered, DnsLookupFailed says which. An answer is an
 * address only once it matches the query's id and question; the addresses
 * are the caller's to check.
 */
final class DnsLookup
{
    public const RESOLV_CONF = '/etc/resolv.conf';
    public const HOSTS = '/etc/hosts';

    private const TYPE_A = 1;
    private const TYPE_CNAME = 5;
    private const TYPE_AAAA = 28;
    private const CLASS_IN = 1;
    private const RCODE_NOERROR = 0;
    private const RCODE_NXDOMAIN = 3;
    /** glibc's MAXNS and the caps it puts on the options. */
    private const MAX_NAMESERVERS = 3;
    private const MAX_TIMEOUT = 30;
    private const MAX_ATTEMPTS = 5;
    private const MAX_NDOTS = 15;
    /** How long the other family is waited for once one has addresses. */
    public const GRACE_SECONDS = 0.5;
    /** Aliases followed from the question's name. */
    private const MAX_CNAME_HOPS = 16;
    /** What a UDP query needs; a server with any of them disabled uses the C library. */
    private const SOCKET_FUNCTIONS = ['stream_socket_client', 'stream_select', 'stream_socket_recvfrom',
        'stream_set_blocking'];

    /**
     * @param list<string> $nameservers "address", "address:port" or "[v6 address]:port"
     * @param list<string> $search domains appended to a name with fewer than $ndots dots
     * @param float $tryTimeout seconds one nameserver is waited for
     * @param string|null $hostsFile read before DNS; null reads none
     * @param bool $oneAtATime single-request(-reopen): A, then AAAA, never both at once
     * @param bool $tcpOnly use-vc
     * @param bool $noAaaa no-aaaa: A alone is asked
     */
    public function __construct(
        private readonly array $nameservers,
        private readonly array $search = [],
        private readonly int $ndots = 1,
        private readonly float $tryTimeout = 5.0,
        private readonly int $attempts = 2,
        private readonly ?string $hostsFile = self::HOSTS,
        private readonly bool $oneAtATime = false,
        private readonly bool $tcpOnly = false,
        private readonly bool $noAaaa = false
    ) {
    }

    /**
     * This server's resolver, from resolv.conf, LOCALDOMAIN and RES_OPTIONS.
     * Null where there is no resolv.conf (Windows), it or the hosts file
     * cannot be read here (open_basedir), or the socket functions a query
     * needs are disabled, so the C library, which reads both, answers instead.
     */
    public static function system(string $resolvConf = self::RESOLV_CONF, ?string $hostsFile = self::HOSTS): ?self
    {
        foreach (self::SOCKET_FUNCTIONS as $function) {
            if (!function_exists($function)) {
                return null;
            }
        }
        if (!@is_file($resolvConf) || !@is_readable($resolvConf)) {
            return null;
        }
        if ($hostsFile !== null && !@is_readable($hostsFile)) {
            return null;
        }
        $text = @file_get_contents($resolvConf);
        if ($text === false) {
            return null;
        }
        // As res_init() reads them: each overrides what resolv.conf says.
        $localDomain = getenv('LOCALDOMAIN');
        if (is_string($localDomain) && trim($localDomain) !== '') {
            $text .= "\nsearch " . $localDomain;
        }
        $resOptions = getenv('RES_OPTIONS');
        if (is_string($resOptions) && trim($resOptions) !== '') {
            $text .= "\noptions " . $resOptions;
        }
        $hostname = gethostname();

        return self::fromResolvConf($text, $hostsFile, $hostname === false ? null : $hostname);
    }

    /** @param string|null $hostname whose domain is searched when no search or domain line names one */
    public static function fromResolvConf(
        string $text,
        ?string $hostsFile = self::HOSTS,
        ?string $hostname = null
    ): self {
        $nameservers = [];
        $search = null;
        $ndots = 1;
        $timeout = 5;
        $attempts = 2;
        $oneAtATime = false;
        $tcpOnly = false;
        $noAaaa = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($fields === [] || $fields[0][0] === '#' || $fields[0][0] === ';') {
                continue;
            }
            $keyword = $fields[0];
            if ($keyword === 'nameserver' && isset($fields[1]) && count($nameservers) < self::MAX_NAMESERVERS) {
                $address = $fields[1];
                $bare = explode('%', $address, 2)[0];
                if (@inet_pton($bare) !== false) {
                    $nameservers[] = $address;
                }
            } elseif ($keyword === 'search' || $keyword === 'domain') {
                // The last search or domain line wins, as in glibc.
                $search = self::domains(array_slice($fields, 1));
            } elseif ($keyword === 'options') {
                foreach (array_slice($fields, 1) as $option) {
                    if ($option === 'single-request' || $option === 'single-request-reopen') {
                        $oneAtATime = true;
                        continue;
                    }
                    if ($option === 'use-vc') {
                        $tcpOnly = true;
                        continue;
                    }
                    if ($option === 'no-aaaa') {
                        $noAaaa = true;
                        continue;
                    }
                    if (preg_match('/^(ndots|timeout|attempts):([0-9]{1,3})$/D', $option, $m) !== 1) {
                        continue;
                    }
                    $value = (int) $m[2];
                    if ($m[1] === 'ndots') {
                        $ndots = min($value, self::MAX_NDOTS);
                    } elseif ($m[1] === 'timeout') {
                        $timeout = max(1, min($value, self::MAX_TIMEOUT));
                    } else {
                        $attempts = max(1, min($value, self::MAX_ATTEMPTS));
                    }
                }
            }
        }

        // With neither line, glibc searches the domain of the host's own name.
        if ($search === null) {
            $dot = $hostname === null ? false : strpos($hostname, '.');
            $search = $dot === false ? [] : self::domains([substr((string) $hostname, $dot + 1)]);
        }

        return new self(
            $nameservers === [] ? ['127.0.0.1'] : $nameservers,
            $search,
            $ndots,
            (float) $timeout,
            $attempts,
            $hostsFile,
            $oneAtATime,
            $tcpOnly,
            $noAaaa
        );
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private static function domains(array $fields): array
    {
        $domains = array_map(static fn (string $d): string => rtrim(strtolower($d), '.'), $fields);

        return array_values(array_filter($domains, static fn (string $d): bool => $d !== ''));
    }

    /**
     * Every address $host resolves to, IPv4 and IPv6, within $seconds.
     * Empty when the name does not exist or has no address.
     *
     * @return list<string>
     * @throws DnsLookupFailed when no answer came within $seconds, or from any nameserver, or every
     *         answer for a name that might exist was a server failure
     */
    public function addresses(string $host, float $seconds): array
    {
        $until = microtime(true) + $seconds;
        $host = strtolower($host);
        $absolute = str_ends_with($host, '.');
        $host = rtrim($host, '.');
        if ($host === '' || strlen($host) > 253) {
            return [];
        }

        $listed = $this->hostsEntries($host);
        if ($listed !== []) {
            return $listed;
        }

        $failed = false;
        foreach ($this->candidates($host, $absolute) as $name) {
            $result = $this->query($name, $until, $seconds);
            if ($result['addresses'] !== null) {
                return $result['addresses'];
            }
            // res_nsearch() goes on to the next name after a server failure,
            // and answers "try again" when no name was found.
            $failed = $failed || $result['failed'];
        }
        if ($failed) {
            throw new DnsLookupFailed($host . ' was not looked up: the nameservers in ' . self::RESOLV_CONF
                . ' answered with a server failure (' . implode(', ', $this->nameservers) . ').');
        }

        return [];
    }

    /**
     * The names res_nsearch() would ask for, in its order.
     *
     * @return list<string>
     */
    private function candidates(string $host, bool $absolute): array
    {
        if ($absolute || $this->search === []) {
            return [$host];
        }
        $searched = array_map(static fn (string $domain): string => $host . '.' . $domain, $this->search);
        $searched = array_values(array_filter($searched, static fn (string $n): bool => strlen($n) <= 253));

        return substr_count($host, '.') >= $this->ndots
            ? array_merge([$host], $searched)
            : array_merge($searched, [$host]);
    }

    /**
     * The addresses one name has (null when it has none, so the next search
     * candidate is asked), A and AAAA asked together; failed when the only
     * answers were server failures.
     *
     * @return array{addresses: list<string>|null, failed: bool}
     * @throws DnsLookupFailed
     */
    private function query(string $name, float $until, float $seconds): array
    {
        $wire = self::encodeName($name);
        if ($wire === null) {
            return ['addresses' => null, 'failed' => false];
        }
        /** @var array<int, list<string>|null> $answers type => addresses once a nameserver has said, null until then */
        $answers = $this->noAaaa ? [self::TYPE_A => null] : [self::TYPE_A => null, self::TYPE_AAAA => null];
        $failed = false;
        $count = count($this->nameservers);
        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            foreach ($this->nameservers as $i => $server) {
                $pending = array_keys(array_filter($answers, static fn (?array $a): bool => $a === null));
                if ($pending === [] || self::hasAddresses($answers)) {
                    break 2;
                }
                $now = microtime(true);
                if ($now >= $until) {
                    break 2;
                }
                // On the first round each nameserver not yet asked has its share
                // of what is left, so every one is asked within the limit.
                $share = $attempt === 0 ? ($until - $now) / ($count - $i) : $until - $now;
                $tryUntil = min($until, $now + $this->tryTimeout, $now + $share);
                $asked = $this->ask(self::endpoint($server), $name, $wire, $pending, $tryUntil);
                foreach ($asked['answered'] as $type => $addresses) {
                    $answers[$type] = $addresses;
                }
                $failed = $failed || $asked['failed'];
            }
        }
        $have = array_merge(...array_values(array_filter($answers, static fn (?array $a): bool => $a !== null)));
        if (in_array(null, $answers, true)) {
            if ($have !== []) {
                // One family answered and the other did not: what the
                // server says it has is the answer.
                return ['addresses' => array_values(array_unique($have)), 'failed' => false];
            }
            if (microtime(true) >= $until) {
                throw new DnsLookupFailed($name . ' was not looked up within ' . self::seconds($seconds)
                    . ': the resolver did not answer in time.');
            }
            if ($failed) {
                return ['addresses' => null, 'failed' => true];
            }
            throw new DnsLookupFailed($name . ' was not looked up: no nameserver in ' . self::RESOLV_CONF
                . ' answered (' . implode(', ', $this->nameservers) . ').');
        }

        return ['addresses' => $have === [] ? null : array_values(array_unique($have)), 'failed' => false];
    }

    /** @param array<int, list<string>|null> $answers */
    private static function hasAddresses(array $answers): bool
    {
        foreach ($answers as $addresses) {
            if ($addresses !== null && $addresses !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask one nameserver for $types of $name until $until: type => the
     * addresses it named, for each type it answered (NOERROR or NXDOMAIN);
     * failed when it answered any with a server failure.
     *
     * @param list<int> $types
     * @return array{answered: array<int, list<string>>, failed: bool}
     */
    private function ask(string $endpoint, string $name, string $wire, array $types, float $until): array
    {
        if (!$this->oneAtATime && !$this->tcpOnly) {
            return $this->askUdp($endpoint, $name, $wire, $types, $until);
        }
        $answered = [];
        $failed = false;
        foreach ($types as $type) {
            $now = microtime(true);
            if ($now >= $until) {
                break;
            }
            $by = self::hasAddresses($answered) ? min($until, $now + self::GRACE_SECONDS) : $until;
            if ($this->tcpOnly) {
                $reply = $this->askTcp($endpoint, $name, $wire, $type, $by);
                if ($reply === null) {
                    continue;
                }
                if (self::answered($reply)) {
                    $answered[$type] = $reply['addresses'];
                } else {
                    $failed = true;
                }
                continue;
            }
            $one = $this->askUdp($endpoint, $name, $wire, [$type], $by);
            $answered += $one['answered'];
            $failed = $failed || $one['failed'];
        }

        return ['answered' => $answered, 'failed' => $failed];
    }

    /** @param array{rcode: int} $reply */
    private static function answered(array $reply): bool
    {
        return $reply['rcode'] === self::RCODE_NOERROR || $reply['rcode'] === self::RCODE_NXDOMAIN;
    }

    /**
     * $types asked at once over UDP, as ask() answers.
     *
     * @param list<int> $types
     * @return array{answered: array<int, list<string>>, failed: bool}
     */
    private function askUdp(string $endpoint, string $name, string $wire, array $types, float $until): array
    {
        $none = ['answered' => [], 'failed' => false];
        $socket = @stream_socket_client('udp://' . $endpoint, $errno, $error, 1.0);
        if ($socket === false) {
            return $none;
        }
        stream_set_blocking($socket, false);
        $sent = [];
        foreach ($types as $type) {
            $id = random_int(0, 0xFFFF);
            while (isset($sent[$id])) {
                $id = random_int(0, 0xFFFF);
            }
            $packet = self::packet($id, $wire, $type);
            if (@fwrite($socket, $packet) !== strlen($packet)) {
                // A refusal of an earlier query surfaces here, and only once:
                // nothing listens there.
                fclose($socket);

                return $none;
            }
            $sent[$id] = $type;
        }

        $answered = [];
        $failed = false;
        while ($sent !== []) {
            $left = $until - microtime(true);
            if ($left <= 0) {
                break;
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) floor($left), (int) (fmod($left, 1.0) * 1_000_000));
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }
            $datagram = @stream_socket_recvfrom($socket, 65535);
            if (!is_string($datagram) || $datagram === '') {
                // An ICMP refusal surfaces as a failed read: nothing listens there.
                break;
            }
            $reply = self::parse($datagram, $name);
            if ($reply === null || !isset($sent[$reply['id']]) || $reply['type'] !== $sent[$reply['id']]) {
                continue;
            }
            $type = $reply['type'];
            unset($sent[$reply['id']]);
            if ($reply['truncated']) {
                $reply = $this->askTcp($endpoint, $name, $wire, $type, $until);
                if ($reply === null) {
                    continue;
                }
            }
            if (!self::answered($reply)) {
                $failed = true;
                continue;
            }
            $answered[$type] = $reply['addresses'];
            if ($reply['addresses'] !== []) {
                $until = min($until, microtime(true) + self::GRACE_SECONDS);
            }
        }
        fclose($socket);

        return ['answered' => $answered, 'failed' => $failed];
    }

    /**
     * @return array{id: int, type: int, truncated: bool, rcode: int, addresses: list<string>}|null
     */
    private function askTcp(string $endpoint, string $name, string $wire, int $type, float $until): ?array
    {
        $left = $until - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $socket = @stream_socket_client('tcp://' . $endpoint, $errno, $error, $left);
        if ($socket === false) {
            return null;
        }
        stream_set_blocking($socket, false);
        $id = random_int(0, 0xFFFF);
        $packet = self::packet($id, $wire, $type);
        $out = pack('n', strlen($packet)) . $packet;
        $in = '';
        $need = null;
        while (true) {
            $left = $until - microtime(true);
            if ($left <= 0) {
                fclose($socket);

                return null;
            }
            $read = $out === '' ? [$socket] : [];
            $write = $out === '' ? null : [$socket];
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) floor($left), (int) (fmod($left, 1.0) * 1_000_000));
            if ($ready === false) {
                fclose($socket);

                return null;
            }
            if ($ready === 0) {
                continue;
            }
            if ($out !== '') {
                $wrote = @fwrite($socket, $out);
                if ($wrote === false) {
                    fclose($socket);

                    return null;
                }
                $out = (string) substr($out, $wrote);
                continue;
            }
            $chunk = @fread($socket, 65537);
            if (!is_string($chunk) || ($chunk === '' && feof($socket))) {
                fclose($socket);

                return null;
            }
            $in .= $chunk;
            if ($need === null && strlen($in) >= 2) {
                $need = (int) unpack('n', $in)[1];
            }
            if ($need !== null && strlen($in) >= 2 + $need) {
                fclose($socket);
                $reply = self::parse(substr($in, 2, $need), $name);

                $ours = $reply !== null && $reply['id'] === $id && $reply['type'] === $type;
                return $ours && !$reply['truncated'] ? $reply : null;
            }
        }
    }

    private static function packet(int $id, string $wire, int $type): string
    {
        // A standard query asking for recursion, one question.
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $wire . pack('nn', $type, self::CLASS_IN);
    }

    /** The name in DNS wire form, or null when it cannot be one. */
    private static function encodeName(string $name): ?string
    {
        $wire = '';
        foreach (explode('.', $name) as $label) {
            $length = strlen($label);
            if ($length === 0 || $length > 63) {
                return null;
            }
            $wire .= chr($length) . $label;
        }

        return $wire . "\x00";
    }

    /**
     * A reply to a question about $name: its id, the type it asks, and the
     * A or AAAA addresses its answer gives for $name or a name $name is an
     * alias of. Null when it is not a well-formed reply to that question.
     *
     * @return array{id: int, type: int, truncated: bool, rcode: int, addresses: list<string>}|null
     */
    public static function parse(string $packet, string $name): ?array
    {
        if (strlen($packet) < 12) {
            return null;
        }
        $header = unpack('nid/nflags/nqd/nan', $packet);
        if ($header === false) {
            return null;
        }
        $flags = $header['flags'];
        if (($flags & 0x8000) === 0 || (($flags >> 11) & 0xF) !== 0 || $header['qd'] !== 1) {
            return null;
        }
        $offset = 12;
        $qname = self::readName($packet, $offset);
        if ($qname === null || $qname !== strtolower($name) || strlen($packet) < $offset + 4) {
            return null;
        }
        $question = unpack('ntype/nclass', $packet, $offset);
        if (
            $question === false || $question['class'] !== self::CLASS_IN
            || ($question['type'] !== self::TYPE_A && $question['type'] !== self::TYPE_AAAA)
        ) {
            return null;
        }
        $offset += 4;
        $type = $question['type'];

        $records = [];
        for ($i = 0; $i < $header['an']; $i++) {
            $owner = self::readName($packet, $offset);
            if ($owner === null || strlen($packet) < $offset + 10) {
                return null;
            }
            $rr = unpack('ntype/nclass/Nttl/nlength', $packet, $offset);
            if ($rr === false) {
                return null;
            }
            $offset += 10;
            if (strlen($packet) < $offset + $rr['length']) {
                return null;
            }
            $records[] = [$owner, $rr['type'], $rr['class'], $offset, $rr['length']];
            $offset += $rr['length'];
        }

        // The question's name and the aliases it leads to, read in one pass.
        $aliasOf = [];
        foreach ($records as [$owner, $rrType, $class, $at]) {
            if ($rrType === self::TYPE_CNAME && $class === self::CLASS_IN && !isset($aliasOf[$owner])) {
                $cursor = $at;
                $target = self::readName($packet, $cursor);
                if ($target !== null) {
                    $aliasOf[$owner] = $target;
                }
            }
        }
        $names = [$qname => true];
        $current = $qname;
        for ($hops = 0; $hops < self::MAX_CNAME_HOPS && isset($aliasOf[$current]); $hops++) {
            $current = $aliasOf[$current];
            if (isset($names[$current])) {
                break;
            }
            $names[$current] = true;
        }

        $addresses = [];
        $size = $type === self::TYPE_A ? 4 : 16;
        foreach ($records as [$owner, $rrType, $class, $at, $length]) {
            if ($rrType === $type && $class === self::CLASS_IN && $length === $size && isset($names[$owner])) {
                $address = inet_ntop(substr($packet, $at, $length));
                if ($address !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return [
            'id' => $header['id'],
            'type' => $type,
            'truncated' => ($flags & 0x0200) !== 0,
            'rcode' => $flags & 0xF,
            'addresses' => array_values(array_unique($addresses)),
        ];
    }

    /** A name at $offset, lower-cased, following compression; $offset moves past it. */
    private static function readName(string $packet, int &$offset): ?string
    {
        $labels = [];
        $at = $offset;
        $jumped = false;
        $length = strlen($packet);
        for ($hops = 0; $hops < 128; $hops++) {
            if ($at >= $length) {
                return null;
            }
            $byte = ord($packet[$at]);
            if ($byte === 0) {
                if (!$jumped) {
                    $offset = $at + 1;
                }
                $name = implode('.', $labels);

                return strlen($name) <= 253 ? strtolower($name) : null;
            }
            if (($byte & 0xC0) === 0xC0) {
                if ($at + 1 >= $length) {
                    return null;
                }
                if (!$jumped) {
                    $offset = $at + 2;
                }
                $at = (($byte & 0x3F) << 8) | ord($packet[$at + 1]);
                $jumped = true;
                continue;
            }
            if (($byte & 0xC0) !== 0 || $at + 1 + $byte > $length) {
                return null;
            }
            $label = substr($packet, $at + 1, $byte);
            if (str_contains($label, '.')) {
                // Joined with the others, it would read as more than one label.
                return null;
            }
            $labels[] = $label;
            $at += 1 + $byte;
        }

        return null;
    }

    /**
     * The addresses /etc/hosts lists for $host, in its order; none when
     * there is no such file.
     *
     * @return list<string>
     * @throws DnsLookupFailed when it is there and cannot be read
     */
    private function hostsEntries(string $host): array
    {
        if ($this->hostsFile === null || !@file_exists($this->hostsFile)) {
            return [];
        }
        // A directory opens, and reads as an empty file.
        $handle = @is_dir($this->hostsFile) ? false : @fopen($this->hostsFile, 'r');
        if ($handle === false) {
            throw new DnsLookupFailed($host . ' was not looked up: ' . $this->hostsFile . ' could not be read.');
        }
        $found = [];
        while (($line = fgets($handle)) !== false) {
            $line = strtolower(explode('#', $line, 2)[0]);
            $fields = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($fields) < 2 || !in_array($host, array_slice($fields, 1), true)) {
                continue;
            }
            $binary = @inet_pton(explode('%', $fields[0], 2)[0]);
            if ($binary !== false) {
                $found[] = (string) inet_ntop($binary);
            }
        }
        fclose($handle);

        return array_values(array_unique($found));
    }

    /** A nameserver as stream_socket_client() takes it, port 53 unless it names one. */
    private static function endpoint(string $server): string
    {
        if (str_starts_with($server, '[')) {
            return $server;
        }
        if (@inet_pton(explode('%', $server, 2)[0]) !== false) {
            return str_contains($server, ':') ? '[' . $server . ']:53' : $server . ':53';
        }

        return $server;
    }

    private static function seconds(float $seconds): string
    {
        $shown = rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.');

        return $shown . ($shown === '1' ? ' second' : ' seconds');
    }
}

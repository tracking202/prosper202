<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\DnsLookup;
use Prosper202\Http\DnsLookupFailed;

/**
 * The guard's lookup (Http\DnsLookup) is bounded by the time it is given.
 * gethostbynamel() and dns_get_record() take no limit, so a nameserver that
 * never answered held a postback, a webhook or a Slack notice for
 * resolv.conf's timeout x attempts per nameserver, per address family,
 * before the sender's own deadline started (backlog Y9Q3, D7NS).
 *
 * Against a nameserver that never answers (a bound UDP socket nobody
 * reads), one that is not there, and tests/fixtures/dns/fake-nameserver.php,
 * which answers as told: aliases, NXDOMAIN, truncation, one family silent,
 * forged answers.
 */
final class DnsLookupTest extends TestCase
{
    private const FAKE = __DIR__ . '/../fixtures/dns/fake-nameserver.php';

    /** @var list<resource> */
    private array $processes = [];
    /** @var list<resource> */
    private array $sockets = [];
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** A UDP port that takes every query and answers none. */
    private function silentNameserver(): string
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertNotFalse($socket, $error);
        $this->sockets[] = $socket;

        return (string) stream_socket_get_name($socket, false);
    }

    /** A UDP port nothing listens on: the kernel refuses each query. */
    private function absentNameserver(): string
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertNotFalse($socket, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return $name;
    }

    /** @param array<string, mixed> $config fake-nameserver.php's */
    private function fakeNameserver(array $config): string
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('needs proc_open');
        }
        $process = proc_open(
            [PHP_BINARY, self::FAKE, json_encode($config, JSON_THROW_ON_ERROR), '30'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
        $port = trim((string) fgets($pipes[1]));
        if (preg_match('/^[0-9]+$/D', $port) !== 1) {
            self::markTestSkipped('the fake nameserver could not listen: ' . $port);
        }

        return '127.0.0.1:' . $port;
    }

    private function tempFile(string $text): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'p202-dns-');
        file_put_contents($file, $text);
        $this->files[] = $file;

        return $file;
    }

    /** @return array<string, mixed> */
    private static function records(): array
    {
        return [
            'hooks.example.com' => ['A' => ['93.184.216.34'], 'AAAA' => ['2606:2800:220:1::1']],
            'api.example.com' => ['CNAME' => 'edge.example.net'],
            'edge.example.net' => ['CNAME' => 'pop.example.org'],
            'pop.example.org' => ['A' => ['198.51.100.7', '198.51.100.8']],
            'api.corp.example' => ['A' => ['203.0.113.9']],
            'api' => ['A' => ['192.0.2.1']],
            'inside.example.com' => ['A' => ['10.1.2.3']],
        ];
    }

    /**
     * The brief's case: a nameserver that never answers. The lookup gives
     * up when its time is up, not after resolv.conf's timeout x attempts,
     * and says so; the time is the caller's, however long each try is.
     */
    public function testANameserverThatNeverAnswersIsGivenUpOnWhenTheTimeIsUp(): void
    {
        $silent = $this->silentNameserver();
        $lookup = new DnsLookup([$silent, $silent, $silent], [], 1, 30.0, 5, null);

        $started = microtime(true);
        try {
            $lookup->addresses('hooks.example.com', 0.6);
            self::fail('a lookup nobody answered returned');
        } catch (DnsLookupFailed $e) {
            $took = microtime(true) - $started;
            self::assertSame(
                'hooks.example.com was not looked up within 0.6 seconds: the resolver did not answer in time.',
                $e->getMessage()
            );
        }
        self::assertGreaterThan(0.55, $took, 'it waited for the answer as long as it was allowed');
        self::assertLessThan(0.9, $took, 'and no longer: it took ' . round($took, 2) . ' s');
    }

    /** Each nameserver gets its try's timeout, then the next is asked: one that answers is found. */
    public function testANameserverThatNeverAnswersIsPassedOverForOneThatDoes(): void
    {
        $lookup = new DnsLookup(
            [$this->silentNameserver(), $this->fakeNameserver(['records' => self::records()])],
            [],
            1,
            0.3,
            2,
            null
        );

        $started = microtime(true);
        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
        $took = microtime(true) - $started;
        self::assertGreaterThan(0.25, $took);
        self::assertLessThan(1.0, $took, 'the silent one had its 0.3 s, no more');
    }

    /** One the kernel refuses is not waited for. */
    public function testANameserverThatIsNotThereIsNotWaitedFor(): void
    {
        $absent = $this->absentNameserver();
        $started = microtime(true);
        try {
            (new DnsLookup([$absent], [], 1, 5.0, 2, null))->addresses('hooks.example.com', 5.0);
            self::fail('nobody answered');
        } catch (DnsLookupFailed $e) {
            self::assertSame(
                'hooks.example.com was not looked up: no nameserver in /etc/resolv.conf answered (' . $absent . ').',
                $e->getMessage()
            );
        }
        self::assertLessThan(1.0, microtime(true) - $started);

        $lookup = new DnsLookup([$absent, $this->fakeNameserver(['records' => self::records()])], [], 1, 5.0, 2, null);
        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function answers(): array
    {
        return [
            'both families' => ['hooks.example.com', ['93.184.216.34', '2606:2800:220:1::1']],
            'an alias of an alias' => ['api.example.com', ['198.51.100.7', '198.51.100.8']],
            'upper case and a trailing dot' => ['HOOKS.Example.COM.', ['93.184.216.34', '2606:2800:220:1::1']],
            'a private answer, the guard\'s to refuse' => ['inside.example.com', ['10.1.2.3']],
            'a name that does not exist' => ['nowhere.example.com', []],
        ];
    }

    /**
     * @dataProvider answers
     * @param list<string> $expected
     */
    public function testTheAnswerIsEveryAddressTheNameHas(string $host, array $expected): void
    {
        $lookup = new DnsLookup([$this->fakeNameserver(['records' => self::records()])], [], 1, 2.0, 2, null);

        self::assertSame($expected, $lookup->addresses($host, 5.0));
    }

    /** An answer over UDP that came back cut short is asked again over TCP. */
    public function testATruncatedAnswerIsAskedAgainOverTcp(): void
    {
        $log = $this->tempFile('');
        $lookup = new DnsLookup(
            [$this->fakeNameserver(['records' => self::records(), 'truncate' => true, 'log' => $log])],
            [],
            1,
            2.0,
            2,
            null
        );

        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
        $asked = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        sort($asked);
        self::assertSame(
            [
                'tcp hooks.example.com A',
                'tcp hooks.example.com AAAA',
                'udp hooks.example.com A',
                'udp hooks.example.com AAAA',
            ],
            $asked
        );
    }

    /**
     * A nameserver that answers A and never AAAA (one that drops AAAA
     * queries): the A answer stands once the time is up, as the C
     * library's lookup gave it.
     */
    public function testOneFamilyAnsweredIsTheAnswerWhenTheOtherNeverIs(): void
    {
        $lookup = new DnsLookup(
            [$this->fakeNameserver(['records' => self::records(), 'silent' => ['AAAA']])],
            [],
            1,
            5.0,
            2,
            null
        );

        // A Slack notice's two seconds: the A answer is not held for the AAAA's whole try.
        $started = microtime(true);
        self::assertSame(['93.184.216.34'], $lookup->addresses('hooks.example.com', 2.0));
        $took = microtime(true) - $started;
        self::assertGreaterThan(DnsLookup::GRACE_SECONDS - 0.05, $took, 'the AAAA had its grace');
        self::assertLessThan(DnsLookup::GRACE_SECONDS + 0.4, $took, 'and no more: it took ' . round($took, 2) . ' s');
    }

    /**
     * A reply is an answer only to the query it names: one with another
     * id, or for another question, is not read, so a forged datagram that
     * gets there first adds no address.
     */
    public function testAReplyToAnotherQueryAddsNothing(): void
    {
        $lookup = new DnsLookup(
            [$this->fakeNameserver(['records' => self::records(), 'spoof' => '6.6.6.6'])],
            [],
            1,
            2.0,
            2,
            null
        );

        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));

        // And where only the forgeries come, the question stays unanswered.
        $lookup = new DnsLookup(
            [$this->fakeNameserver(['records' => self::records(), 'spoof' => '6.6.6.6', 'silent' => ['A']])],
            [],
            1,
            0.3,
            2,
            null
        );
        self::assertSame(['2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
    }

    /**
     * resolv.conf's search list, as res_nsearch() applies it: a name with
     * fewer dots than ndots is tried in each domain first, then as it is;
     * one with as many is tried as it is first; one ending in a dot is
     * never searched.
     */
    public function testTheSearchListIsAppliedInTheCLibrarysOrder(): void
    {
        $log = $this->tempFile('');
        $server = $this->fakeNameserver(['records' => self::records(), 'log' => $log]);
        $lookup = new DnsLookup([$server], ['corp.example', 'example.com'], 1, 2.0, 2, null);
        $asked = static function () use ($log): array {
            $lines = array_values(array_filter(
                file($log, FILE_IGNORE_NEW_LINES) ?: [],
                static fn (string $l): bool => str_ends_with($l, ' A')
            ));
            file_put_contents($log, '');

            return $lines;
        };

        self::assertSame(['203.0.113.9'], $lookup->addresses('api', 5.0));
        self::assertSame(['udp api.corp.example A'], $asked());

        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks', 5.0));
        self::assertSame(['udp hooks.corp.example A', 'udp hooks.example.com A'], $asked());

        self::assertSame(['192.0.2.1'], $lookup->addresses('api.', 5.0));
        self::assertSame(['udp api A'], $asked());

        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
        self::assertSame(['udp hooks.example.com A'], $asked(), 'enough dots: as it is, first');

        $lookup = new DnsLookup([$server], ['corp.example'], 2, 2.0, 2, null);
        self::assertSame([], $lookup->addresses('nowhere.example', 5.0));
        self::assertSame(['udp nowhere.example.corp.example A', 'udp nowhere.example A'], $asked());
    }

    /** /etc/hosts answers first, as nsswitch's "files dns" reads it, and DNS is not asked. */
    public function testTheHostsFileAnswersBeforeDns(): void
    {
        $hosts = $this->tempFile("# comment\n127.0.0.1 localhost\n10.0.0.8  intranet.example.com intranet # ours\n"
            . "fd00::8 intranet.example.com\n  \n93.184.216.99 HOOKS.EXAMPLE.COM\n");
        $lookup = new DnsLookup([$this->silentNameserver()], [], 1, 5.0, 2, $hosts);

        $started = microtime(true);
        self::assertSame(['10.0.0.8', 'fd00::8'], $lookup->addresses('intranet.example.com', 2.0));
        self::assertSame(['10.0.0.8'], $lookup->addresses('Intranet', 2.0), 'each name its own lines');
        self::assertSame(['93.184.216.99'], $lookup->addresses('hooks.example.com.', 2.0));
        self::assertLessThan(0.5, microtime(true) - $started, 'the silent nameserver was never asked');
    }

    public function testResolvConfIsReadAsTheCLibraryReadsIt(): void
    {
        $lookup = DnsLookup::fromResolvConf(
            "# a comment\n; another\nnameserver 192.0.2.53\nnameserver not-an-address\nnameserver 2001:db8::53\n"
            . "nameserver fe80::1%eth0\nnameserver 192.0.2.54\ndomain first.example\nsearch Corp.Example. example.com\n"
            . "options rotate ndots:3 timeout:99 attempts:0 timeout:x edns0\n",
            null
        );

        self::assertSame(
            ['192.0.2.53', '2001:db8::53', 'fe80::1%eth0'],
            self::property($lookup, 'nameservers'),
            'at most three'
        );
        self::assertSame(['corp.example', 'example.com'], self::property($lookup, 'search'), 'the last search line');
        self::assertSame(3, self::property($lookup, 'ndots'));
        self::assertSame(30.0, self::property($lookup, 'tryTimeout'), 'glibc caps timeout at 30');
        self::assertSame(1, self::property($lookup, 'attempts'), 'and attempts at no fewer than 1');

        $empty = DnsLookup::fromResolvConf('', null);
        self::assertSame(['127.0.0.1'], self::property($empty, 'nameservers'), 'none named: the local one');
        self::assertSame(5.0, self::property($empty, 'tryTimeout'));
        self::assertSame(2, self::property($empty, 'attempts'));
        self::assertSame(1, self::property($empty, 'ndots'));
    }

    public function testThereIsNoSystemLookupWithoutResolvConf(): void
    {
        self::assertNull(DnsLookup::system(sys_get_temp_dir() . '/p202-no-such-resolv.conf'));
        $conf = $this->tempFile("nameserver 192.0.2.53\n");
        self::assertSame(['192.0.2.53'], self::property(DnsLookup::system($conf, null), 'nameservers'));
        self::assertSame($conf, self::property(DnsLookup::system($conf, $conf), 'hostsFile'));
        self::assertNull(
            DnsLookup::system($conf, sys_get_temp_dir() . '/p202-no-such-hosts'),
            'a hosts file it cannot read is the C library\'s to read'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedReplies(): iterable
    {
        $question = "\x05hooks\x07example\x03com\x00" . pack('nn', 1, 1);
        $header = static fn (int $flags, int $qd, int $an): string => pack('nnnnnn', 7, $flags, $qd, $an, 0, 0);
        $a = "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . "\x5D\xB8\xD8\x22";

        yield 'shorter than a header' => ["\x00\x07\x81\x80"];
        yield 'a query, not a reply' => [$header(0x0100, 1, 1) . $question . $a];
        yield 'another opcode' => [$header(0x8980, 1, 1) . $question . $a];
        yield 'no question' => [$header(0x8180, 0, 1) . $a];
        yield 'another question' => [$header(0x8180, 1, 1) . "\x04evil\x07example\x00" . pack('nn', 1, 1) . $a];
        yield 'another class' => [$header(0x8180, 1, 1) . "\x05hooks\x07example\x03com\x00" . pack('nn', 1, 3) . $a];
        yield 'a type it never asks' => [
            $header(0x8180, 1, 1) . "\x05hooks\x07example\x03com\x00" . pack('nn', 16, 1) . $a,
        ];
        yield 'an answer cut short' => [$header(0x8180, 1, 1) . $question . substr($a, 0, 13)];
        yield 'an answer count past the end' => [$header(0x8180, 1, 2) . $question . $a];
        yield 'a pointer loop' => [$header(0x8180, 1, 1) . "\xC0\x0C" . pack('nn', 1, 1)];
        yield 'a pointer past the end' => [$header(0x8180, 1, 1) . "\xC0\xFF" . pack('nn', 1, 1)];
        yield 'a label past the end' => [$header(0x8180, 1, 0) . "\x3Fhooks"];
        yield 'a reserved label type' => [$header(0x8180, 1, 0) . "\x45hooks\x00" . pack('nn', 1, 1)];
    }

    /** @dataProvider malformedReplies */
    public function testAMalformedReplyIsNotAnAnswer(string $packet): void
    {
        self::assertNull(DnsLookup::parse($packet, 'hooks.example.com'));
    }

    /**
     * Only records for the name asked, or a name it is an alias of, of the
     * type asked, the size of that type, count: what else a reply carries
     * adds no address.
     */
    public function testAReplyGivesOnlyTheAddressesOfTheNameAsked(): void
    {
        $name = "\x05hooks\x07example\x03com\x00";
        $edge = "\x04edge\x07example\x03net\x00";
        $rr = static fn (string $owner, int $type, string $data, int $class = 1): string
            => $owner . pack('nnNn', $type, $class, 60, strlen($data)) . $data;
        $answers = [
            $rr("\xC0\x0C", 5, $edge),
            $rr($edge, 1, "\xC6\x33\x64\x07"),
            $rr("\x05other\x07example\x00", 1, "\x06\x06\x06\x06"),
            $rr("\xC0\x0C", 1, "\x06\x06\x06"),
            $rr("\xC0\x0C", 28, str_repeat("\x06", 16)),
            $rr("\xC0\x0C", 1, "\x07\x07\x07\x07", 3),
            $rr("\xC0\x0C", 1, "\x5D\xB8\xD8\x22"),
        ];
        $packet = pack('nnnnnn', 0xBEEF, 0x8380, 1, count($answers), 0, 0)
            . $name . pack('nn', 1, 1) . implode('', $answers);

        self::assertSame(
            [
                'id' => 0xBEEF,
                'type' => 1,
                'truncated' => true,
                'rcode' => 0,
                'addresses' => ['198.51.100.7', '93.184.216.34'],
            ],
            DnsLookup::parse($packet, 'HOOKS.example.com')
        );
        self::assertNull(DnsLookup::parse($packet, 'edge.example.net'), 'a reply is about the name it asks');
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
    /**
     * resolv.conf's own timeout (5 s) is as long as the whole lookup may
     * take: a dead first nameserver must not use it all, or the second,
     * which answers, is never asked.
     */
    public function testADeadFirstNameserverLeavesTheSecondItsShare(): void
    {
        $lookup = new DnsLookup(
            [$this->silentNameserver(), $this->fakeNameserver(['records' => self::records()])],
            [],
            1,
            5.0,
            2,
            null
        );

        $started = microtime(true);
        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));
        $took = microtime(true) - $started;
        self::assertGreaterThan(2.4, $took, 'the first had half of the five seconds');
        self::assertLessThan(3.2, $took, 'and the second answered at once: it took ' . round($took, 2) . ' s');
    }

    /**
     * A server failure on one search candidate goes on to the next, as
     * res_nsearch() does (ndots:5, as in Kubernetes); a lookup whose every
     * answer was a server failure says so, and is tried again later.
     */
    public function testAServerFailureOnOneSearchNameGoesOnToTheNext(): void
    {
        $records = self::records() + ['hooks.example.com.svc.cluster.local' => ['rcode' => 2]];
        $server = $this->fakeNameserver(['records' => $records]);
        $lookup = new DnsLookup([$server], ['svc.cluster.local'], 5, 2.0, 2, null);

        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $lookup->addresses('hooks.example.com', 5.0));

        $lookup = new DnsLookup(
            [$this->fakeNameserver(['records' => ['hooks.example.com' => ['rcode' => 2]]])],
            [],
            1,
            2.0,
            2,
            null
        );
        $started = microtime(true);
        try {
            $lookup->addresses('hooks.example.com', 5.0);
            self::fail('a server failure read as an answer');
        } catch (DnsLookupFailed $e) {
            self::assertStringStartsWith(
                'hooks.example.com was not looked up: the nameservers in /etc/resolv.conf answered with a server'
                    . ' failure',
                $e->getMessage()
            );
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'a failure is an answer, not a wait');
    }

    /** use-vc asks over TCP only, no-aaaa asks A alone, single-request one query at a time. */
    public function testTheOptionsThatChangeWhatIsSentAreHonoured(): void
    {
        $log = $this->tempFile('');
        $server = $this->fakeNameserver(['records' => self::records(), 'log' => $log]);
        $asked = static function () use ($log): array {
            $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
            file_put_contents($log, '');
            sort($lines);

            return $lines;
        };

        $tcp = new DnsLookup([$server], [], 1, 2.0, 2, null, false, true);
        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $tcp->addresses('hooks.example.com', 5.0));
        self::assertSame(['tcp hooks.example.com A', 'tcp hooks.example.com AAAA'], $asked());

        $v4 = new DnsLookup([$server], [], 1, 2.0, 2, null, false, false, true);
        self::assertSame(['93.184.216.34'], $v4->addresses('hooks.example.com', 5.0));
        self::assertSame(['udp hooks.example.com A'], $asked());

        $single = new DnsLookup([$server], [], 1, 2.0, 2, null, true);
        self::assertSame(['93.184.216.34', '2606:2800:220:1::1'], $single->addresses('hooks.example.com', 5.0));
        self::assertSame(['udp hooks.example.com A', 'udp hooks.example.com AAAA'], $asked());

        $read = DnsLookup::fromResolvConf(
            "nameserver 192.0.2.53\noptions single-request-reopen use-vc no-aaaa\n",
            null
        );
        self::assertTrue(self::property($read, 'oneAtATime'));
        self::assertTrue(self::property($read, 'tcpOnly'));
        self::assertTrue(self::property($read, 'noAaaa'));
        $plain = DnsLookup::fromResolvConf("nameserver 192.0.2.53\noptions rotate edns0\n", null);
        self::assertFalse(self::property($plain, 'oneAtATime'));
        self::assertFalse(self::property($plain, 'tcpOnly'));
        self::assertFalse(self::property($plain, 'noAaaa'));
    }

    /** With no search or domain line, the host name's own domain is searched, as glibc does; and every domain is kept. */
    public function testTheSearchListDefaultsToTheHostsOwnDomain(): void
    {
        $lookup = DnsLookup::fromResolvConf("nameserver 192.0.2.53\n", null, 'web1.Corp.Example');
        self::assertSame(['corp.example'], self::property($lookup, 'search'));
        self::assertSame([], self::property(DnsLookup::fromResolvConf('', null, 'web1'), 'search'));
        $dot = DnsLookup::fromResolvConf("search .\n", null, 'web1.corp.example');
        self::assertSame([], self::property($dot, 'search'));

        $many = DnsLookup::fromResolvConf(
            "search a.example b.example c.example d.example e.example f.example g.example\n",
            null
        );
        self::assertCount(7, self::property($many, 'search'), 'glibc 2.26 and later keep them all');
    }

    /** LOCALDOMAIN replaces the search list and RES_OPTIONS adds options, as res_init() reads them. */
    public function testTheEnvironmentOverridesResolvConf(): void
    {
        $conf = $this->tempFile("nameserver 192.0.2.53\nsearch corp.example\noptions ndots:2\n");
        $was = [getenv('LOCALDOMAIN'), getenv('RES_OPTIONS')];
        try {
            putenv('LOCALDOMAIN=one.example two.example');
            putenv('RES_OPTIONS=ndots:4 use-vc');
            $lookup = DnsLookup::system($conf, null);
            self::assertNotNull($lookup);
            self::assertSame(['one.example', 'two.example'], self::property($lookup, 'search'));
            self::assertSame(4, self::property($lookup, 'ndots'));
            self::assertTrue(self::property($lookup, 'tcpOnly'));
        } finally {
            putenv($was[0] === false ? 'LOCALDOMAIN' : 'LOCALDOMAIN=' . $was[0]);
            putenv($was[1] === false ? 'RES_OPTIONS' : 'RES_OPTIONS=' . $was[1]);
        }
    }

    /** Where the socket functions are disabled, the C library answers, not an Error out of the guard. */
    public function testDisabledSocketFunctionsLeaveTheLookupToTheCLibrary(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('needs proc_open');
        }
        $conf = $this->tempFile("nameserver 192.0.2.53\n");
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . ' echo var_export(\Prosper202\Http\DnsLookup::system(' . var_export($conf, true)
            . ', null) === null, true);';
        $functions = ['stream_socket_client', 'stream_select', 'stream_socket_recvfrom', 'stream_set_blocking'];
        foreach ($functions as $function) {
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $function . ' -r ' . escapeshellarg($script)
                . ' 2>&1', $out, $status);
            self::assertSame(0, $status, implode("\n", $out));
            self::assertSame(['true'], $out, 'with ' . $function . ' disabled');
        }
    }

    /** A hosts file that is there and cannot be read is a failed lookup, not "not listed": DNS would answer instead. */
    public function testAHostsFileThatCannotBeReadIsAFailure(): void
    {
        $lookup = new DnsLookup([$this->silentNameserver()], [], 1, 1.0, 1, sys_get_temp_dir());
        try {
            $lookup->addresses('intranet.example.com', 1.0);
            self::fail('an unreadable hosts file read as empty');
        } catch (DnsLookupFailed $e) {
            self::assertSame(
                'intranet.example.com was not looked up: ' . sys_get_temp_dir() . ' could not be read.',
                $e->getMessage()
            );
        }
        $none = new DnsLookup(
            [$this->fakeNameserver(['records' => self::records()])],
            [],
            1,
            1.0,
            1,
            sys_get_temp_dir() . '/p202-no-such-hosts'
        );
        self::assertSame(
            ['93.184.216.34', '2606:2800:220:1::1'],
            $none->addresses('hooks.example.com', 2.0),
            'no file: DNS'
        );
    }

    /**
     * Aliases are read once each and followed at most MAX_CNAME_HOPS: a
     * reply carrying thousands, listed backwards, is read in moments, and
     * a chain longer than that gives no address.
     */
    public function testALongAliasChainIsReadInLinearTime(): void
    {
        $label = static fn (int $i): string => "\x01n\x03" . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT)
            . "\x02" . str_pad((string) intdiv($i, 1000), 2, '0', STR_PAD_LEFT) . "\x00";
        $reply = static function (int $links) use ($label): string {
            $question = "\x05hooks\x07example\x03com\x00";
            $rr = static fn (string $owner, int $type, string $data): string
                => $owner . pack('nnNn', $type, 1, 60, strlen($data)) . $data;
            $answers = [$rr("\xC0\x0C", 5, $label(0))];
            for ($i = 0; $i < $links - 1; $i++) {
                $answers[] = $rr($label($i), 5, $label($i + 1));
            }
            $answers[] = $rr($label($links - 1), 1, "\xC6\x33\x64\x07");

            return pack('nnnnnn', 7, 0x8180, 1, count($answers), 0, 0) . $question . pack('nn', 1, 1)
                . implode('', array_reverse($answers));
        };

        self::assertSame(['198.51.100.7'], DnsLookup::parse($reply(15), 'hooks.example.com')['addresses'] ?? null);
        $tooLong = DnsLookup::parse($reply(17), 'hooks.example.com');
        self::assertSame([], $tooLong['addresses'] ?? null, 'past the hop cap');

        $started = microtime(true);
        $parsed = DnsLookup::parse($reply(2_600), 'hooks.example.com');
        self::assertSame([], $parsed['addresses'] ?? null);
        self::assertLessThan(0.3, microtime(true) - $started, 'one pass over the records');
    }

    /** A label holding a dot is not two labels: "a.example" in one label is not the name a.example. */
    public function testALabelHoldingADotIsNotTwoLabels(): void
    {
        $packet = pack('nnnnnn', 7, 0x8180, 1, 1, 0, 0) . "\x09a.example\x00" . pack('nn', 1, 1)
            . "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . "\x5D\xB8\xD8\x22";
        self::assertNull(DnsLookup::parse($packet, 'a.example'));
    }
}

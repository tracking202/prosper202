<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * No request path sends a traffic source's server postback (pixel type 4)
 * itself; every one is queued in the notification outbox with the
 * conversion it announces and sent from there (Codex P1 on PR #157).
 *
 * gpb.php sent type 4 synchronously after the conversion committed, and only
 * while the row was new: a send that threw after the commit answered an
 * error, the network's retry was a duplicate, and the postback was never
 * sent. Queued in the row's transaction, the worker retries it.
 *
 * What this holds, over every PHP file under tracking202/, api/ and
 * 202-config/: each call of p202FireTrafficSourcePixels() passes a literal
 * `false` as its fifth argument ($server), and each call of
 * TrafficSourcePixels::fire() passes a literal `false` as its sixth. A file
 * that renders the markup types that way and records its own conversion
 * also asks the writer to queue (`'notify_traffic_source' => true`). The goal
 * notifier queues through the engine, so it is held only to the first rule.
 *
 * And none of them sends the queue before answering (66H8): gpb.php and
 * upx.php did, up to 20 URLs at 10 seconds each, so a slow traffic source held
 * the network's postback open. Outside the outbox and the cron worker, only
 * p202SendQueuedPostbacksAfterAnswer() calls sendDue(), after releasing the
 * client, and both endpoints call it last.
 */
final class ServerPostbacksAreQueuedTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** The two definitions, which pass the flag through rather than choose it. */
    private const DEFINITIONS = [
        '202-config/static-endpoint-helpers.php',
        '202-config/Conversion/TrafficSourcePixels.php',
    ];

    /** @return list<string> */
    private static function files(): array
    {
        $out = [];
        foreach (['tracking202', 'api', '202-config'] as $dir) {
            $it = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree(self::ROOT . $dir));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    $out[] = substr($file->getPathname(), strlen(self::ROOT));
                }
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<array{0: int, 1: string, 2: int}> significant tokens */
    private static function tokens(string $src): array
    {
        $out = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $out[] = [$t[0], $t[1], $t[2]];
                }
            } else {
                $out[] = [0, $t, 0];
            }
        }

        return $out;
    }

    /**
     * The calls in $src of the function `$name` (bare or `\`-qualified,
     * not a method) or of the static `Class::$name` when $class is given,
     * each as [line, list of argument token-texts].
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private static function calls(string $src, string $name, ?string $class = null): array
    {
        $t = self::tokens($src);
        $n = count($t);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if (strcasecmp(ltrim($t[$i][1], '\\'), $name) !== 0 || ($t[$i + 1][1] ?? null) !== '(') {
                continue;
            }
            $prev = $t[$i - 1] ?? [0, '', 0];
            if ($class === null) {
                if (in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                    continue;
                }
            } else {
                $owner = $t[$i - 2][1] ?? '';
                $isOwner = strcasecmp(substr(ltrim($owner, '\\'), -strlen($class)), $class) === 0;
                if ($prev[0] !== T_DOUBLE_COLON || !$isOwner) {
                    continue;
                }
            }
            $args = [];
            $current = '';
            $depth = 0;
            for ($k = $i + 1; $k < $n; $k++) {
                $text = $t[$k][1];
                if (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        if (trim($current) !== '') {
                            $args[] = trim($current);
                        }
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $args[] = trim($current);
                    $current = '';
                    continue;
                }
                $current .= $text . ' ';
            }
            $out[] = [$t[$i][2], $args];
        }

        return $out;
    }

    public function testNoRequestPathSendsAServerPostbackItself(): void
    {
        $firing = [];
        foreach (self::files() as $file) {
            if (in_array($file, self::DEFINITIONS, true)) {
                continue;
            }
            $src = (string) file_get_contents(self::ROOT . $file);
            foreach (self::calls($src, 'p202FireTrafficSourcePixels') as [$line, $args]) {
                self::assertSame(
                    'false',
                    strtolower($args[4] ?? '(omitted)'),
                    "$file:$line calls p202FireTrafficSourcePixels() without \$server = false, so it sends the type-4 postbacks "
                    . 'itself; queue them with the conversion (notify_traffic_source); the worker sends them'
                );
                $firing[$file] = true;
            }
            foreach (self::calls($src, 'fire', 'TrafficSourcePixels') as [$line, $args]) {
                self::assertSame(
                    'false',
                    strtolower($args[5] ?? '(omitted)'),
                    "$file:$line calls TrafficSourcePixels::fire() without \$server = false"
                );
            }
        }
        self::assertArrayHasKey('tracking202/static/gpb.php', $firing, 'the scan found the postback endpoint');
        self::assertArrayHasKey('tracking202/static/upx.php', $firing, 'and the universal pixel');

        foreach (array_keys($firing) as $file) {
            $src = (string) file_get_contents(self::ROOT . $file);
            $queues = false;
            foreach (self::calls($src, 'p202RecordConversion') as [, $args]) {
                $queues = $queues || preg_match("/'notify_traffic_source'\s*=>\s*true\b/i", $args[1] ?? '') === 1;
            }
            self::assertTrue($queues, "$file renders the traffic source's pixels but never asks p202RecordConversion() to queue its server postbacks");
        }
    }

    public function testNoRequestPathSendsTheQueue(): void
    {
        $sending = [];
        $read = 0;
        foreach (self::files() as $file) {
            // The queues' definitions; DestinationQueue::sendDue() is called only by the cron worker, which the scan does not read.
            $senders = [
                '202-config/Notifications/NotificationOutbox.php',
                '202-config/Destinations/DestinationQueue.php',
                '202-config/static-endpoint-helpers.php',
            ];
            if (in_array($file, $senders, true)) {
                continue;
            }
            $read++;
            $t = self::tokens((string) file_get_contents(self::ROOT . $file));
            foreach ($t as $i => $token) {
                if (strcasecmp($token[1], 'sendDue') === 0 && ($t[$i + 1][1] ?? null) === '(') {
                    $sending[] = $file . ':' . $token[2];
                }
            }
        }
        self::assertGreaterThan(100, $read, 'the scan read the tree');
        self::assertSame([], $sending, 'a request path sends queued postbacks before it answers');

        $helpers = (string) file_get_contents(self::ROOT . '202-config/static-endpoint-helpers.php');
        self::assertSame(1, substr_count($helpers, '->sendDue('), 'the helpers send in one place');
        $body = substr($helpers, (int) strpos($helpers, 'function p202SendQueuedPostbacksAfterAnswer('));
        $body = substr($body, 0, (int) strpos($body, '->sendDue('));
        self::assertStringContainsString('$released = fastcgi_finish_request();', $body, 'after releasing the client');
        self::assertStringContainsString("if (\$released !== true) {\n            return;", $body, 'and only then');

        foreach (['tracking202/static/gpb.php', 'tracking202/static/upx.php'] as $file) {
            $t = self::tokens((string) file_get_contents(self::ROOT . $file));
            $last = null;
            foreach ($t as $i => $token) {
                if ($token[0] === T_STRING && ($t[$i + 1][1] ?? null) === '(') {
                    $last = $token[1];
                }
            }
            self::assertSame('p202SendQueuedPostbacksAfterAnswer', $last, "$file sends after its last output");
        }
    }

    public function testTheCallReaderReadsArgumentsNotText(): void
    {
        $src = '<?php p202FireTrafficSourcePixels($db, f($a, $b), [1, 2], null, false); '
            . '$o->p202FireTrafficSourcePixels($x); '
            . "p202RecordConversion(\$db, ['a' => g(1, 2), 'notify_traffic_source' => true], 'x'); "
            . 'X\\TrafficSourcePixels::fire($c, 1, [], null, true, false); Other::fire(1, 2, 3, 4, 5, 6);';
        $fire = self::calls($src, 'p202FireTrafficSourcePixels');
        self::assertCount(1, $fire, 'a method of the same name is not the function');
        self::assertSame(['$db', 'f ( $a , $b )', '[ 1 , 2 ]', 'null', 'false'], $fire[0][1]);
        self::assertCount(1, self::calls($src, 'fire', 'TrafficSourcePixels'), 'another class\'s fire() is not this one');
        self::assertSame('false', self::calls($src, 'fire', 'TrafficSourcePixels')[0][1][5]);
        self::assertMatchesRegularExpression(
            "/'notify_traffic_source'\s*=>\s*true\b/",
            self::calls($src, 'p202RecordConversion')[0][1][1]
        );
    }
}

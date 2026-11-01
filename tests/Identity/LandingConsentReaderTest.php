<?php

declare(strict_types=1);

namespace Tests\Identity;

use PHPUnit\Framework\TestCase;
use Prosper202\Identity\RequestSignals;

/**
 * The landing script reads ?p202_consent as the tracker does: each query
 * below is run through the shipped script's own reader and link decorator
 * under node, and each answer is held to PHP's parse_str() and
 * RequestSignals::consentGiven() on the same query.
 */
final class LandingConsentReaderTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../tracking202/static/landing.php';

    /** Spellings PHP folds onto p202_consent, or does not, by its name and its value. */
    private const QUERIES = [
        'p202_consent=0', 'p202_consent=1', 'p202_consent=%201%20', 'p202_consent[]=0', 'p202_consent[]=1',
        'p202.consent=0', 'p202.consent=1', 'p202%20consent=0', '%20p202_consent=0', '%20%20p202.consent=1',
        'p202[consent=0', 'p202[consent=1', 'p202[con.sent=1', 'p202_consent[=1', 'p202_consent]=0',
        'p202_consent[x]=1', 'p202_consent[x=1', 'p202_consent=1&p202_consent[]=0',
        'p202_consent[]=0&p202_consent=1', 'p202_consent[a]=1&p202_consent=1', 'p202_consent=1&p202_consent[a]=1',
        'p202_consent=%C2%A01', 'p202_consent=%0B1', 'p202_consent=1%00', 'p202_consent=%091%0A',
        'P202_consent=0', 'p202_consentx=0', 'p202_consent%5B%5D=0', '[p202_consent=0',
        'p202_consent=0&p202.consent=1', 'p202.consent=1&p202_consent=0', 'p202_consent=', 'a=1', 'p202+consent=0',
        'p202_consent=1&p202_consent=0', 'p202_consent=0&p202_consent=1', 'p202_consent%00x=0',
        'p202_consent%00=0', 'p202.consent%00x=0', 'p202_consent%00[]=0', 'p202_consent[%00]=0',
        'p202%00_consent=0', 'p202_consent=1&p202_consent%00x=0', '%00p202_consent=0', '%20p202_consent%00=1',
    ];

    public function testTheScriptReadsEachSpellingAsTheTrackerDoes(): void
    {
        $answers = $this->runScript();
        $expected = [];
        $actual = [];
        foreach (self::QUERIES as $i => $query) {
            parse_str($query, $get);
            $expected[$query] = !array_key_exists(RequestSignals::CONSENT_PARAM, $get) ? 'absent'
                : (RequestSignals::consentGiven($get) ? 'consent' : 'refuse');
            $actual[$query] = $answers[$i]['read'];
        }

        self::assertSame($expected, $actual);
    }

    /**
     * A followed link keeps none of the page's spellings: refused, the
     * tracker reads the refusal the script sets, and with consent it reads
     * no p202_consent at all.
     */
    public function testAFollowedLinkCarriesOnlyTheScriptsOwnAnswer(): void
    {
        $answers = $this->runScript();
        $expected = [];
        $actual = [];
        foreach (self::QUERIES as $i => $query) {
            foreach (['refused' => '0', 'given' => null] as $state => $value) {
                $url = parse_url($answers[$i][$state]);
                parse_str($url['query'] ?? '', $get);
                $expected["$query, $state"] = $value;
                $actual["$query, $state"] = $get[RequestSignals::CONSENT_PARAM] ?? null;
            }
        }

        self::assertSame($expected, $actual);
    }

    /** @return list<array{read: string, refused: string, given: string}> each query's answers, in order */
    private function runScript(): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            // CI's runners carry node: a run there that cannot find it has not checked anything.
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $source = (string) file_get_contents(self::SCRIPT);
        $reader = self::between($source, "// A query name as PHP's \$_GET files it", 'if (p202Refused ||');
        $decorator = self::between($source, 'function p202DecorateLink(a) {', 'function p202OnFollow(ev) {');
        $program = <<<'JS'
            var queries = JSON.parse(require('fs').readFileSync(0, 'utf8'));
            var P202_CONSENT_KEY = 'p202_consent';
            var p202TrackerHost = 'tracker.example';
            var p202CampaignCapture = false;
            var given = false;
            function p202ConsentGiven() { return given; }
            function p202Lpid() { return ''; }
            var out = queries.map(function(query) {
                var _params = new URLSearchParams(query);
            JS . "\n" . $reader . "\n" . $decorator . "\n" . <<<'JS'
                var answer = {read: p202ConsentParam === undefined ? 'absent' : (p202Refused ? 'refuse' : 'consent')};
                [['refused', false], ['given', true]].forEach(function(state) {
                    given = state[1];
                    var a = {href: 'https://tracker.example/lp?' + query, hostname: 'tracker.example'};
                    p202DecorateLink(a);
                    answer[state[0]] = a.href;
                });
                return answer;
            });
            JS . "\n" . 'process.stdout.write(JSON.stringify(out));';

        $proc = proc_open([$node, '-e', $program], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($proc, 'node could not be started');
        fwrite($pipes[0], json_encode(self::QUERIES, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $stderr);
        $answers = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($answers);
        self::assertCount(count(self::QUERIES), $answers, $stdout);

        return $answers;
    }

    /** The script's text from $start up to $end, each found exactly once. */
    private static function between(string $source, string $start, string $end): string
    {
        self::assertSame(1, substr_count($source, $start), "landing.php has one '$start'");
        self::assertSame(1, substr_count($source, $end), "landing.php has one '$end'");
        $from = strpos($source, $start);
        $to = strpos($source, $end, (int) $from);
        self::assertIsInt($to, "'$end' follows '$start'");

        return substr($source, (int) $from, $to - (int) $from);
    }
}

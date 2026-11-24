<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickId;
use Prosper202\Click\PublicClickId;

/**
 * A cloaked click's public id (P0ZN): its neighbours' ids cannot be
 * worked out from it, it never names a click an id from before 1.9.80
 * names, and PHP, the Web Crypto reference the edge is held to and an
 * outside computation (vectors.json, made with Python's hmac) give the same
 * id for every vector.
 */
final class PublicClickIdTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/click-public-id/';

    /** @return list<array{key_hex: string, click_id: int, public_id: string}> */
    private static function vectors(): array
    {
        $json = (string) file_get_contents(self::FIXTURES . 'vectors.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data['vectors'] ?? null);
        self::assertGreaterThanOrEqual(30, count($data['vectors']));

        return $data['vectors'];
    }

    public function testEveryVectorIsTheIdPhpIssues(): void
    {
        foreach (self::vectors() as $v) {
            self::assertSame(
                $v['public_id'],
                PublicClickId::issue($v['click_id'], (string) hex2bin($v['key_hex'])),
                'click ' . $v['click_id'] . ' under key ' . $v['key_hex']
            );
        }
    }

    public function testTheWebCryptoReferenceIssuesEveryVector(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            // CI's runners carry node: a run there that cannot find it has not checked anything.
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the reference was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $proc = proc_open(
            [$node, self::FIXTURES . 'reference.mjs', self::FIXTURES . 'vectors.json'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc, 'node could not be started');
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), 'reference.mjs failed: ' . $err);

        $answers = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $vectors = self::vectors();
        self::assertCount(count($vectors), $answers);
        foreach ($vectors as $i => $v) {
            $answer = $answers[$i]['public_id'] ?? null;
            self::assertSame($v['public_id'], $answer, 'reference.mjs, click ' . $v['click_id']);
        }
    }

    public function testTheIdIsAOneTheClickIdAndSixDigits(): void
    {
        $key = random_bytes(32);
        foreach ([1, 7, 123456, 10_000_000_000, PublicClickId::MAX_CLICK_ID] as $click) {
            $id = PublicClickId::issue($click, $key);
            self::assertMatchesRegularExpression('/\A1\d{18}\z/D', $id);
            self::assertSame($click, (int) substr($id, 1, 12));
            self::assertSame(PublicClickId::suffix($click, $key), (int) substr($id, 13));
            // Every reader parses a pci with ClickId::parse(): 19 digits and below PHP_INT_MAX.
            self::assertSame((int) $id, ClickId::parse($id));
            self::assertFalse(PublicClickId::isLegacy((int) $id));
        }
    }

    /**
     * Holding click N's link and knowing the format, a neighbour's id is a
     * guess at its six digits: across 5,000 consecutive clicks the step from
     * one suffix to the next takes nearly every value, each key gives other
     * suffixes, and none of the 81 ids the old format let one try is ever the
     * neighbour's.
     */
    public function testANeighboursIdCannotBeWorkedOutFromOne(): void
    {
        $key = random_bytes(32);
        $other = random_bytes(32);
        $steps = [];
        $sameUnderBothKeys = 0;
        $start = 41_000_000;
        $previous = PublicClickId::suffix($start, $key);
        for ($click = $start + 1; $click <= $start + 5000; $click++) {
            $suffix = PublicClickId::suffix($click, $key);
            $steps[($suffix - $previous + 1_000_000) % 1_000_000] = true;
            $previous = $suffix;
            if ($suffix === PublicClickId::suffix($click, $other)) {
                $sameUnderBothKeys++;
            }
        }
        self::assertGreaterThan(4900, count($steps), 'the step between neighbours\' suffixes repeats');
        self::assertLessThan(5, $sameUnderBothKeys, 'the suffix does not depend on the key');

        $mine = 52_345;
        $neighbours = [PublicClickId::issue($mine - 1, $key), PublicClickId::issue($mine + 1, $key)];
        foreach ([$mine - 1, $mine + 1] as $near) {
            for ($a = 1; $a <= 9; $a++) {
                for ($b = 1; $b <= 9; $b++) {
                    self::assertNotContains($a . $near . $b, $neighbours);
                }
            }
        }
        // My own suffix on a neighbour's id names nothing.
        $mineSuffix = substr(PublicClickId::issue($mine, $key), 13);
        foreach ([$mine - 1, $mine + 1] as $near) {
            $guess = '1' . str_pad((string) $near, 12, '0', STR_PAD_LEFT) . $mineSuffix;
            self::assertNull(PublicClickId::clickOf($guess, $key));
        }
    }

    /**
     * An id from before 1.9.80 is the click id with a digit either side, so
     * it is two digits longer than the click id: below FLOOR for any click id
     * under 10^16, whatever order ids were allocated in, and so never an id
     * issued now (each at least FLOOR).
     */
    public function testNoIdFromBefore1980IsAnIdIssuedNow(): void
    {
        $largestLegacy = (int) ('9' . '9999999999999999' . '9');
        self::assertSame(18, strlen((string) $largestLegacy));
        self::assertTrue(PublicClickId::isLegacy($largestLegacy));
        self::assertLessThan(PublicClickId::FLOOR, $largestLegacy);

        $key = random_bytes(32);
        self::assertSame(PublicClickId::FLOOR, (int) ('1' . str_repeat('0', 18)));
        self::assertGreaterThanOrEqual(PublicClickId::FLOOR, (int) PublicClickId::issue(1, $key));
        self::assertGreaterThanOrEqual(PublicClickId::FLOOR, (int) PublicClickId::issueUnkeyed(1));
        self::assertTrue(PublicClickId::isLegacy(0));
    }

    public function testClickOfVouchesOnlyForAnIdIssuedUnderTheKey(): void
    {
        $key = random_bytes(32);
        $id = PublicClickId::issue(98_765, $key);
        self::assertSame(98_765, PublicClickId::clickOf($id, $key));
        self::assertNull(PublicClickId::clickOf($id, random_bytes(32)));
        self::assertNull(PublicClickId::clickOf(substr($id, 0, -1) . ((int) substr($id, -1) + 1) % 10, $key));
        self::assertNull(PublicClickId::clickOf('3987654', $key), 'an id from before 1.9.80');
        self::assertNull(
            PublicClickId::clickOf((int) $id, $key),
            'read as a number, it was read by something that may have rounded it'
        );
        self::assertNull(PublicClickId::clickOf(' ' . $id, $key));
        self::assertNull(PublicClickId::clickOf($id . "\n", $key));
        self::assertNull(PublicClickId::clickOf('1000000000000' . substr($id, 13), $key), 'click 0');
    }

    public function testAClickIdOutsideTheTwelveDigitsIsRefused(): void
    {
        $key = random_bytes(32);
        foreach ([0, -1, PublicClickId::MAX_CLICK_ID + 1] as $click) {
            try {
                PublicClickId::issue($click, $key);
                self::fail('issued an id for click ' . $click);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString((string) $click, $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        PublicClickId::issue(1, random_bytes(16));
    }

    public function testAnUnkeyedIdHasTheSameShape(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $id = PublicClickId::issueUnkeyed(555);
            self::assertMatchesRegularExpression('/\A1000000000555\d{6}\z/D', $id);
            $ids[$id] = true;
        }
        self::assertGreaterThan(40, count($ids), 'the unkeyed suffix is not random');
    }
}

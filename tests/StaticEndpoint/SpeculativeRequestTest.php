<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for p202IsSpeculativeRequest() — the detector every
 * click-recording redirect endpoint (dl/lp/rtr/off/…) uses to answer a
 * browser prefetch/prerender/HEAD with a 204 and record NOTHING, so the
 * speculative hit plus the real navigation don't double-count one click.
 *
 * The bug this locks down: the legacy Purpose / X-Purpose / X-moz headers were
 * matched by EXACT equality while Sec-Purpose used a substring match, so a
 * request advertising itself as "Purpose: prefetch;prerender" (a real
 * comma/semicolon-joined variant) slipped past the guard and was counted as a
 * real click on top of the navigation.
 *
 * The function lives in the connect2.php bootstrap, which cannot be included in
 * a unit test (DB/session side effects), so we extract just its source and eval
 * it in isolation — this exercises the REAL production code, not a copy.
 */
final class SpeculativeRequestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (function_exists('p202IsSpeculativeRequest')) {
            return;
        }
        $src = file_get_contents(__DIR__ . '/../../202-config/connect2.php');
        if ($src === false
            || !preg_match('/function p202IsSpeculativeRequest\(\): bool\s*\{.*?\n\}/s', $src, $m)) {
            self::fail('could not extract p202IsSpeculativeRequest() from connect2.php');
        }
        eval($m[0]);
    }

    /**
     * @dataProvider headerCases
     * @param array<string,string> $server
     */
    public function testSpeculativeDetection(array $server, bool $expected, string $why): void
    {
        $_SERVER = array_merge(['REQUEST_METHOD' => 'GET'], $server);
        self::assertSame($expected, p202IsSpeculativeRequest(), $why);
    }

    /**
     * @return array<string, array{0: array<string,string>, 1: bool, 2: string}>
     */
    public static function headerCases(): array
    {
        return [
            'plain GET counts'                => [[], false, 'a real navigation must be recorded'],
            'HEAD probe suppressed'           => [['REQUEST_METHOD' => 'HEAD'], true, 'link-preview HEAD probes record nothing'],
            'Sec-Purpose prefetch'            => [['HTTP_SEC_PURPOSE' => 'prefetch'], true, 'modern Chrome prefetch'],
            'Sec-Purpose joined'              => [['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], true, 'modern Chrome prerender'],
            'Sec-Purpose prerender'           => [['HTTP_SEC_PURPOSE' => 'prerender'], true, 'prerender only'],
            'Purpose prefetch'                => [['HTTP_PURPOSE' => 'prefetch'], true, 'legacy Chrome prefetch'],
            'Purpose joined (the leak)'       => [['HTTP_PURPOSE' => 'prefetch;prerender'], true, 'joined legacy Purpose must NOT slip past the guard'],
            'Purpose comma-joined'            => [['HTTP_PURPOSE' => 'prefetch, prerender'], true, 'comma-joined variant too'],
            'Purpose uppercase'               => [['HTTP_PURPOSE' => 'PREFETCH'], true, 'case-insensitive'],
            'X-Purpose preview'               => [['HTTP_X_PURPOSE' => 'preview'], true, 'Safari link preview'],
            'X-Purpose prefetch joined'       => [['HTTP_X_PURPOSE' => 'prefetch;x'], true, 'Safari joined variant'],
            'X-moz prefetch'                  => [['HTTP_X_MOZ' => 'prefetch'], true, 'Firefox prefetch'],
            'unrelated Purpose value counts'  => [['HTTP_PURPOSE' => 'navigate'], false, 'a non-speculative Purpose must still record'],
        ];
    }
}

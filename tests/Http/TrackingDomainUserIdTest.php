<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Whose stored tracking domain is read: a session user id that is there but
 * is not a positive whole number used to read as "nobody signed in", which
 * named the first account's domain.
 */
final class TrackingDomainUserIdTest extends TestCase
{
    /**
     * @dataProvider cases
     * @param array<mixed> $session
     */
    public function testWhoseDomainIsRead(?int $given, array $session, string $expected): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/202-config/functions-shared.php', true) . ';'
            . ' try { echo p202TrackingDomainUserId(' . var_export($given, true) . ', '
            . var_export($session, true) . '); }'
            . ' catch (\RuntimeException $e) { echo "refused"; }';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        self::assertSame($expected, trim((string)$out));
    }

    /** @return iterable<string, array{?int, array<mixed>, string}> */
    public static function cases(): iterable
    {
        yield 'given' => [5, ['user_id' => 9], '5'];
        yield 'given zero' => [0, ['user_id' => 9], 'refused'];
        yield 'given negative' => [-1, [], 'refused'];
        yield 'nobody signed in' => [null, [], '1'];
        yield 'null in the session' => [null, ['user_id' => null], '1'];
        yield 'int' => [null, ['user_id' => 9], '9'];
        yield 'digits' => [null, ['user_id' => '12'], '12'];
        yield 'empty string' => [null, ['user_id' => ''], 'refused'];
        yield 'zero' => [null, ['user_id' => 0], 'refused'];
        yield 'zero string' => [null, ['user_id' => '0'], 'refused'];
        yield 'not a number' => [null, ['user_id' => 'abc'], 'refused'];
        yield 'leading zero' => [null, ['user_id' => '012'], 'refused'];
        yield 'negative' => [null, ['user_id' => -3], 'refused'];
        yield 'array' => [null, ['user_id' => [1]], 'refused'];
    }
}

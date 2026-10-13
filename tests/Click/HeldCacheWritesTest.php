<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * Cache writes made while a click's transaction is open are written when it
 * commits and dropped when it does not.
 *
 * rtr.php resolves the redirect URL's site URL id inside the transaction,
 * and INDEXES::get_site_url_id() caches the id of a row it inserts at once:
 * a rollback after it left the entry naming no row, and every later click to
 * that URL stored the dangling id for the 7 days the entry lived (measured
 * on a live instance with memcached). The real setCache() and
 * p202WithHeldCacheWrites() are lifted out of connect2.php under other
 * names, so they cannot collide with the setCache() other suites declare.
 */
final class HeldCacheWritesTest extends TestCase
{
    /** @var list<array{string, mixed, mixed}> */
    public static array $written = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        if (function_exists('p202HeldTest_setCache')) {
            return;
        }
        $connect2 = __DIR__ . '/../../202-config/connect2.php';
        foreach (['setCache', 'p202WithHeldCacheWrites'] as $name) {
            $source = lift_function($connect2, $name);
            eval(str_replace(
                ['setCache(', 'p202WithHeldCacheWrites('],
                ['p202HeldTest_setCache(', 'p202HeldTest_withHeld('],
                $source
            ));
        }
    }

    protected function setUp(): void
    {
        self::$written = [];
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcache'] = new class {
            public function set(string $key, mixed $value, mixed $exp): bool
            {
                HeldCacheWritesTest::$written[] = [$key, $value, $exp];

                return true;
            }
        };
        $GLOBALS['p202HeldCacheWrites'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['whatCache'], $GLOBALS['memcache'], $GLOBALS['p202HeldCacheWrites']);
    }

    public function testAWriteGoesStraightToTheCacheWhenNothingIsHeld(): void
    {
        self::assertTrue(\p202HeldTest_setCache('url-id-a', 7, 604800));

        self::assertSame([['url-id-a', 7, 604800]], self::$written);
    }

    public function testHeldWritesAreWrittenInOrderWhenTheWorkReturns(): void
    {
        \p202HeldTest_withHeld(static function (): void {
            self::assertTrue(\p202HeldTest_setCache('domain-id-a', 9, 2592000));
            self::assertTrue(\p202HeldTest_setCache('url-id-a', 78, 604800));
            self::assertSame([], self::$written, 'nothing reaches the cache while the transaction is open');
        });

        self::assertSame([['domain-id-a', 9, 2592000], ['url-id-a', 78, 604800]], self::$written);
        \p202HeldTest_setCache('url-id-b', 79, 604800);
        self::assertSame(['url-id-b', 79, 604800], self::$written[2], 'and writes go straight through again');
    }

    public function testHeldWritesAreDroppedAndTheFailurePassedOnWhenTheWorkThrows(): void
    {
        $failure = new \Error('the transaction was rolled back');
        try {
            \p202HeldTest_withHeld(static function () use ($failure): void {
                \p202HeldTest_setCache('url-id-a', 75, 604800);
                throw $failure;
            });
            self::fail('the failure reaches the caller');
        } catch (\Error $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], self::$written);
        \p202HeldTest_setCache('url-id-b', 76, 604800);
        self::assertSame([['url-id-b', 76, 604800]], self::$written, 'holding stops with the failure');
    }

    public function testTheDefaultExpiryIsAppliedWhenAHeldWriteIsReleased(): void
    {
        \p202HeldTest_withHeld(static fn () => \p202HeldTest_setCache('keyword-id-a', 3));

        self::assertSame([['keyword-id-a', 3, 2592000]], self::$written);
    }

    public function testHoldingInsideAHoldIsRefusedAndTheOuterWritesAreKept(): void
    {
        \p202HeldTest_withHeld(static function (): void {
            \p202HeldTest_setCache('url-id-a', 80, 604800);
            try {
                \p202HeldTest_withHeld(static fn () => \p202HeldTest_setCache('url-id-b', 81, 604800));
                self::fail('a second hold is refused');
            } catch (\LogicException) {
            }
        });

        self::assertSame([['url-id-a', 80, 604800]], self::$written);
    }

    /**
     * The hold only works where the click's write runs inside it: rtr.php's
     * one call of the writer is in the arguments of its one call of
     * p202WithHeldCacheWrites().
     */
    public function testRtrWritesTheRotatorClickInsideTheHold(): void
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(__DIR__ . '/../../tracking202/redirect/rtr.php'));
        $code = array_values(array_filter(
            $tokens,
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        foreach ($code as $i => $t) {
            // An unqualified call is the global function only without these.
            self::assertFalse(
                $t->is(T_NAMESPACE) || ($t->is(T_USE) && ($code[$i + 1] ?? null)?->is(T_FUNCTION)),
                'rtr.php declares no namespace and imports no function; line ' . $t->line
            );
        }

        $holds = [];
        $writes = [];
        foreach ($code as $i => $t) {
            $next = $code[$i + 1] ?? null;
            $prev = $code[$i - 1] ?? null;
            if ($t->is(T_STRING) && $t->text === 'p202WithHeldCacheWrites' && $next?->text === '(') {
                self::assertFalse(
                    $prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]),
                    'line ' . $t->line . ' calls the global function'
                );
                $holds[] = [$i + 1, self::closingParen($code, $i + 1)];
            }
            $member = $prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);
            if ($t->is(T_STRING) && $t->text === 'write' && $next?->text === '(' && $member) {
                $writes[] = $i;
            }
        }

        self::assertCount(1, $holds, 'rtr.php holds its cache writes once');
        self::assertCount(1, $writes, 'rtr.php writes its click once');
        [$open, $close] = $holds[0];
        self::assertTrue(
            $writes[0] > $open && $writes[0] < $close,
            'the write on line ' . $code[$writes[0]]->line . ' runs outside the hold on line ' . $code[$open]->line
        );
    }

    /** @param list<\PhpToken> $code */
    private static function closingParen(array $code, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = count($code); $i < $n; $i++) {
            $depth += match ($code[$i]->text) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $i;
            }
        }
        self::fail('unbalanced parentheses after token ' . $open);
    }
}

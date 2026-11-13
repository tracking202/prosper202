<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\DestinationQueue;

/**
 * DestinationQueue reads its three refusal columns as the offline export's
 * reasonFor() does: only a 0 is "not refused". A NULL, a missing column or
 * any other value withholds (CLAUDE.md #11). The SQL gives only 0 or 1, so
 * the integration tests cannot see a reader that loosens this.
 */
final class RefusedConsentReadTest extends TestCase
{
    private static function read(array $conv): ?string
    {
        $method = new \ReflectionMethod(DestinationQueue::class, 'refusedConsent');

        return $method->invoke(null, $conv);
    }

    /** @return array<string, array{0: mixed}> */
    public static function notZero(): array
    {
        return [
            '1' => ['1'], 'an int 1' => [1], 'null' => [null], '2' => ['2'], 'empty' => [''], 'absent' => ['absent'],
        ];
    }

    /** @dataProvider notZero */
    public function testAnythingButZeroInAnyColumnIsARefusal(mixed $value): void
    {
        foreach (['refused_request', 'refused_replaced', 'refused_visit'] as $column) {
            $conv = ['refused_request' => '0', 'refused_replaced' => '0', 'refused_visit' => '0'];
            if ($value === 'absent') {
                unset($conv[$column]);
            } else {
                $conv[$column] = $value;
            }
            self::assertNotNull(self::read($conv), "$column " . var_export($value, true) . ' withholds');
        }
    }

    public function testZeroInEveryColumnIsNoRefusal(): void
    {
        self::assertNull(self::read(['refused_request' => '0', 'refused_replaced' => '0', 'refused_visit' => '0']));
        self::assertNull(self::read(['refused_request' => 0, 'refused_replaced' => 0, 'refused_visit' => 0]));
    }
}

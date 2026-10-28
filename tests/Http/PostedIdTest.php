<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\PostedId;

/**
 * A posted id is read exactly or not at all: `(int)` made `12abc` 12, so LTV
 * Settings' Delete removed a webhook nobody named.
 */
final class PostedIdTest extends TestCase
{
    public function testAnIdIsReadAsItIsWritten(): void
    {
        self::assertSame(12, PostedId::read('12'));
        self::assertSame(1, PostedId::read('1'));
        self::assertSame(PHP_INT_MAX, PostedId::read((string) PHP_INT_MAX));
    }

    public function testAnythingElseIsNoId(): void
    {
        $refused = [
            '12abc', 'abc', '0', '-1', '', ' 12', '12 ', "12\n", '012', '+12', '1.5', '1e3', '0x1A',
            '9223372036854775808', '9999999999999999999', '12345678901234567890',
            12, 12.0, true, null, ['12'],
        ];
        foreach ($refused as $value) {
            self::assertNull(PostedId::read($value), var_export($value, true));
        }
    }
}

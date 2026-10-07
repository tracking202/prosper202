<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\AccountPixels;

/**
 * What Setup › Traffic Sources' account form posts for its pixels, read as
 * the rows the account is left with (AccountPixels::fromForm()). The save
 * itself runs against a real database in AccountPixelsIntegrationTest.
 */
final class AccountPixelsTest extends TestCase
{
    public function testAFormWithNoPixelCodeListsNoPixels(): void
    {
        $errors = [];
        // The form always posts its first row; cleared, it is no pixel.
        self::assertSame([], AccountPixels::fromForm([
            'pixel_type_id' => ['5'], 'pixel_code' => ['  '], 'pixel_id' => ['12'], 'pixel_correction_url' => [''],
        ], $errors));
        self::assertSame([], AccountPixels::fromForm(['pixel_type_id' => [''], 'pixel_code' => [''], 'pixel_id' => ['']], $errors));
        // A form that posts no pixel arrays at all (every row removed).
        self::assertSame([], AccountPixels::fromForm([], $errors));
        self::assertSame([], $errors);
    }

    public function testRowsAreReadByPositionWithTheirIds(): void
    {
        $errors = [];
        $rows = AccountPixels::fromForm([
            'pixel_type_id' => ['1', '4', '5'],
            'pixel_code' => [' https://a.example/p.gif ', 'https://net.example/pb?tx=[[subid]]', '<script>x("a\\\\b")</script>'],
            'pixel_id' => ['7', '', 'x9'],
            'pixel_correction_url' => ['', ' https://net.example/fix ', ''],
        ], $errors);
        self::assertSame([], $errors);
        self::assertSame([
            ['pixel_id' => 7, 'pixel_type_id' => 1, 'pixel_code' => 'https://a.example/p.gif', 'correction_url' => ''],
            ['pixel_id' => 0, 'pixel_type_id' => 4, 'pixel_code' => 'https://net.example/pb?tx=[[subid]]', 'correction_url' => 'https://net.example/fix'],
            // An id that is not one is a new pixel, never pixel 9.
            ['pixel_id' => 0, 'pixel_type_id' => 5, 'pixel_code' => '<script>x("a\\\\b")</script>', 'correction_url' => ''],
        ], $rows);
    }

    public function testARawCodeKeepsEveryBackslash(): void
    {
        $code = "<script>var s = \"a\\nb\", t = \"c\\\\d\", u = '\\'';</script>";
        $errors = [];
        $rows = AccountPixels::fromForm(['pixel_type_id' => ['5'], 'pixel_code' => [$code], 'pixel_id' => ['']], $errors);
        self::assertSame($code, $rows[0]['pixel_code']);
        // Saved again from what the form shows, it is still the same bytes.
        $again = AccountPixels::fromForm(['pixel_type_id' => ['5'], 'pixel_code' => [$rows[0]['pixel_code']], 'pixel_id' => ['3']], $errors);
        self::assertSame($code, $again[0]['pixel_code']);
    }

    public function testABrowsersLineBreaksAreStoredAsNewlines(): void
    {
        $errors = [];
        $rows = AccountPixels::fromForm([
            'pixel_type_id' => ['5'],
            'pixel_code' => ["<script>\r\nvar a = 1;\r\rvar b = 2;\r\n</script>\r\n"],
            'pixel_id' => [''],
        ], $errors);
        self::assertSame("<script>\nvar a = 1;\n\nvar b = 2;\n</script>", $rows[0]['pixel_code']);
    }

    public function testACodeWithNoTypeIsRefusedNotDropped(): void
    {
        $errors = [];
        $rows = AccountPixels::fromForm([
            'pixel_type_id' => ['', '1'],
            'pixel_code' => ['<img src="x">', 'https://a.example/p.gif'],
            'pixel_id' => ['', ''],
        ], $errors);
        self::assertSame(['pixel_type_id'], array_keys($errors));
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['pixel_type_id']);
    }
}

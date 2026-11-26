<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\LandingPageSplitTest;
use UnexpectedValueException;

/**
 * A landing page's split test as Setup › Landing Pages posts it and as it is
 * stored (LandingPageSplitTest). LandingPageSplitTestIntegrationTest saves
 * and reads it on a real database and runs the served script;
 * split-test-harness.js (LandingSplitTestScriptTest) runs the script's block.
 */
final class LandingPageSplitTestTest extends TestCase
{
    public function testTheFormsTestIsRead(): void
    {
        $errors = [];
        $test = LandingPageSplitTest::fromForm([
            'split_status' => '1',
            'split_overwrite_c4' => 'on',
            'split_variants' => "  headline-a 30 \r\n\nheadline_b\t70\nv3.blue 0\n",
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['status' => 1, 'overwrite_c4' => 1, 'variants' => [
            ['name' => 'headline-a', 'weight' => 30],
            ['name' => 'headline_b', 'weight' => 70],
            ['name' => 'v3.blue', 'weight' => 0],
        ]], $test);
        self::assertSame("headline-a 30\nheadline_b 70\nv3.blue 0", LandingPageSplitTest::formText($test['variants']));
    }

    public function testAWeightLeftOutIsOneAndNothingPostedIsNoTest(): void
    {
        $errors = [];
        self::assertSame(
            ['status' => 1, 'overwrite_c4' => 0, 'variants' => [
                ['name' => 'a', 'weight' => 1],
                ['name' => 'b', 'weight' => 1],
            ]],
            LandingPageSplitTest::fromForm(['split_status' => '1', 'split_variants' => "a\nb"], $errors)
        );
        self::assertSame(LandingPageSplitTest::defaults(), LandingPageSplitTest::fromForm([], $errors));
        self::assertSame(
            LandingPageSplitTest::defaults(),
            LandingPageSplitTest::fromForm(
                ['split_status' => '0', 'split_overwrite_c4' => '0', 'split_variants' => ''],
                $errors
            )
        );
        self::assertSame([], $errors);
    }

    /** A test that is off keeps the list it can store, one variant or none. */
    public function testATestThatIsOffMayHoldAnyListItCanStore(): void
    {
        $errors = [];
        self::assertSame(
            ['status' => 0, 'overwrite_c4' => 0, 'variants' => [['name' => 'only', 'weight' => 0]]],
            LandingPageSplitTest::fromForm(['split_variants' => 'only 0'], $errors)
        );
        self::assertSame([], $errors);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function refused(): iterable
    {
        yield 'a space in a name' => [
            ['split_variants' => 'Head line 1'],
            'split_variants',
            'Line 1 (Head line 1) is not a name and a weight.',
        ];
        yield 'a name of other characters' => [
            ['split_variants' => "a\nb/c 2"],
            'split_variants',
            'Line 2 (b/c 2): a name is 1 to 50',
        ];
        yield 'a name starting with a dash' => [
            ['split_variants' => '-a'],
            'split_variants',
            'starting with a letter or digit',
        ];
        yield 'a name too long' => [['split_variants' => str_repeat('n', 51)], 'split_variants', 'a name is 1 to 50'];
        yield 'a weight that is not a whole number' => [
            ['split_variants' => 'a 1.5'],
            'split_variants',
            'a weight is a whole number from 0 to 1000',
        ];
        yield 'a weight above the most' => [
            ['split_variants' => 'a 1001'],
            'split_variants',
            'a weight is a whole number from 0 to 1000',
        ];
        yield 'a weight with a leading zero' => [
            ['split_variants' => 'a 01'],
            'split_variants',
            'a weight is a whole number',
        ];
        yield 'a negative weight' => [['split_variants' => 'a -1'], 'split_variants', 'a weight is a whole number'];
        yield 'names that differ only in case' => [
            ['split_variants' => "Blue\nblue"],
            'split_variants',
            'Line 2 (blue): Blue is listed already',
        ];
        yield 'too many variants' => [
            ['split_variants' => implode("\n", range(1, 11))],
            'split_variants',
            'at most 10 variants',
        ];
        yield 'one variant, on' => [
            ['split_status' => '1', 'split_variants' => 'a'],
            'split_variants',
            'needs at least two variants',
        ];
        yield 'no weight, on' => [
            ['split_status' => '1', 'split_variants' => "a 0\nb 0"],
            'split_variants',
            'a weight above 0',
        ];
        yield 'a list' => [['split_variants' => ['a']], 'split_variants', 'as text'];
        yield 'a switch neither on nor off' => [
            ['split_status' => 'yes', 'split_variants' => 'a'],
            'split_status',
            'run switch',
        ];
        yield 'the C4 switch neither on nor off' => [
            ['split_overwrite_c4' => ['1']],
            'split_overwrite_c4',
            'replace C4 switch',
        ];
    }

    /**
     * What cannot be stored as sent is refused, never cut or dropped.
     *
     * @dataProvider refused
     * @param array<string, mixed> $post
     */
    public function testWhatCannotBeStoredIsRefused(array $post, string $field, string $sentence): void
    {
        $errors = [];
        LandingPageSplitTest::fromForm($post, $errors);

        self::assertArrayHasKey($field, $errors, var_export($errors, true));
        self::assertStringContainsString($sentence, $errors[$field]);
    }

    public function testEveryRefusedLineIsNamed(): void
    {
        $errors = [];
        LandingPageSplitTest::fromForm(['split_variants' => "ok\nbad name here\nok 2000"], $errors);

        self::assertSame(
            'Line 2 (bad name here) is not a name and a weight.'
            . ' Line 3 (ok 2000): a weight is a whole number from 0 to 1000.',
            $errors['split_variants']
        );
    }

    public function testTheScriptGetsOnlyARunningTestsVariantsAVisitorCanBeShown(): void
    {
        $test = ['status' => 1, 'overwrite_c4' => 1, 'variants' => [
            ['name' => 'a', 'weight' => 0],
            ['name' => 'b', 'weight' => 5],
        ]];
        self::assertSame(
            ['variants' => [['name' => 'b', 'weight' => 5]], 'overwriteC4' => true],
            LandingPageSplitTest::forScript($test)
        );
        self::assertNull(LandingPageSplitTest::forScript(['status' => 0] + $test));
        self::assertNull(LandingPageSplitTest::forScript(['variants' => [['name' => 'a', 'weight' => 0]]] + $test));
        self::assertNull(LandingPageSplitTest::forScript(LandingPageSplitTest::defaults()));
    }

    public function testAStoredRowIsReadExactly(): void
    {
        self::assertSame(
            ['status' => 1, 'overwrite_c4' => 0, 'variants' => [['name' => 'a', 'weight' => 2]]],
            LandingPageSplitTest::fromRow(
                ['split_status' => 1, 'split_overwrite_c4' => '0', 'split_variants' => '[{"name":"a","weight":2}]']
            )
        );
        self::assertSame(
            LandingPageSplitTest::defaults(),
            LandingPageSplitTest::fromRow(['split_status' => '0', 'split_overwrite_c4' => 0, 'split_variants' => '[]'])
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unreadableRows(): iterable
    {
        $row = ['split_status' => 1, 'split_overwrite_c4' => 0, 'split_variants' => '[]'];
        yield 'a status of 2' => [['split_status' => 2] + $row, 'split_status'];
        yield 'no status' => [['split_overwrite_c4' => 0, 'split_variants' => '[]'], 'split_status'];
        yield 'an overwrite of NULL' => [['split_overwrite_c4' => null] + $row, 'split_overwrite_c4'];
        yield 'variants that are not JSON' => [['split_variants' => '[{'] + $row, 'not JSON'];
        yield 'variants NULL' => [['split_variants' => null] + $row, 'not text'];
        yield 'an object' => [['split_variants' => '{"a":1}'] + $row, 'not a list'];
        $eleven = array_map(static fn (int $i): array => ['name' => "v$i", 'weight' => 1], range(1, 11));
        yield 'too many' => [
            ['split_variants' => json_encode($eleven)] + $row,
            'not a list',
        ];
        yield 'a weight as text' => [['split_variants' => '[{"name":"a","weight":"1"}]'] + $row, 'entry 0'];
        yield 'a key more' => [['split_variants' => '[{"name":"a","weight":1,"x":1}]'] + $row, 'entry 0'];
        yield 'a bad name' => [['split_variants' => '[{"name":"a b","weight":1}]'] + $row, 'entry 0'];
        yield 'a weight too high' => [['split_variants' => '[{"name":"a","weight":1001}]'] + $row, 'entry 0'];
        yield 'names alike' => [
            ['split_variants' => '[{"name":"a","weight":1},{"name":"A","weight":1}]'] + $row,
            'entry 1',
        ];
    }

    /**
     * A row that does not read is an error naming its column, never "no test".
     *
     * @dataProvider unreadableRows
     * @param array<string, mixed> $row
     */
    public function testAStoredRowThatDoesNotReadIsRefused(array $row, string $said): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($said);
        LandingPageSplitTest::fromRow($row);
    }
}

<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\MiscTables;
use Prosper202\DataEngine\LedgerClicks;

/**
 * LedgerClicks stands in for 202_dataengine on the report pages, so it
 * carries every column of it: a column it left out is a page that fails only
 * once the account has spend ledger rows.
 */
final class LedgerClicksColumnsTest extends TestCase
{
    public function testItCarriesEveryColumnOf202DataengineWithCostFromTheLedger(): void
    {
        preg_match_all('/^\s*`([a-z0-9_]+)`\s/m', MiscTables::dataengine()->createStatement, $m);
        $columns = array_values(array_diff($m[1], ['cost']));
        self::assertContains('cost', $m[1]);
        self::assertSame($columns, LedgerClicks::COLUMNS);
    }

    public function testTheJoinIsInlinedOnlyWhenEveryPlaceholderHasItsBind(): void
    {
        $db = $this->createStub(\mysqli::class);
        $db->method('real_escape_string')->willReturnCallback(static fn (string $s): string => addslashes($s));

        self::assertSame("a = 7 AND b = 'x\\'y'", LedgerClicks::inline($db, 'a = ? AND b = ?', 'is', [7, "x'y"]));
        $refused = [['a = ?', 'ii', [1, 2]], ['a = ? AND b = ?', 'i', [1]], ["a = '?'", 's', ['x']]];
        foreach ($refused as [$sql, $types, $binds]) {
            try {
                LedgerClicks::inline($db, $sql, $types, $binds);
                self::fail("inlined $sql");
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

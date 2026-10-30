<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRetention;

/** The standalone operator tool must follow the application's click-table contract. */
final class AdaptiveDeletionScriptTest extends TestCase
{
    public function testStandaloneDeletionTableListMatchesTheApplication(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/scripts/p202-adaptive-delete.py');
        self::assertNotFalse($source);
        self::assertSame(1, preg_match('/CLICK_TABLES = \((.*?)\n\)/s', $source, $constant));
        preg_match_all('/"(202_[a-z_]+)"/', $constant[1], $tables);
        self::assertSame(ClickRetention::TABLES, $tables[1]);
    }
}

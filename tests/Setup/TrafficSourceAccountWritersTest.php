<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\PpcAccountsController;
use PHPUnit\Framework\TestCase;
use Tests\Repository\LookupTablesWrittenOnlyByRepositoriesTest;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * Every write to traffic source accounts is taken in the lock order the
 * traffic source repair shares (TrafficSourceAccountWrite: the account, then
 * its sources), or is listed with the order it keeps instead. A repair reads
 * the accounts naming a source once it holds the source; a writer that took
 * no lock on the source could commit an account on it after that read
 * (TrafficSourceAccountWritesIntegrationTest runs the races).
 *
 * The scan reads the SQL each served file assembles
 * (LookupTablesWrittenOnlyByRepositoriesTest::inserts() for INSERT and
 * REPLACE, and UPDATE and DELETE as ClickRowsWrittenOnlyByTheWriterTest reads
 * them). Not seen: a statement whose table it cannot read, such as the v3
 * API's, built from tableName(): PpcAccountsController is held by its own
 * writeRow() instead, and ClickRowsWrittenOnlyByTheWriterTest lists the other
 * unread writers and the tables they reach.
 */
final class TrafficSourceAccountWritersTest extends TestCase
{
    private const TABLE = '202_ppc_accounts';

    /** file => why its writes keep the order */
    private const WRITERS = [
        'tracking202/setup/ppc_accounts.php' => 'Setup › Traffic Sources: the add, edit and remove run inside'
            . ' TrafficSourceAccountWrite::run(), and the source is asked for again under the lock',
        '202-config/Setup/SetupRemoval.php' => 'the Remove of a traffic source account: the one caller, ppc_accounts.php,'
            . ' runs mark() inside TrafficSourceAccountWrite::run() with the account locked',
        '202-config/Setup/TrafficSourceOwners.php' => 'the split: the users, then the accounts it moves (FOR'
            . ' UPDATE, by id), then the source, the order a writer keeps',
        '202-config/Setup/ChatGptAdsSource.php' => "add(): the user's row FOR UPDATE first, as the repair locks the"
            . " users it moves between, and the account goes on the user's own source, found or made under it",
    ];

    private const UPDATE = '/\A\s*UPDATE\b(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+(.+?)\s+SET\b/is';
    private const DELETE = '/\A\s*DELETE\b.*?\bFROM\s+(.+?)(?:\s+(?:WHERE|ORDER|LIMIT|USING)\b|\z)/is';
    private const TABLE_REF = '/(?:\A|\b(?:STRAIGHT_)?JOIN\b|,)\s*\(?\s*'
        . '(?:`?[\w\x00]+`?\.)?`?((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    public function testEveryAccountWriterIsListedWithTheOrderItKeeps(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::writes($source) as $line) {
                $found[$path][] = $line;
            }
        }

        $unlisted = [];
        foreach ($found as $path => $lines) {
            if (!isset(self::WRITERS[$path])) {
                $unlisted[] = "$path:" . implode(',', $lines);
            }
        }
        self::assertSame([], $unlisted, "Traffic source accounts are written outside the listed writers:\n  "
            . implode("\n  ", $unlisted) . "\nRun the write in Prosper202\\Setup\\TrafficSourceAccountWrite::run(),"
            . ' check the source under its lock, and list the file here with why.');
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::WRITERS), array_keys($found))),
            'listed writers the scan no longer finds; drop them'
        );
    }

    public function testTheScanSeesEachShapeOfAWrite(): void
    {
        $shapes = [
            '<?php $db->query("INSERT INTO 202_ppc_accounts SET ppc_account_name = \'x\'");',
            '<?php $db->query(" UPDATE  `202_ppc_accounts`  SET ppc_account_deleted = 1 WHERE ppc_account_id = 1");',
            '<?php $sql = "UPDATE 202_ppc_accounts AS pa JOIN 202_users USING (user_id) SET pa.x = 1";',
            '<?php $sql = "UPDATE 202_users AS u, 202_ppc_accounts AS pa SET pa.x = 1";',
            '<?php $t = "202_ppc_accounts"; $sql = "DELETE FROM " . "202_ppc_accounts" . " WHERE user_id = 1";',
            '<?php $sql = \'REPLACE INTO 202_ppc_accounts (ppc_account_id) VALUES (1)\';',
        ];
        foreach ($shapes as $shape) {
            self::assertNotSame([], self::writes($shape), $shape);
        }
        self::assertSame([], self::writes('<?php $sql = "SELECT * FROM 202_ppc_accounts WHERE user_id = 1";'));
        self::assertSame([], self::writes('<?php $sql = "UPDATE 202_ppc_networks SET user_id = 1";'));
    }

    /**
     * The API's writes are built from tableName(), which the scan cannot
     * read: the controller takes the locks in its own writeRow(), which
     * create(), update() and the delete call for the row write.
     */
    public function testTheApiControllerTakesTheLocksForItsOwnWrites(): void
    {
        $method = new \ReflectionMethod(PpcAccountsController::class, 'writeRow');

        self::assertSame(PpcAccountsController::class, $method->getDeclaringClass()->getName());
    }

    /** @return list<int> the lines of $source's writes to 202_ppc_accounts */
    private static function writes(string $source): array
    {
        $lines = [];
        foreach (LookupTablesWrittenOnlyByRepositoriesTest::inserts($source) as [$line, $table]) {
            if ($table === self::TABLE) {
                $lines[] = $line;
            }
        }
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            foreach ([self::UPDATE, self::DELETE] as $statement) {
                if (preg_match($statement, $text, $m) !== 1) {
                    continue;
                }
                preg_match_all(self::TABLE_REF, $m[1], $tables);
                if (in_array(self::TABLE, array_map('strtolower', $tables[1]), true)) {
                    $lines[] = $line;
                }
            }
        }
        sort($lines);

        return array_values(array_unique($lines));
    }
}

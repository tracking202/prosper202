<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A read of one click's 202_clicks row says which visit it takes.
 *
 * A redirector re-click gives the click another 202_clicks row, at its own
 * visit's time, with that visit's campaign and payout, so a read of one
 * click with no order gets whichever row the database returns first.
 * LatestVisit holds the order that takes the latest, the visit a conversion
 * credits: of() is a derived table standing where the statement read
 * `202_clicks`, orderBy() the ORDER BY for a statement that joins
 * 202_clicks_rotator, isLatest() the condition for a statement that reads
 * many clicks' rows. FirstVisit::of() is the derived table of the first, the
 * visit whose traffic source and cost a click keeps. Every other read of one
 * click is listed in ANY_VISIT with why any visit's row may answer it; the
 * list only shrinks.
 *
 * Read per statement as PHP assembles it (AssembledStrings: literals,
 * interpolations, heredocs, class constants such as TableRegistry::CLICKS,
 * joined across `.`). A statement reads one click when it
 *
 * - reads the bare table `202_clicks` — after FROM, a JOIN or a comma, with
 *   or without backticks or a database; never the target of a DELETE, and
 *   never 202_clicks_advance or another click table — or a table the scan
 *   cannot read (a variable, `%s`) after FROM or a JOIN, whose read part
 *   could begin `202_clicks`;
 * - and asks for one click: a `click_id` or `click_id_public` column,
 *   qualified or not, compared by `=` or `<=>`, either way round, with a
 *   value (a placeholder, a literal, a number, a subquery or call, a sprintf
 *   conversion, a part the scan cannot read) rather than a column; a
 *   `click_id IN (...)` whose list is one value or is built at runtime (it
 *   may hold one id); or, where it reads the bare table, a `LIMIT 1`
 *   (`LIMIT 0, 1`, `LIMIT 1 OFFSET`) anywhere in the statement. A table
 *   it cannot read with only a LIMIT 1 is any table's one row, not seen.
 *
 * It names its visit when the alias of every bare 202_clicks it reads is
 * the first argument of a LatestVisit::orderBy() call among the statement's
 * own operands whose second argument is the alias of a 202_clicks_rotator in
 * the same statement, or the argument of a LatestVisit::isLatest() call
 * among them, and every table it cannot read is a LatestVisit::of() or
 * FirstVisit::of() call standing where that table is. A statement that reads
 * through of() reads one click by construction.
 *
 * What it does not read: a restriction appended in another statement
 * (`$sql .= ' AND c.click_id = ?'`) or handed to a builder. When this was
 * written nine SQL fragments carried one: ClickRollupSql::insertSelect()'s
 * re-derivation of one click (three callers; it writes the report row of the
 * click's latest visit and removes its earlier visits' rows), the reports' subid filter
 * (four), and two API list filters on other tables. Nor does it
 * read one row taken in PHP from a statement with no LIMIT 1 and no
 * click_id restriction (a fetchOne() of a join from another table's row),
 * `LIMIT ?`, a table read at runtime after a comma, or SQL not spelled in
 * PHP strings. A LatestVisit call held in a variable or inside parentheses
 * is not credited, so its statement is reported.
 * The predicate is wider than "one click" where it cannot tell (LIMIT 1 over
 * an aggregate, an id list built at runtime): those are listed with why,
 * the loud direction. Writes of 202_clicks are ClickRowsWrittenOnlyByTheWriterTest's.
 * Which visit a read should take is not read: a read of a source column
 * through LatestVisit, or of a campaign through FirstVisit, is credited.
 */
final class SingleClickReadsNameTheirVisitTest extends TestCase
{
    /** Each class whose calls name a visit => the visit its reads take. */
    private const VISITS = [
        'prosper202\click\latestvisit' => 'latest',
        'prosper202\click\firstvisit' => 'first',
    ];

    /**
     * file::function => [how many reads of one click it makes, the visit
     * they take]: the reads that define a visit, which no call can name.
     * The count is held exactly.
     *
     * @var array<string, array{int, string}>
     */
    private const DEFINES_A_VISIT = [
        '202-config/Click/FirstVisit.php::of' => [1, 'first'],
    ];

    /**
     * file::function ({main} outside any named function) => [how many reads
     * of one click it makes without LatestVisit, why any visit may answer].
     * The count is held exactly. Of a click's 202_clicks columns only
     * click_id and user_id agree across its visits: recordReClick() refuses
     * a click of another account, and nothing rewrites either; a re-click's
     * row carries its own campaign, payout and time (and, when written before
     * 1.9.79, its own traffic source and cost, and click_lead 0 until the
     * ledger next recomputes the click).
     *
     * @var array<string, array{int, string}>
     */
    private const ANY_VISIT = [
        // The ledger's click lock.
        '202-config/Conversion/Ledger/MysqlConversionLedger.php::lockClickRow' => [1, 'The lock: it selects click_id,'
            . ' which agrees across visits, from the click\'s first row in the click_id index (LIMIT 1 FOR UPDATE),'
            . ' the row every ledger writer locks; the visit is read through LatestVisit after it.'],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php::lockedVisitsSql' => [1, 'The click\'s two latest'
            . ' rows by time: lockClick() takes the first only when their seconds differ, and reads two visits in'
            . ' one second through LatestVisit::orderBy() (lockedVisitSql()), so the route row is locked only then.'],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php::lockedLeadSql' => [1, 'Any visit by design: whether'
            . ' any of the click\'s rows of the account is a lead, as LatestVisit::anyVisitIsLead() asks it, with'
            . ' share locks, so a lead committed while the lock was awaited is read.'],
        // Shown only.
        '202-account/administration.php::p202_admin_scheduled_deletion' => [1, 'Shown only: the day of the click'
            . ' an earlier version\'s scheduled deletion names by id (the newest at or below it, LIMIT 1). The'
            . ' deletion goes by id and reads no time; a later visit labels it with the day of a re-click.'],
        'api/v3/Controllers/AdministrationController.php::retentionState' => [1, 'As administration.php: the'
            . ' `before` day GET /system/retention shows for a deletion scheduled by click id.'],
        '202-cronjobs/index.php::ClearOldClicks' => [1, 'The day the Slack message names for a finished deletion'
            . ' scheduled by click id, from the marker\'s own click, which that deletion keeps.'],
        // Every visit's row.
        '202-config/Click/MysqlClickRepository.php::accountsClickFirstTime' => [1, 'Reads every visit\'s row'
            . ' (fetchAll): each must be the account\'s, and the earliest time is the click\'s first.'],
        '202-config/Click/MysqlClickRepository.php::repairVisits' => [1, 'Reads every visit\'s row (fetchAll):'
            . ' their accounts must agree, and their times name the rows the backfill rewrites.'],
        '202-config/Click/MysqlClickRepository.php::writeVisitRows' => [1, 'COUNT(*) of the click\'s rows in the'
            . ' second of the visit being written: whichever they are, they are the rows that visit rewrites.'],
        '202-config/Click/LatestVisit.php::refresh' => [1, 'MAX(click_time) over every visit\'s row: the latest'
            . ' visit\'s time is what it records.'],
        '202-config/Report/RollupDirty.php::summedHours' => [1, 'DISTINCT over every visit\'s row of the clicks an'
            . ' id list built at runtime names: each visit\'s hour is marked.'],
        '202-config/Report/RollupDirty.php::clickCost' => [1, 'DISTINCT over every visit\'s row (an INSERT … SELECT):'
            . ' each visit\'s hour is marked.'],
        '202-config/Report/RollupDirty.php::clickOfAnyAccount' => [1, 'DISTINCT user_id and click_id over every'
            . ' visit\'s row (an INSERT … SELECT); both agree across visits.'],
        '202-config/static-endpoint-helpers.php::p202ClickVisitedCampaign' => [1, 'pb.php\'s campaign check, with'
            . ' or without an event: whether any visit of the click, of its account, was on the campaign the'
            . ' postback names, where the sale was made before a re-click moved the visitor on. The conversion and'
            . ' the goals credit the latest visit, read through LatestVisit::of().'],
        '202-config/Conversion/RevenueUploadImporter.php::preview' => [1, 'Which ids are the account\'s clicks:'
            . ' click_id under user_id, both of which agree across visits, from an id list read at runtime.'],
        '202-config/Ltv/MysqlRecommendationRepository.php::engagedCampaignInterest' => [1, 'The LIMIT 1 takes the'
            . ' top campaign of an aggregate over each click\'s latest visit (LatestVisit::isLatest() on the'
            . ' derived table), not a visit.'],
        '202-config/Goals/WebEvents.php::clickForVisitor' => [1, 'Only when 202_click_latest_visits is missing'
            . ' (LatestVisit::tableMissing(), deployed before the 1.9.79 upgrade): no click has a record, so every'
            . ' visit is a latest visit, as isLatest() reads a click with none; the read beside it names the latest.'],
    ];

    /** How many reads ANY_VISIT holds. Lower it when one goes. */
    private const CEILING = 17;

    /**
     * A table reference: after FROM, a JOIN or a comma; its name, and its
     * alias when a word other than a keyword follows (a keyword taken for
     * an alias would swallow the FROM after a select list's comma).
     */
    private const TABLE_REF = '/(?<kw>\bFROM\b|JOIN\b|,)\s*\(*\s*(?:`?[\w\x00]+`?\.)?`?'
        . '(?<name>[\w\x00]+|%(?:\d+\$)?s)`?(?![\w\x00.`])'
        . '(?:\s+(?:AS\s+)?(?!' . self::KEYWORDS . '\b)`?(?<alias>\w+)`?)?/i';

    /** Words that follow a table where an alias would. */
    private const KEYWORDS = '(?:FROM|SELECT|WHERE|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|JOIN|ON|USING'
        . '|GROUP|ORDER|LIMIT|HAVING|UNION|FOR|LOCK|SET|FORCE|USE|IGNORE|PARTITION|WINDOW|AND|OR|INTO|PROCEDURE|AS)';

    private const COLUMN = '(?<![\w\x00.`])(?:`?[\w\x00]+`?\.)?`?click_id(?:_public)?`?(?![\w\x00])';

    /** What a click id is compared with when it names one click: anything but a column. */
    private const VALUE = '(?:\?|:[A-Za-z_]\w*|\d+(?![\w.])|\'[^\']*\'|"[^"]*"|\(|[A-Za-z_]\w*\s*\('
        . '|%(?:\d+\$)?[sdu]|\x00+(?![\w.]))';

    /** The same, read leftwards of the comparison. */
    private const VALUE_BEFORE = '(?:\?|:[A-Za-z_]\w*|(?<![\w.])\d+|\'[^\']*\'|"[^"]*"|%(?:\d+\$)?[sdu]'
        . '|(?<![\w.])\x00+)';

    /** One click asked for by its id. */
    private const BY_ID = '/' . self::COLUMN . '\s*(?:<=>|=)\s*' . self::VALUE
        . '|' . self::VALUE_BEFORE . '\s*(?:<=>|=)\s*' . self::COLUMN
        . '|' . self::COLUMN . '\s+IN\s*\(\s*(?:' . self::VALUE . '\s*\)|[^()]*\x00)/i';

    /** One row asked for. */
    private const ONE_ROW = '/\bLIMIT\s+(?:0\s*,\s*)?1\b(?!\s*,)/i';

    /**
     * Files that read one click through a visit's class, with the visit and
     * how many such reads each makes at least: the redirect path's and the
     * conversions', which the scan has to credit in each shape they use.
     *
     * @var array<string, array<string, int>>
     */
    private const THROUGH_A_VISIT = [
        '202-config/Click/ClickPathReads.php' => ['latest' => 1],
        '202-config/Click/LatestVisit.php' => ['latest' => 1],
        '202-config/connect2.php' => ['latest' => 2],
        'api/v3/Controllers/ClicksController.php' => ['latest' => 1],
        'tracking202/redirect/off.php' => ['latest' => 1],
        'tracking202/redirect/offrtr.php' => ['latest' => 2],
        'tracking202/redirect/pci.php' => ['latest' => 1],
        // of(), bound and inline, and orderBy() in a statement read by id.
        '202-config/static-endpoint-helpers.php' => ['latest' => 3, 'first' => 1],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => ['latest' => 2],
        '202-config/Conversion/TrafficSourcePixels.php' => ['first' => 1],
        // of() alone, with no click id in the statement around it.
        '202-config/Click/MysqlClickRepository.php' => ['first' => 1],
        '202-config/Click/LastClickFromAddress.php' => ['first' => 1],
        // orderBy() over a join from the install.
        '202-config/Goals/GoalEngine.php' => ['latest' => 3],
        // isLatest() over a list of clicks, and over a join with LIMIT 1.
        '202-config/Update/SubidBatch.php' => ['latest' => 1],
        '202-config/Goals/WebEvents.php' => ['latest' => 2],
    ];

    /** @var array<string, list<array{line: int, function: string, visit: string, sql: string}>>|null */
    private static ?array $tree = null;

    public function testEveryReadOfOneClickNamesItsVisitOrIsListed(): void
    {
        $unlisted = [];
        foreach (self::tree() as $path => $reads) {
            foreach ($reads as $read) {
                $site = $path . '::' . $read['function'];
                $listed = isset(self::ANY_VISIT[$site]) || isset(self::DEFINES_A_VISIT[$site]);
                if ($read['visit'] === 'any' && !$listed) {
                    $unlisted[] = "$path:{$read['line']} ({$read['function']}) {$read['sql']}";
                }
            }
        }

        self::assertSame([], $unlisted, "These read one click's 202_clicks row and take whichever visit the"
            . " database returns first:\n  " . implode("\n  ", $unlisted)
            . "\nRead the click's latest visit through Prosper202\\Click\\LatestVisit, in the statement itself"
            . ' (of() where it reads 202_clicks, orderBy() naming the 202_clicks alias and a 202_clicks_rotator'
            . ' joined on the click, or isLatest() naming the alias), its first through FirstVisit::of() (a traffic'
            . ' source or cost), or list the function in ANY_VISIT with why any visit may answer.');
    }

    public function testEachDefinitionOfAVisitMakesTheReadsItWeighs(): void
    {
        $found = array_fill_keys(array_keys(self::DEFINES_A_VISIT), 0);
        foreach (self::tree() as $path => $reads) {
            foreach ($reads as $read) {
                $site = $path . '::' . $read['function'];
                if (isset($found[$site])) {
                    $found[$site]++;
                }
            }
        }
        $expected = array_map(static fn (array $entry): int => $entry[0], self::DEFINES_A_VISIT);

        self::assertSame($expected, $found, 'A definition of a visit reads one click exactly as often as it is'
            . ' weighed: a second read there is not the definition.');
    }

    public function testEachListedFunctionMakesTheReadsItWeighs(): void
    {
        $miscounted = [];
        foreach (self::listedReads() as $site => $lines) {
            $weighed = self::ANY_VISIT[$site][0];
            if ($lines !== [] && count($lines) !== $weighed) {
                $miscounted[] = "$site: ANY_VISIT weighs $weighed, the scan finds " . count($lines)
                    . ' (lines ' . implode(', ', $lines) . ')';
            }
        }

        self::assertSame([], $miscounted, "A listed function reads one click a different number of times than its"
            . " entry weighs:\n  " . implode("\n  ", $miscounted)
            . "\nA read added beside the one weighed is not the one weighed: read it through LatestVisit, or"
            . ' weigh it and say why. One gone: lower the count and CEILING.');
    }

    public function testNoListedFunctionIsGone(): void
    {
        $stale = array_keys(array_filter(self::listedReads(), static fn (array $lines): bool => $lines === []));

        self::assertSame([], $stale, "Listed in ANY_VISIT, and the scan finds no read of one click there any"
            . " more:\n  " . implode("\n  ", $stale) . "\nDrop the entry and lower CEILING.");
    }

    public function testTheListOnlyShrinks(): void
    {
        $count = array_sum(array_map(static fn (array $entry): int => $entry[0], self::ANY_VISIT));

        self::assertLessThanOrEqual(self::CEILING, $count, 'a read of any visit was added; see the class docblock');
        self::assertSame(self::CEILING, $count, 'one went: lower CEILING to ' . $count);
    }

    /** The reads that do go through a visit's class are found, so a scan that reads nothing cannot pass. */
    public function testTheScanCreditsTheReadsThatGoThroughAVisit(): void
    {
        $missing = [];
        foreach (self::THROUGH_A_VISIT as $path => $visits) {
            foreach ($visits as $visit => $least) {
                $found = count(array_filter(
                    self::tree()[$path] ?? [],
                    static fn (array $read): bool => in_array($visit, explode(' ', $read['visit']), true)
                ));
                if ($found < $least) {
                    $missing[] = "$path: $found read(s) of the $visit visit, at least $least expected";
                }
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected "line function visit" for each read of one click: any, latest, first, or
     *        "latest first" for a statement that reads both
     */
    public function testTheScanReadsEachSpelling(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (array $read): string => $read['line'] . ' ' . $read['function'] . ' ' . $read['visit'],
            self::reads($source)
        ));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function spellings(): iterable
    {
        $any = static fn (string $code): array => ["<?php\n" . $code, ['2 {main} any']];
        $none = static fn (string $code): array => ["<?php\n" . $code, []];
        // A statement in single quotes, assigned.
        $q = static fn (string $sql): string => "\$s = '" . $sql . "';";

        yield 'a placeholder' => $any("\$db->prepare('SELECT aff_campaign_id FROM 202_clicks WHERE click_id = ?');");
        yield 'a select list first' => $any($q('SELECT click_id, click_time, aff_campaign_id FROM 202_clicks'
            . ' WHERE click_id = ?'));
        yield 'concatenated and quoted' => $any(
            "\$s = \"SELECT click_payout FROM 202_clicks WHERE click_id='\" . \$id . \"'\";"
        );
        yield 'interpolated' => $any("\$s = \"SELECT click_payout FROM 202_clicks WHERE click_id = {\$id}\";");
        yield 'a cast' => $any("\$s = 'SELECT click_payout FROM 202_clicks WHERE click_id = ' . (int) \$id;");
        yield 'backticks and a database' => $any($q('SELECT c.click_payout FROM `p202`.`202_clicks` AS c'
            . ' WHERE c.`click_id` = ?'));
        yield 'lower case, unspaced' => $any($q('select click_payout from 202_clicks where click_id=5'));
        yield 'the value first' => $any($q('SELECT click_payout FROM 202_clicks WHERE ? = click_id'));
        yield 'null-safe' => $any($q('SELECT click_payout FROM 202_clicks WHERE click_id <=> ?'));
        yield 'a named placeholder' => $any($q('SELECT click_payout FROM 202_clicks WHERE click_id = :id'));
        yield 'a subquery' => $any($q('SELECT click_time FROM 202_clicks'
            . ' WHERE click_id = (SELECT MAX(click_id) FROM 202_clicks_counter)'));
        yield 'a sprintf value' => $any(
            "\$s = sprintf('SELECT click_payout FROM 202_clicks WHERE click_id = %d', \$id);"
        );
        yield 'a list of one' => $any($q('SELECT click_payout FROM 202_clicks WHERE click_id IN (?)'));
        yield 'a list built at runtime' => $any(
            "\$s = 'SELECT click_lead FROM 202_clicks WHERE click_id IN (' . implode(',', \$ids) . ')';"
        );
        yield 'a public id through a join' => $any($q('SELECT c.aff_campaign_id FROM 202_clicks_record r'
            . ' JOIN 202_clicks c ON c.click_id = r.click_id WHERE r.click_id_public = ?'));
        yield 'a comma join' => $any($q('SELECT c.aff_campaign_id FROM 202_clicks_record r, 202_clicks c'
            . ' WHERE c.click_id = r.click_id AND r.click_id_public = ?'));
        yield 'one row of a join' => $any($q('SELECT c.aff_campaign_id FROM 202_app_installs i'
            . ' JOIN 202_clicks c ON c.click_id = i.click_id WHERE i.install_row_id = ? LIMIT 1'));
        yield 'LIMIT 0, 1' => $any($q('SELECT click_time FROM 202_clicks WHERE user_id = ?'
            . ' ORDER BY click_id DESC LIMIT 0, 1'));
        yield 'a table constant' => [
            "<?php\nuse Prosper202\\Database\\Schema\\TableRegistry;\n"
                . "\$s = 'SELECT click_payout FROM ' . TableRegistry::CLICKS . ' WHERE click_id = ?';",
            ['3 {main} any'],
        ];
        yield 'a table read at runtime' => $any("\$s = \"SELECT click_payout FROM {\$table} WHERE click_id = ?\";");
        yield 'a sprintf table' => $any("\$s = sprintf('SELECT click_payout FROM %s WHERE click_id = ?', \$t);");
        yield 'read by an insert' => $any($q('INSERT INTO 202_conversion_logs (campaign_id)'
            . ' SELECT aff_campaign_id FROM 202_clicks WHERE click_id = ?'));
        yield 'read by an update' => $any($q('UPDATE 202_conversion_logs l JOIN 202_clicks c'
            . ' ON c.click_id = l.click_id SET l.campaign_id = c.aff_campaign_id WHERE c.click_id = ?'));
        yield 'read by a delete' => $any($q('DELETE o FROM 202_identity_observations o JOIN 202_clicks c'
            . ' ON c.click_id = o.click_id WHERE c.click_id = ?'));
        yield 'read by a multi-table delete, first after FROM' => $any($q('DELETE de FROM 202_clicks AS c'
            . ' STRAIGHT_JOIN 202_dataengine AS de ON de.click_id = c.click_id WHERE c.click_id = ?'));
        yield 'a multi-table delete from 202_clicks' => $none($q('DELETE c FROM 202_clicks AS c'
            . ' JOIN 202_users AS u ON u.user_id = c.user_id WHERE c.click_id = ?'));
        yield 'a heredoc, at its first line' => [
            "<?php\n\$s = <<<SQL\n    SELECT click_payout\n    FROM 202_clicks\n"
                . "    WHERE click_id = {\$id}\n    SQL;\n",
            ['2 {main} any'],
        ];
        yield 'in a closure in a method' => [
            "<?php\nclass C\n{\n    public function m(): void\n    {\n        \$f = function () {\n"
                . "            return 'SELECT click_time FROM 202_clicks WHERE click_id = ?';\n"
                . "        };\n    }\n}\n",
            ['7 m any'],
        ];
        yield 'in a function returning a reference' => [
            "<?php\nfunction &f(): string\n{\n    \$s = 'SELECT click_time FROM 202_clicks WHERE click_id = ?';\n"
                . "    return \$s;\n}\n",
            ['4 f any'],
        ];

        // Shaped like LatestVisit, and not it.
        $use = "<?php\nuse Prosper202\\Click\\LatestVisit;\n";
        $joined = "'SELECT c.aff_campaign_id FROM 202_clicks c LEFT JOIN 202_clicks_rotator r"
            . " ON r.click_id = c.click_id WHERE c.click_id = ? '";
        yield 'ordered by another alias' => [
            $use . "\$s = $joined . LatestVisit::orderBy('x', 'r') . ' LIMIT 1';",
            ['3 {main} any'],
        ];
        yield 'ordered by a route joined to no click' => [
            $use . "\$s = 'SELECT c.x FROM 202_clicks c LEFT JOIN 202_clicks_rotator r ON r.click_id = 0"
                . " WHERE c.click_id = ? ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1';",
            ['3 {main} any'],
        ];
        yield 'ordered by a route joined to another click' => [
            $use . "\$s = 'SELECT c.x FROM 202_clicks c JOIN 202_clicks_record d ON d.click_id = c.click_id + 1"
                . " LEFT JOIN 202_clicks_rotator r ON r.click_id = d.click_id"
                . " WHERE c.click_id = ? ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1';",
            ['3 {main} any'],
        ];
        yield 'ordered by a route joined to an arithmetic of the click' => [
            $use . "\$s = 'SELECT c.x FROM 202_clicks c LEFT JOIN 202_clicks_rotator r ON r.click_id = 1 + c.click_id"
                . " WHERE c.click_id = ? ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1';",
            ['3 {main} any'],
        ];
        yield 'ordered by a route joined by an arithmetic of its own id' => [
            $use . "\$s = 'SELECT c.x FROM 202_clicks c LEFT JOIN 202_clicks_rotator r ON 1 + r.click_id = c.click_id"
                . " WHERE c.click_id = ? ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1';",
            ['3 {main} any'],
        ];
        yield 'ordered by a route not joined' => [
            $use . "\$s = 'SELECT c.x FROM 202_clicks c WHERE c.click_id = ? ' . LatestVisit::orderBy('c', 'r');",
            ['3 {main} any'],
        ];
        yield 'ordered by another class of that name' => [
            "<?php\n\$s = $joined . \\Other\\LatestVisit::orderBy('c', 'r');",
            ['2 {main} any'],
        ];
        yield 'an instance method of that name' => [
            "<?php\n\$s = $joined . \$visit->orderBy('c', 'r');",
            ['2 {main} any'],
        ];
        yield 'the order held in a variable' => [
            $use . "\$o = LatestVisit::orderBy('c', 'r');\n\$s = $joined . \$o;",
            ['4 {main} any'],
        ];
        yield 'the order in parentheses' => [
            $use . "\$s = $joined . (LatestVisit::orderBy('c', 'r'));",
            ['3 {main} any'],
        ];
        yield 'the order with an alias read at runtime' => [
            $use . "\$s = $joined . LatestVisit::orderBy(\$a, 'r');",
            ['3 {main} any'],
        ];
        yield 'the derived table held in a variable' => [
            $use . "\$lv = LatestVisit::of('?');\n\$s = 'SELECT c.x FROM ' . \$lv . ' AS c WHERE c.click_id = ?';",
            ['4 {main} any'],
        ];
        yield 'the derived table beside a bare read' => [
            $use . "\$s = 'SELECT d.x FROM ' . LatestVisit::of('?')"
                . " . ' AS c JOIN 202_clicks d ON d.click_id = c.click_id';",
            ['3 {main} any'],
        ];
        yield 'the derived table where no table is' => [
            $use . "\$s = \"SELECT x.a FROM {\$t} AS x WHERE x.click_id = ? AND x.b IN (\""
                . " . LatestVisit::of('?') . ')';",
            ['3 {main} any'],
        ];
        $first = "<?php\nuse Prosper202\\Click\\FirstVisit;\nuse Prosper202\\Click\\LatestVisit;\n";
        yield 'the first visit beside a bare read' => [
            $first . "\$s = 'SELECT d.x FROM ' . FirstVisit::of('?')"
                . " . ' AS f JOIN 202_clicks d ON d.click_id = f.click_id';",
            ['4 {main} any'],
        ];
        yield 'the first visit of another class of that name' => [
            "<?php\n\$s = 'SELECT c.ppc_account_id FROM ' . \\Other\\FirstVisit::of('?')"
                . " . ' AS c WHERE c.click_id = ?';",
            ['2 {main} any'],
        ];
        $list = "'SELECT c.click_lead FROM 202_clicks AS c WHERE c.click_id IN (' . implode(',', \$ids) . ') AND '";
        yield 'isLatest naming another alias' => [$use . "\$s = $list . LatestVisit::isLatest('x');", ['3 {main} any']];
        yield 'isLatest of another class of that name' => [
            "<?php\n\$s = $list . \\Other\\LatestVisit::isLatest('c');",
            ['2 {main} any'],
        ];
        yield 'isLatest under NOT' => [$use . "\$s = $list . 'NOT ' . LatestVisit::isLatest('c');", ['3 {main} any']];
        yield 'isLatest under !' => [$use . "\$s = $list . '!' . LatestVisit::isLatest('c');", ['3 {main} any']];
        yield 'isLatest or another condition' => [
            $use . "\$s = $list . LatestVisit::isLatest('c') . ' OR c.click_lead = 1';",
            ['3 {main} any'],
        ];
        yield 'isLatest in parentheses with an OR' => [
            $use . "\$s = $list . '(c.click_lead = 1 OR ' . LatestVisit::isLatest('c') . ')';",
            ['3 {main} any'],
        ];
        yield 'isLatest in an AND inside an OR' => [
            $use . "\$s = $list . '(c.click_lead = 1 OR (c.click_time > 0 AND ' . LatestVisit::isLatest('c') . '))';",
            ['3 {main} any'],
        ];
        yield 'isLatest beside a second, unordered read' => [
            $use . "\$s = 'SELECT c.click_lead FROM 202_clicks AS c JOIN 202_clicks AS d ON d.click_id = c.click_id"
                . " WHERE c.click_id = ? AND ' . LatestVisit::isLatest('c');",
            ['3 {main} any'],
        ];

        // Through LatestVisit.
        yield 'ordered' => [$use . "\$s = $joined . LatestVisit::orderBy('c', 'r') . ' LIMIT 1';", ['3 {main} latest']];
        yield 'ordered, fully qualified' => [
            "<?php\n\$s = 'SELECT 2c.x FROM `202_clicks` AS 2c LEFT JOIN 202_clicks_rotator AS cro"
                . " ON cro.click_id = 2c.click_id WHERE 2c.click_id = ? '"
                . " . \\Prosper202\\Click\\LatestVisit::orderBy('2c', 'cro');",
            ['2 {main} latest'],
        ];
        yield 'ordered, the route joined through the record' => [
            "<?php\n\$s = 'SELECT 2c.x FROM 202_clicks_record AS 2cr"
                . " LEFT JOIN 202_clicks AS 2c ON (2c.click_id = 2cr.click_id)"
                . " LEFT JOIN 202_clicks_rotator AS cro ON (cro.click_id = 2cr.click_id)"
                . " WHERE 2cr.click_id_public = ? '"
                . " . \\Prosper202\\Click\\LatestVisit::orderBy('2c', 'cro') . ' LIMIT 1';",
            ['2 {main} latest'],
        ];
        yield 'ordered, under another name' => [
            "<?php\nuse Prosper202\\Click\\LatestVisit as Visit;\n\$s = $joined . Visit::orderBy('c', 'r');",
            ['3 {main} latest'],
        ];
        yield 'ordered, in its namespace' => [
            "<?php\nnamespace Prosper202\\Click;\n\$s = $joined . LatestVisit::orderBy('c', 'r');",
            ['3 {main} latest'],
        ];
        yield 'the derived table, bound' => [
            $use . "\$s = 'SELECT c.user_id FROM ' . LatestVisit::of('?') . ' AS c';",
            ['3 {main} latest'],
        ];
        yield 'the derived table, the id in the statement' => [
            $use . "\$s = \"SELECT 2c.x FROM \" . LatestVisit::of(\$id)"
                . " . \" AS 2c WHERE 2c.click_id = '\" . \$id . \"'\";",
            ['3 {main} latest'],
        ];
        yield 'isLatest naming the alias' => [$use . "\$s = $list . LatestVisit::isLatest('c');", ['3 {main} latest']];
        yield 'isLatest in parentheses, ANDed' => [
            $use . "\$s = $list . '(c.click_time > 0 AND ' . LatestVisit::isLatest('c') . ') AND c.user_id = 1';",
            ['3 {main} latest'],
        ];
        yield 'isLatest over a join, one row' => [
            $use . "\$s = 'SELECT c.aff_campaign_id FROM 202_identity_observations AS o JOIN 202_clicks AS c"
                . " ON c.click_id = o.click_id WHERE o.signal_hash = ? AND ' . LatestVisit::isLatest('c')"
                . " . ' ORDER BY c.click_time DESC LIMIT 1';",
            ['3 {main} latest'],
        ];
        yield 'the first visit, bound' => [
            $first . "\$s = 'SELECT c.ppc_account_id FROM ' . FirstVisit::of('?') . ' AS c WHERE c.user_id = ?';",
            ['4 {main} first'],
        ];
        yield 'the first visit, nothing around it' => [
            $first . "\$s = 'SELECT fv.ppc_account_id, fv.click_cpc FROM ' . FirstVisit::of('?') . ' AS fv';",
            ['4 {main} first'],
        ];
        yield 'the first visit, fully qualified, the id in the statement' => [
            "<?php\n\$s = 'SELECT c.click_cpc FROM ' . \\Prosper202\\Click\\FirstVisit::of(\$id) . ' AS c';",
            ['2 {main} first'],
        ];
        yield 'the first visit beside the latest' => [
            $first . "\$s = 'SELECT c.aff_campaign_id, f.ppc_account_id FROM 202_clicks_tracking AS t'"
                . " . ' LEFT JOIN ' . LatestVisit::of(\$id) . ' AS c USING (click_id)'"
                . " . ' LEFT JOIN ' . FirstVisit::of(\$id) . ' AS f USING (click_id) WHERE c.click_id = ' . \$id;",
            ['4 {main} latest first'],
        ];
        yield 'self:: in LatestVisit' => [
            "<?php\nnamespace Prosper202\\Click;\nfinal class LatestVisit\n{\n"
                . "    public static function of(): string\n    {\n"
                . "        return '(SELECT lv.* FROM 202_clicks AS lv LEFT JOIN 202_clicks_rotator AS lvr"
                . " ON lvr.click_id = lv.click_id WHERE lv.click_id = ? ' . self::orderBy('lv', 'lvr') . ' LIMIT 1)';\n"
                . "    }\n}\n",
            ['7 of latest'],
        ];

        // Not a read of one click's 202_clicks row.
        yield 'many clicks' => $none($q('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ?'));
        yield 'another click table' => $none($q('SELECT keyword_id FROM 202_clicks_advance WHERE click_id = ?'));
        yield 'another click table, named at runtime' => $none(
            "\$s = \"SELECT x FROM 202_clicks_{\$t} WHERE click_id = ?\";"
        );
        yield 'another table, named at runtime' => $none(
            "\$s = \"SELECT c1 FROM 202_tracking_{\$n} WHERE click_id = ?\";"
        );
        yield 'a write' => $none($q('UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ?'));
        yield 'a delete' => $none($q('DELETE FROM 202_clicks WHERE click_id = ?'));
        yield 'joined on a column' => $none($q('SELECT c.x FROM 202_clicks c JOIN 202_clicks_tracking t'
            . ' ON t.click_id = c.click_id WHERE t.customer_id = ?'));
        yield 'a range' => $none($q('SELECT click_id FROM 202_clicks WHERE click_id BETWEEN ? AND ?'));
        yield 'a fixed list of two' => $none($q('SELECT click_id FROM 202_clicks WHERE click_id IN (?, ?)'));
        yield 'not equal' => $none($q('SELECT click_id FROM 202_clicks'
            . ' WHERE click_id != ? AND click_id <> ? AND click_id >= ?'));
        yield 'a longer column' => $none($q('SELECT x FROM 202_clicks WHERE first_click_id = ? AND click_id_hash = ?'));
        yield 'a qualifier, not a table' => $none($q('SELECT a, 202_clicks.click_id FROM t WHERE click_id = ?'));
        yield 'LIMIT 10' => $none($q('SELECT click_id FROM 202_clicks WHERE user_id = ? LIMIT 10'));
        yield 'LIMIT 1, 5' => $none($q('SELECT click_id FROM 202_clicks WHERE user_id = ? LIMIT 1, 5'));
        yield 'a row of a table named at runtime' => $none(
            "\$s = \"SELECT * FROM {\$table} WHERE {\$column} = ? LIMIT 1\";"
        );

        // Not read (the class docblock says so): each is a read of one click.
        yield 'not read: a restriction appended later' => $none(
            "\$s = 'SELECT x FROM 202_clicks c';\nif (\$a) {\n    \$s .= ' WHERE c.click_id = ?';\n}"
        );
        yield 'not read: LIMIT ?' => $none($q('SELECT x FROM 202_clicks WHERE user_id = ? LIMIT ?'));
        yield 'not read: a table named at runtime after a comma' => $none(
            "\$s = \"SELECT x FROM a, {\$t} WHERE click_id = ?\";"
        );
    }

    /** @return array<string, list<array{line: int, function: string, visit: string, sql: string}>> */
    private static function tree(): array
    {
        if (self::$tree === null) {
            self::$tree = [];
            foreach (SourceScan::phpFiles() as $path => $source) {
                $reads = self::reads($source);
                if ($reads !== []) {
                    self::$tree[$path] = $reads;
                }
            }
        }

        return self::$tree;
    }

    /** @return array<string, list<int>> each listed site => the lines of its reads of any visit */
    private static function listedReads(): array
    {
        $lines = array_fill_keys(array_keys(self::ANY_VISIT), []);
        foreach (self::tree() as $path => $reads) {
            foreach ($reads as $read) {
                $site = $path . '::' . $read['function'];
                if ($read['visit'] === 'any' && isset($lines[$site])) {
                    $lines[$site][] = $read['line'];
                }
            }
        }

        return $lines;
    }

    /**
     * Each statement in $source that reads one click's 202_clicks row: its
     * line, the function it is in, the visit it names (any, latest, first,
     * or "latest first"), and its text (white space folded, `…` for a part
     * the scan cannot read).
     *
     * @return list<array{line: int, function: string, visit: string, sql: string}>
     */
    public static function reads(string $source): array
    {
        $found = [];
        $functions = null;
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text, 'calls' => $calls]) {
            $verdict = self::verdict($text, $calls);
            if ($verdict === null) {
                continue;
            }
            $functions ??= self::functionRanges($source);
            $found[] = [
                'line' => $line,
                'function' => self::functionAt($functions, $line),
                'visit' => $verdict,
                'sql' => self::folded($text),
            ];
        }

        return $found;
    }

    /**
     * Null when $text does not read one click's 202_clicks row; otherwise
     * the visit it names: any, or the visits its reads take.
     *
     * @param list<array{class: string, method: string, args: list<string|null>, offset: int}> $calls
     */
    private static function verdict(string $text, array $calls): ?string
    {
        if (preg_match_all(self::TABLE_REF, $text, $refs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Reading a statement failed: ' . preg_last_error_msg());
        }
        $isDelete = preg_match('/\A\s*DELETE\b/i', $text) === 1;
        // A multi-table DELETE (`DELETE de FROM a JOIN b AS de`) removes from
        // the tables it names before FROM, read here by alias; a single-table
        // one (`DELETE FROM t`) from the table after FROM.
        $targets = [];
        if (
            $isDelete
            && preg_match('/\A\s*DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*(?!FROM\b)([\w`.,\s]+?)\s+FROM\b/i', $text, $m) === 1
        ) {
            foreach (explode(',', $m[1]) as $target) {
                $parts = explode('.', str_replace('`', '', trim($target)));
                $targets[] = strtolower((string) end($parts));
            }
        }
        $clicks = [];
        $routes = [];
        $unread = [];
        $firstFrom = true;
        foreach ($refs as $ref) {
            $keyword = strtolower($ref['kw'][0]);
            $name = $ref['name'][0];
            $alias = isset($ref['alias']) && $ref['alias'][1] >= 0 ? $ref['alias'][0] : $name;
            $deleted = $targets === []
                ? $isDelete && $keyword === 'from' && $firstFrom
                : in_array(strtolower($alias), $targets, true);
            if ($keyword === 'from') {
                $firstFrom = false;
            }
            if (strcasecmp($name, '202_clicks') === 0 && !$deleted) {
                $clicks[] = $alias;
            } elseif (strcasecmp($name, '202_clicks_rotator') === 0) {
                $routes[] = $alias;
            } elseif ($keyword !== ',' && !$deleted && strpbrk($name, AssembledStrings::UNREAD . '%') !== false) {
                $readPart = strtolower(substr($name, 0, strcspn($name, AssembledStrings::UNREAD . '%')));
                if (str_starts_with('202_clicks', $readPart)) {
                    $unread[] = $ref['name'][1] + strlen($readPart);
                }
            }
        }
        if ($clicks === [] && $unread === []) {
            return null;
        }

        $ordered = [];
        $derived = [];
        $visits = [];
        $sameClick = self::sameClick($text);
        foreach ($calls as $call) {
            $visit = self::VISITS[strtolower($call['class'])] ?? null;
            if ($visit === null) {
                continue;
            }
            $method = strtolower($call['method']);
            [$clickAlias, $routeAlias] = [$call['args'][0] ?? null, $call['args'][1] ?? null];
            if (
                $visit === 'latest'
                && $method === 'orderby'
                && in_array($routeAlias, $routes, true)
                && $clickAlias !== null
                && $routeAlias !== null
                && ($sameClick[$clickAlias] ?? $clickAlias) === ($sameClick[$routeAlias] ?? $routeAlias)
            ) {
                $ordered[] = $clickAlias;
                $visits[$visit] = true;
            } elseif (
                $visit === 'latest'
                && $method === 'islatest'
                && in_array($clickAlias, $clicks, true)
                && self::isConjunct($text, $call['offset'])
            ) {
                $ordered[] = $clickAlias;
                $visits[$visit] = true;
            } elseif ($method === 'of') {
                $derived[$call['offset']] = $visit;
            }
        }
        $byId = preg_match(self::BY_ID, $text) === 1;
        $reads = ($clicks !== [] && ($byId || preg_match(self::ONE_ROW, $text) === 1))
            || ($unread !== [] && $byId)
            || ($unread !== [] && array_diff($unread, array_keys($derived)) === []);
        if (!$reads) {
            return null;
        }
        if (array_diff($clicks, $ordered) !== [] || array_diff($unread, array_keys($derived)) !== []) {
            return 'any';
        }
        foreach ($unread as $offset) {
            $visits[$derived[$offset]] = true;
        }

        return implode(' ', array_keys(array_filter(
            ['latest' => isset($visits['latest']), 'first' => isset($visits['first'])]
        )));
    }

    /**
     * Whether the condition at $offset (the one character standing for a
     * call) is one of the conditions its statement ANDs: right after WHERE,
     * ON or AND, and no OR, XOR or || in any group around it, up to the
     * whole statement. Under NOT or beside an OR it does not narrow the rows
     * to the latest visit (`AND NOT isLatest()` takes every visit but it).
     * An OR anywhere in a group around it is refused, whatever it joins: the
     * loud direction.
     */
    private static function isConjunct(string $text, int $offset): bool
    {
        if (preg_match('/(?<![\w`])(?:AND|WHERE|ON)\s*\z/i', substr($text, 0, $offset)) !== 1) {
            return false;
        }
        $depths = [];
        $depth = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === ')') {
                $depth--;
            }
            $depths[$i] = $depth;
            if ($text[$i] === '(') {
                $depth++;
            }
        }
        for ($level = $depths[$offset]; $level >= 0; $level--) {
            [$from, $to] = [$offset, $offset];
            while ($from > 0 && $depths[$from - 1] >= $level) {
                $from--;
            }
            while ($to < $length - 1 && $depths[$to + 1] >= $level) {
                $to++;
            }
            $group = '';
            for ($i = $from; $i <= $to; $i++) {
                $group .= $depths[$i] === $level ? $text[$i] : ' ';
            }
            if (preg_match('/(?<![\w`])(?:OR|XOR)(?![\w`])|\|\|/i', $group) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The aliases a statement equates by click id (`a.click_id = b.click_id`,
     * in any clause, directly or through another alias), each mapped to one
     * alias standing for its group. A route is the click's only when it is
     * joined to it: joined on anything else, its order picks among visits of
     * no click.
     *
     * @return array<string, string>
     */
    private static function sameClick(string $text): array
    {
        // Each side whole: no arithmetic beside either column.
        $pattern = '/(?<![-+*\/%.\w`])(?<![-+*\/%]\s)`?(\w+)`?\.`?click_id`?\s*(?:<=>|=)\s*'
            . '`?(\w+)`?\.`?click_id`?(?![\w`])(?!\s*[-+*\/%.(])/i';
        if (preg_match_all($pattern, $text, $pairs, PREG_SET_ORDER) === false) {
            throw new \RuntimeException('Reading a statement failed: ' . preg_last_error_msg());
        }
        $group = [];
        foreach ($pairs as [, $a, $b]) {
            $ga = $group[$a] ?? $a;
            $gb = $group[$b] ?? $b;
            foreach ($group + [$a => $a, $b => $b] as $alias => $g) {
                $group[$alias] = $g === $gb ? $ga : $g;
            }
        }

        return $group;
    }

    /**
     * Every named function's line range, in the order they open.
     *
     * @return list<array{string, int, int}> name, first line, last line
     */
    private static function functionRanges(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable()
        ));
        $ranges = [];
        $open = [];
        $depth = 0;
        $pending = null;
        foreach ($tokens as $i => $token) {
            if ($token->is(T_FUNCTION)) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next->text === '&') {
                    $next = $tokens[$i + 2] ?? null;
                }
                $pending = [$next !== null && $next->is(T_STRING) ? $next->text : null, $token->line];
            } elseif ($token->text === ';' && $pending !== null) {
                $pending = null;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pending !== null && $token->text === '{') {
                    $open[] = [$pending[0], $pending[1], $depth];
                    $pending = null;
                }
            } elseif ($token->text === '}') {
                if ($open !== [] && end($open)[2] === $depth) {
                    [$name, $first] = array_pop($open);
                    if ($name !== null) {
                        $ranges[] = [$name, $first, $token->line];
                    }
                }
                $depth--;
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $ranges;
    }

    /**
     * The innermost named function around $line; a closure is read as the
     * function it is in, and code outside any as {main}.
     *
     * @param list<array{string, int, int}> $ranges
     */
    private static function functionAt(array $ranges, int $line): string
    {
        $name = '{main}';
        foreach ($ranges as [$function, $first, $last]) {
            if ($first <= $line && $line <= $last) {
                $name = $function;
            }
        }

        return $name;
    }

    private static function folded(string $sql): string
    {
        $folded = trim((string) preg_replace('/\s+/', ' ', str_replace(AssembledStrings::UNREAD, '…', $sql)));

        return strlen($folded) > 160 ? substr($folded, 0, 157) . '...' : $folded;
    }
}

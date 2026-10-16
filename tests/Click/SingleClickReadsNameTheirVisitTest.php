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
 * visit's time, with that visit's campaign, payout, cost and traffic source,
 * so a read of one click with no order gets whichever row the database
 * returns first. LatestVisit holds the order that takes the latest: of() is
 * a derived table standing where the statement read `202_clicks`, orderBy()
 * the ORDER BY for a statement that joins 202_clicks_rotator. Every other
 * read of one click is listed in ANY_VISIT with why any visit's row may
 * answer it; the list only shrinks.
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
 * It goes through LatestVisit when the alias of every bare 202_clicks it
 * reads is the first argument of a LatestVisit::orderBy() call among the
 * statement's own operands whose second argument is the alias of a
 * 202_clicks_rotator in the same statement, and every table it cannot read
 * is a LatestVisit::of() call standing where that table is. A statement
 * that reads through of() reads one click by construction.
 *
 * What it does not read: a restriction appended in another statement
 * (`$sql .= ' AND c.click_id = ?'`) or handed to a builder. When this was
 * written nine SQL fragments carried one: ClickRollupSql::insertSelect()'s
 * re-derivation of one click (three callers; it writes a report row per
 * visit, keyed by click_id and click_time), the reports' subid filter
 * (four), and two API list filters on other tables. Nor does it
 * read one row taken in PHP from a statement with no LIMIT 1 and no
 * click_id restriction (a fetchOne() of a join from another table's row),
 * `LIMIT ?`, a table read at runtime after a comma, or SQL not spelled in
 * PHP strings. A LatestVisit call held in a variable or inside parentheses
 * is not credited, so its statement is reported.
 * The predicate is wider than "one click" where it cannot tell (LIMIT 1 over
 * an aggregate, an id list built at runtime): those are listed with why,
 * the loud direction. Writes of 202_clicks are ClickRowsWrittenOnlyByTheWriterTest's.
 */
final class SingleClickReadsNameTheirVisitTest extends TestCase
{
    private const LATEST_VISIT = 'prosper202\click\latestvisit';

    /** What every conversion-side entry below shares. */
    private const UNDECIDED = 'Which visit a conversion credits is undecided, raised with the maintainer separately; ';

    /**
     * file::function ({main} outside any named function) => [how many reads
     * of one click it makes without LatestVisit, why any visit may answer].
     * The count is held exactly. Of a click's 202_clicks columns only
     * click_id and user_id agree across its visits: recordReClick() refuses
     * a click of another account, and nothing rewrites either; a re-click's
     * row carries its own campaign, payout, cost, traffic source and time,
     * and click_lead 0 until the ledger next recomputes the click.
     *
     * @var array<string, array{int, string}>
     */
    private const ANY_VISIT = [
        // Conversions, pixels and the ledger.
        'tracking202/static/gpb.php::{main}' => [2, self::UNDECIDED . 'the postback\'s traffic source (ppc_account_id:'
            . ' whose pixels fire), and the campaign, payout, CPC, lead flag and click time it records.'],
        'tracking202/static/gpx.php::{main}' => [2, self::UNDECIDED . 'the pixel\'s campaign, lead flag and click'
            . ' time, and the payout it records when the pixel names none.'],
        'tracking202/static/upx.php::{main}' => [2, self::UNDECIDED . 'as gpb.php: the campaign, payout, CPC, lead'
            . ' flag and click time, and the traffic source whose pixels fire.'],
        '202-config/static-endpoint-helpers.php::p202RecordLegacyConversion' => [1, self::UNDECIDED . 'a px/pb/cb202'
            . ' conversion\'s campaign (and its campaign_mismatch refusal), payout, lead flag and click time.'],
        '202-config/Conversion/MysqlConversionRepository.php::recordLocked' => [1, self::UNDECIDED . 'the'
            . ' conversion\'s campaign and with it the campaign\'s terms, the click payout, lead flag and time,'
            . ' read under the click\'s lock.'],
        '202-config/Conversion/MysqlConversionRepository.php::reviveGoalRowLocked' => [1, self::UNDECIDED
            . 'the campaign the click is recomputed under when a goal row is revived.'],
        '202-config/Conversion/MysqlConversionRepository.php::softDeleteLocked' => [1, self::UNDECIDED . 'the'
            . ' pre-ledger baseline carried in before the deletion (campaign, payout, time, lead flag) and the'
            . ' campaign the click is recomputed under.'],
        '202-config/Conversion/MysqlConversionRepository.php::clearClicks' => [1, self::UNDECIDED . 'as'
            . ' softDeleteLocked(), for each click a subid list or a category reset clears.'],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php::lockGoalRowsClick' => [1, self::UNDECIDED
            . 'the campaign the click is recomputed under when a goal row is superseded.'],
        '202-config/Conversion/Ledger/ClickBreakdown.php::forClick' => [1, self::UNDECIDED . 'shown, not written:'
            . ' the campaign whose terms explain the click\'s ledger, and its payout, lead flag and time; it has to'
            . ' show the visit the ledger credits.'],
        '202-config/Conversion/TrafficSourcePixels.php::clickTokens' => [1, self::UNDECIDED . 'which traffic'
            . ' source\'s pixels and postbacks announce a conversion (ppc_account_id), and the CPC token they carry.'],
        '202-config/Attribution/ConversionBackfill.php::run' => [1, self::UNDECIDED . 'the baseline row a pre-ledger'
            . ' lead is given: its campaign, payout, time and lead flag.'],
        '202-config/Update/SubidBatch.php::ownedClicks' => [1, self::UNDECIDED . 'a subid upload\'s preview of'
            . ' whether each click already converted (click_lead, an id list read at runtime); the mark itself is'
            . ' recordLocked()\'s.'],
        // Goals, identity and installs.
        '202-config/Goals/GoalEngine.php::clickSubject' => [1, self::UNDECIDED . 'a click subject\'s anchor time and'
            . ' the campaign whose goal set and payouts apply.'],
        '202-config/Goals/GoalEngine.php::installSubject' => [1, self::UNDECIDED . 'an install\'s click anchor time'
            . ' and the campaign whose payouts apply (one row of a join from the install, LIMIT 1).'],
        '202-config/Goals/GoalEngine.php::current' => [1, self::UNDECIDED . 'as installSubject(), re-read under the'
            . ' install\'s lock. Its comment says a click\'s time and campaign are never rewritten; a re-click adds'
            . ' a row with its own, and an exit rewrites the campaign of rows not yet a lead.'],
        '202-config/Goals/GoalEngine.php::clickCampaignGoals' => [1, self::UNDECIDED . 'the campaign whose goals an'
            . ' install evaluates beyond its registration\'s (one row of a join from the install, LIMIT 1).'],
        '202-config/Goals/WebEvents.php::click' => [1, self::UNDECIDED . 'an event\'s account, the campaign whose'
            . ' goals evaluate it, and the click time.'],
        '202-config/Goals/WebEvents.php::clickForVisitor' => [1, self::UNDECIDED . 'which of a visitor\'s clicks an'
            . ' event goes to, and its campaign: the newest matching row by click_time, which under the campaign'
            . ' filter can be an earlier visit of a click re-clicked elsewhere.'],
        '202-config/Identity/ClickIdentity.php::attachToStoredClick' => [1, self::UNDECIDED . 'whether a converting'
            . ' click is linked to its customer (its campaign\'s identity setting), and the time it is linked at.'],
        'api/v3/Apps/Android/InstallIntake.php::classifyWithClick' => [1, self::UNDECIDED . 'an install\'s'
            . ' attribution: the click\'s campaign and the app it links, its owner and time, under the click\'s'
            . ' lock.'],
        'api/v3/Controllers/AppInstallsController.php::installToken' => [1, self::UNDECIDED . 'whether the click\'s'
            . ' campaign links another app, refused before a token is minted; read as InstallIntake reads it.'],
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
        '202-config/Report/RollupDirty.php::summedHours' => [1, 'DISTINCT over every visit\'s row of the clicks an'
            . ' id list built at runtime names: each visit\'s hour is marked.'],
        '202-config/Report/RollupDirty.php::clickCost' => [1, 'DISTINCT over every visit\'s row (an INSERT … SELECT):'
            . ' each visit\'s hour is marked.'],
        '202-config/Report/RollupDirty.php::clickOfAnyAccount' => [1, 'DISTINCT user_id and click_id over every'
            . ' visit\'s row (an INSERT … SELECT); both agree across visits.'],
        '202-config/Conversion/RevenueUploadImporter.php::preview' => [1, 'Which ids are the account\'s clicks:'
            . ' click_id under user_id, both of which agree across visits, from an id list read at runtime.'],
        '202-config/Ltv/MysqlRecommendationRepository.php::engagedCampaignInterest' => [1, 'The LIMIT 1 takes the'
            . ' top campaign of an aggregate over every visit (COUNT and MAX(click_time) per campaign), not a'
            . ' visit.'],
    ];

    /** How many reads ANY_VISIT holds. Lower it when one goes. */
    private const CEILING = 34;

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
     * Files that read one click through LatestVisit, with how many such reads
     * each makes at least: the redirect path's, which the scan has to credit.
     */
    private const THROUGH_LATEST_VISIT = [
        '202-config/Click/ClickPathReads.php' => 1,
        '202-config/Click/LatestVisit.php' => 1,
        '202-config/connect2.php' => 2,
        'api/v3/Controllers/ClicksController.php' => 1,
        'tracking202/redirect/off.php' => 1,
        'tracking202/redirect/offrtr.php' => 2,
        'tracking202/redirect/pci.php' => 1,
    ];

    /** @var array<string, list<array{line: int, function: string, latest: bool, sql: string}>>|null */
    private static ?array $tree = null;

    public function testEveryReadOfOneClickNamesItsVisitOrIsListed(): void
    {
        $unlisted = [];
        foreach (self::tree() as $path => $reads) {
            foreach ($reads as $read) {
                if (!$read['latest'] && !isset(self::ANY_VISIT[$path . '::' . $read['function']])) {
                    $unlisted[] = "$path:{$read['line']} ({$read['function']}) {$read['sql']}";
                }
            }
        }

        self::assertSame([], $unlisted, "These read one click's 202_clicks row and take whichever visit the"
            . " database returns first:\n  " . implode("\n  ", $unlisted)
            . "\nRead the click's latest visit through Prosper202\\Click\\LatestVisit, in the statement itself"
            . ' (of() where it reads 202_clicks, or orderBy() naming the 202_clicks alias and a 202_clicks_rotator'
            . ' joined on the click), or list the function in ANY_VISIT with why any visit may answer.');
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

    /** The reads that do go through LatestVisit are found, so a scan that reads nothing cannot pass. */
    public function testTheScanCreditsTheReadsThatGoThroughLatestVisit(): void
    {
        $missing = [];
        foreach (self::THROUGH_LATEST_VISIT as $path => $least) {
            $found = count(array_filter(self::tree()[$path] ?? [], static fn (array $read): bool => $read['latest']));
            if ($found < $least) {
                $missing[] = "$path: $found read(s) through LatestVisit, at least $least expected";
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected "line function any|latest" for each read of one click
     */
    public function testTheScanReadsEachSpelling(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (array $read): string => $read['line'] . ' ' . $read['function'] . ' '
                . ($read['latest'] ? 'latest' : 'any'),
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

    /** @return array<string, list<array{line: int, function: string, latest: bool, sql: string}>> */
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
                if (!$read['latest'] && isset($lines[$site])) {
                    $lines[$site][] = $read['line'];
                }
            }
        }

        return $lines;
    }

    /**
     * Each statement in $source that reads one click's 202_clicks row: its
     * line, the function it is in, whether it reads the latest visit, and
     * its text (white space folded, `…` for a part the scan cannot read).
     *
     * @return list<array{line: int, function: string, latest: bool, sql: string}>
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
                'latest' => $verdict,
                'sql' => self::folded($text),
            ];
        }

        return $found;
    }

    /**
     * Null when $text does not read one click's 202_clicks row; otherwise
     * whether it reads the latest visit.
     *
     * @param list<array{class: string, method: string, args: list<string|null>, offset: int}> $calls
     */
    private static function verdict(string $text, array $calls): ?bool
    {
        if (preg_match_all(self::TABLE_REF, $text, $refs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Reading a statement failed: ' . preg_last_error_msg());
        }
        $isDelete = preg_match('/\A\s*DELETE\b/i', $text) === 1;
        $clicks = [];
        $routes = [];
        $unread = [];
        $firstFrom = true;
        foreach ($refs as $ref) {
            $keyword = strtolower($ref['kw'][0]);
            $name = $ref['name'][0];
            $alias = isset($ref['alias']) && $ref['alias'][1] >= 0 ? $ref['alias'][0] : $name;
            $deleted = $isDelete && $keyword === 'from' && $firstFrom;
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
        $sameClick = self::sameClick($text);
        foreach ($calls as $call) {
            if (strtolower($call['class']) !== self::LATEST_VISIT) {
                continue;
            }
            $method = strtolower($call['method']);
            [$clickAlias, $routeAlias] = [$call['args'][0] ?? null, $call['args'][1] ?? null];
            if (
                $method === 'orderby'
                && in_array($routeAlias, $routes, true)
                && $clickAlias !== null
                && $routeAlias !== null
                && ($sameClick[$clickAlias] ?? $clickAlias) === ($sameClick[$routeAlias] ?? $routeAlias)
            ) {
                $ordered[] = $clickAlias;
            } elseif ($method === 'of') {
                $derived[] = $call['offset'];
            }
        }
        $byId = preg_match(self::BY_ID, $text) === 1;
        $reads = ($clicks !== [] && ($byId || preg_match(self::ONE_ROW, $text) === 1))
            || ($unread !== [] && $byId)
            || ($unread !== [] && array_diff($unread, $derived) === []);
        if (!$reads) {
            return null;
        }

        return array_diff($clicks, $ordered) === [] && array_diff($unread, $derived) === [];
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

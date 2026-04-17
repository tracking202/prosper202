<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;

/**
 * Tests for MysqlClickRepository — the atomic 9-table click write.
 *
 * MysqlClickRepository::recordClick() uses Connection (final) which calls
 * mysqli_stmt_execute() (procedural, C-backed). This bypasses fake stmt
 * overrides in PHP 8.4, so we verify the code structure and SQL correctness
 * through source analysis rather than execution.
 *
 * The InMemoryClickRepository tests (ClickRepositoryTest.php) verify the
 * interface contract. These tests verify the MySQL implementation specifics.
 */
final class MysqlClickRepositoryTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(__DIR__ . '/../../202-config/Click/MysqlClickRepository.php');
        self::assertNotFalse($this->source);
    }

    // --- Transaction safety ---

    public function testRecordClickUsesTransaction(): void
    {
        self::assertStringContainsString(
            '$this->conn->transaction(',
            $this->source,
            'recordClick must wrap all writes in a transaction'
        );
    }

    // --- All 9 tables written ---

    public function testRecordClickWritesToAllNineTables(): void
    {
        $expectedTables = [
            '202_clicks_counter' => 'click ID allocation',
            '202_clicks SET' => 'core click data',
            '202_clicks_variable' => 'variable set associations',
            '202_google' => 'UTM and gclid data',
            '202_clicks_spy' => 'denormalized reporting copy',
            '202_clicks_advance' => 'geo/device/keyword data',
            '202_clicks_tracking' => 'C1-C4 tracking params',
            '202_clicks_record' => 'public ID and cloaking state',
            '202_clicks_site' => 'URL references',
        ];

        foreach ($expectedTables as $table => $description) {
            self::assertStringContainsString(
                $table,
                $this->source,
                "Must write to $table ($description)"
            );
        }
    }

    // --- Bind param type string correctness ---

    public function testCoreClicksBindParamTypes(): void
    {
        // 202_clicks: clickId(i), userId(i), affCampaignId(i), landingPageId(i),
        //             ppcAccountId(i), clickCpc(s), clickPayout(s),
        //             clickFiltered(i), clickBot(i), clickAlp(i), clickTime(i)
        self::assertStringContainsString(
            "'iiiiissiiii'",
            $this->source,
            '202_clicks bind types must be iiiiissiiii (11 params)'
        );
    }

    public function testGoogleTableBindParamTypes(): void
    {
        // 202_google: clickId(i), gclid(s), utm_source_id(i), utm_medium_id(i),
        //             utm_campaign_id(i), utm_term_id(i), utm_content_id(i)
        self::assertStringContainsString(
            "'isiiiii'",
            $this->source,
            '202_google bind types must be isiiiii (7 params)'
        );
    }

    public function testAdvanceTableBindParamTypes(): void
    {
        // 202_clicks_advance: clickId(i), textAdId(i), keywordId(i), ipId(i),
        //                     countryId(i), regionId(i), ispId(i), cityId(i),
        //                     platformId(i), browserId(i), deviceId(i)
        self::assertStringContainsString(
            "'iiiiiiiiiii'",
            $this->source,
            '202_clicks_advance bind types must be iiiiiiiiiii (11 params)'
        );
    }

    public function testTrackingTableBindParamTypes(): void
    {
        // 202_clicks_tracking: clickId(i), c1Id(i), c2Id(i), c3Id(i), c4Id(i)
        // Count i's: should be 5
        preg_match_all("/202_clicks_tracking.*?bind\(\\\$stmt,\s*'(i+)'/s", $this->source, $matches);
        self::assertNotEmpty($matches[1], 'Must find bind types for 202_clicks_tracking');
        self::assertSame(5, strlen($matches[1][0]), '202_clicks_tracking must bind 5 integers');
    }

    public function testRecordTableBindParamTypes(): void
    {
        // 202_clicks_record: clickId(i), clickIdPublic(s), clickCloaking(i),
        //                    clickIn(i), clickOut(i)
        self::assertStringContainsString(
            "'isiii'",
            $this->source,
            '202_clicks_record bind types must be isiii (5 params)'
        );
    }

    public function testSiteTableBindParamTypes(): void
    {
        // 202_clicks_site: clickId(i), referer(i), landing(i), outbound(i),
        //                  cloaking(i), redirect(i)
        // Should be 6 i's
        preg_match_all("/202_clicks_site.*?bind\(\\\$stmt,\s*'(i+)'/s", $this->source, $matches);
        self::assertNotEmpty($matches[1], 'Must find bind types for 202_clicks_site');
        self::assertSame(6, strlen($matches[1][0]), '202_clicks_site must bind 6 integers');
    }

    public function testVariableTableBindParamTypes(): void
    {
        // 202_clicks_variable: clickId(i), variableSetId(i)
        preg_match_all("/202_clicks_variable.*?bind\(\\\$stmt,\s*'(i+)'/s", $this->source, $matches);
        self::assertNotEmpty($matches[1], 'Must find bind types for 202_clicks_variable');
        self::assertSame(2, strlen($matches[1][0]), '202_clicks_variable must bind 2 integers');
    }

    // --- Spy table consistency ---

    public function testSpyTableUsesIdenticalInsertStructureAsClicksTable(): void
    {
        // Extract the column list between SET and the closing quote for each INSERT
        preg_match("/INSERT INTO 202_clicks SET\s+(.+?)'/s", $this->source, $clicksMatch);
        preg_match("/INSERT INTO 202_clicks_spy SET\s+(.+?)'/s", $this->source, $spyMatch);

        self::assertNotEmpty($clicksMatch, 'Must find 202_clicks INSERT');
        self::assertNotEmpty($spyMatch, 'Must find 202_clicks_spy INSERT');

        // Normalize whitespace and compare column lists
        $clicksCols = preg_replace('/\s+/', ' ', trim($clicksMatch[1]));
        $spyCols = preg_replace('/\s+/', ' ', trim($spyMatch[1]));

        self::assertSame($clicksCols, $spyCols, '202_clicks and 202_clicks_spy must have identical column lists');
    }

    // --- Pre-allocated ID handling ---

    public function testPreAllocatedIdSkipsCounterInsert(): void
    {
        self::assertStringContainsString(
            '$click->clickId > 0',
            $this->source,
            'Must check for pre-allocated clickId before counter INSERT'
        );
    }

    // --- Every execute is followed by close ---

    public function testEveryExecuteIsFollowedByClose(): void
    {
        // Count execute() calls and close() calls — they should be balanced
        $executeCount = substr_count($this->source, '$this->conn->execute($stmt)');
        $closeCount = substr_count($this->source, '$stmt->close()');
        $executeInsertCount = substr_count($this->source, '$this->conn->executeInsert($stmt)');

        // executeInsert internally closes, so only execute() calls need explicit close
        self::assertSame(
            $executeCount,
            $closeCount,
            "Every execute() must have a corresponding close(). Found $executeCount execute() and $closeCount close()"
        );
    }

    // --- ClickRecord default values ---

    public function testClickRecordDefaultsAreZeroOrEmpty(): void
    {
        $click = new ClickRecord();

        // Verify critical defaults that would cause SQL issues if wrong
        self::assertSame(0, $click->clickId, 'Default clickId must be 0 (auto-generate)');
        self::assertSame(0, $click->userId);
        self::assertSame(0, $click->affCampaignId);
        self::assertSame('0', $click->clickCpc, 'clickCpc must default to string "0"');
        self::assertSame('0', $click->clickPayout, 'clickPayout must default to string "0"');
        self::assertSame(0, $click->clickFiltered);
        self::assertSame(0, $click->clickBot);
        self::assertSame('', $click->gclid);
        self::assertSame('', $click->clickIdPublic);
        self::assertSame(1, $click->clickIn, 'clickIn must default to 1');
        self::assertSame(0, $click->clickOut, 'clickOut must default to 0');
    }
}

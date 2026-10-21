<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

/**
 * rtr.php's two re-click steps, taken from the file and run here: the
 * c1-c4 a re-click does not bring go to recordReClick() as 0 (the click
 * keeps its own), and the Landing Page Optimizer token's claims describe the
 * click as stored — its first visit's traffic source, its keyword, the c1-c4
 * it kept — read back from it. The token block fails open, so a statement
 * that cannot run or reads the wrong column would only ever mint no token,
 * or a wrong one, with nothing said. RtrClickInstanceTest drives rtr.php
 * over HTTP; this runs where no instance is.
 *
 * @group integration
 */
final class RtrReClickValuesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990305;
    private const CLICK = 99030501;
    private const RTR = __DIR__ . '/../../tracking202/redirect/rtr.php';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        foreach (
            [
                '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
                '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
    }

    /**
     * The source of the block in rtr.php that begins at the first statement
     * whose tokens start with $opening, through the `}` closing the first
     * `if ($reClick !== null) {` from there.
     *
     * @param list<string> $opening token texts, whitespace and comments left out
     */
    private static function block(array $opening): string
    {
        $tokens = \PhpToken::tokenize((string) file_get_contents(self::RTR));
        $code = array_values(array_filter($tokens, static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $texts = array_map(static fn (\PhpToken $t): string => $t->text, $code);
        $count = count($code);
        for ($i = 0; $i < $count; $i++) {
            if (array_slice($texts, $i, count($opening)) !== $opening) {
                continue;
            }
            $guard = ['if', '(', '$reClick', '!==', 'null', ')', '{'];
            for ($j = $i; $j < $count && array_slice($texts, $j, count($guard)) !== $guard; $j++) {
            }
            self::assertLessThan($count, $j, 'rtr.php: no if ($reClick !== null) { after ' . implode(' ', $opening));
            $depth = 0;
            for ($k = $j + count($guard) - 1; $k < $count; $k++) {
                $depth += match ($texts[$k]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    $start = $code[$i]->pos;

                    return substr(
                        (string) file_get_contents(self::RTR),
                        $start,
                        $code[$k]->pos + 1 - $start
                    );
                }
            }
        }
        self::fail('rtr.php has no statement beginning ' . implode(' ', $opening));
    }

    /** The first visit, through source 3, with a keyword and c1-c4 of its own; the re-click brings c2 alone. */
    private static function reClicked(MysqlTrackingRepository $tracking): void
    {
        $repo = new MysqlClickRepository(new Connection(self::requireScratch()));
        $repo->recordClick(
            new ClickRecord(
                clickId: self::CLICK,
                userId: self::USER,
                clickTime: time() - 600,
                ppcAccountId: 3,
                clickCpc: '0.25',
                keywordId: $tracking->findOrCreateKeyword('rtr-kw-first'),
                ipId: 13,
                c1Id: $tracking->findOrCreateC1('rtr-c1-first'),
                c2Id: $tracking->findOrCreateC2('rtr-c2-first'),
                c3Id: $tracking->findOrCreateC3('rtr-c3-first'),
                c4Id: $tracking->findOrCreateC4('rtr-c4-first'),
                recordsCampaignRows: false,
                rotator: new RotatorRoute(7, 8, 9),
            ),
            static fn (): int => 51
        );
        $repo->recordReClick(
            new ClickRecord(
                clickId: self::CLICK,
                userId: self::USER,
                clickTime: time(),
                ppcAccountId: 13,
                clickCpc: '0.75',
                ipId: 13,
                c2Id: $tracking->findOrCreateC2('rtr-c2-second'),
                recordsCampaignRows: false,
                rotator: new RotatorRoute(17, 18, 19),
            ),
            static fn (): int => 52
        );
    }

    /**
     * rtr.php's re-click branch, given the visit's c1-c4 and their ids as
     * the request found them: the ids the click record is built with.
     *
     * @return array<string, string>
     */
    private static function reClickIds(string $c1, string $c2, string $c3, string $c4): array
    {
        $code = self::block(['$reClick', '=', 'null', ';']);
        $branch = substr($code, (int) strpos($code, 'if ($reClick !== null) {'));
        $run = static function (string $c1, string $c2, string $c3, string $c4) use ($branch): array {
            $reClick = ['click_id' => self::CLICK, 'keyword_id' => 5, 'ppc_account_id' => 3, 'click_id_public' => '0'];
            $mysql = ['c1_id' => '41', 'c2_id' => '42', 'c3_id' => '43', 'c4_id' => '44', 'keyword_id' => '6'];
            eval($branch);

            return array_intersect_key($mysql, array_flip(['c1_id', 'c2_id', 'c3_id', 'c4_id', 'keyword_id']));
        };

        return $run($c1, $c2, $c3, $c4);
    }

    public function testAReClickPassesTheC1ToC4ItDoesNotBringAsZero(): void
    {
        self::requireScratch();

        self::assertSame(
            ['c1_id' => '0', 'c2_id' => '42', 'c3_id' => '0', 'c4_id' => '0', 'keyword_id' => '5'],
            self::reClickIds('', 'brought', '', '')
        );
        self::assertSame(
            ['c1_id' => '41', 'c2_id' => '42', 'c3_id' => '43', 'c4_id' => '44', 'keyword_id' => '5'],
            self::reClickIds('a', 'b', 'c', 'd'),
            'a re-click bringing all four passes their ids'
        );
    }

    /**
     * rtr.php's claims block, with the visit's values as rtr.php holds them
     * (the link's source 13, the request's keyword and c1-c4).
     *
     * @param array<string, int|string>|null $reClick
     * @return array<string, int|string>
     */
    private static function claims(?array $reClick, int $userId): array
    {
        $code = self::block(['$t202ctx_click', '=', '[']);
        self::assertStringContainsString('LatestVisit::of(', $code);
        $run = static function (\mysqli $db, ?array $reClick, int $click_id, string $user_id) use ($code): array {
            $ppc_account = '13';
            $keyword = 'rtr-kw-request';
            [$c1, $c2, $c3, $c4] = ['', 'rtr-c2-second', '', ''];
            eval($code);

            return $t202ctx_click;
        };

        return $run(self::requireScratch(), $reClick, self::CLICK, (string) $userId);
    }

    public function testAReClicksClaimsAreTheClickAsStored(): void
    {
        self::reClicked(new MysqlTrackingRepository(new Connection(self::requireScratch())));
        $reClick = ['click_id' => self::CLICK, 'keyword_id' => 0, 'ppc_account_id' => 3, 'click_id_public' => '0'];

        self::assertSame(
            [
                'acc' => 3, 'kw' => 'rtr-kw-first',
                'c1' => 'rtr-c1-first', 'c2' => 'rtr-c2-second', 'c3' => 'rtr-c3-first', 'c4' => 'rtr-c4-first',
            ],
            self::claims($reClick, self::USER),
            'the first visit\'s source, the click\'s keyword, the c2 brought and the c1, c3 and c4 kept'
        );
        self::assertSame(
            ['acc' => 13, 'kw' => 'rtr-kw-request', 'c1' => '', 'c2' => 'rtr-c2-second', 'c3' => '', 'c4' => ''],
            self::claims(null, self::USER),
            'a new click\'s claims are the values rtr.php holds, read from nowhere'
        );

        try {
            self::claims($reClick, self::USER + 1);
            self::fail('a click that is not the account\'s has no claims to read');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('has no row', $e->getMessage());
        }
    }
}

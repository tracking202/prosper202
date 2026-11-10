<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\TrafficSourceOwners;

/**
 * Wherever the list of traffic sources in another user's account is shown,
 * it says that custom variable values are not being recorded on the other
 * users' accounts; and every click script that stores a source's variables
 * says in the log when a click loses them. What the page and the API answer
 * on a database is TrafficSourceOwnersSettingsPanelIntegrationTest's, and
 * what a click logs UnrecordedSourceVariablesServedIntegrationTest's.
 */
final class TrafficSourceVariablesNoteTest extends TestCase
{
    private static function file(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source, $path);

        return $source;
    }

    public function testTheNoticeSaysItOnlyWhenTheReportDoes(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-traffic-source-owners-ui.php';
        $report = ['sources' => [['ppc_network_id' => 7]], 'repairable' => 1, 'ambiguous' => 0];
        $said = htmlspecialchars(TrafficSourceOwners::VARIABLES_NOTE, ENT_QUOTES, 'UTF-8');

        $with = \p202_ts_owners_notice($report + ['variables_note' => TrafficSourceOwners::VARIABLES_NOTE], 'x.php');
        self::assertStringContainsString('<strong>' . $said . '</strong>', $with);
        foreach ([[], ['variables_note' => null], ['variables_note' => '']] as $none) {
            $without = \p202_ts_owners_notice($report + $none, 'x.php');
            self::assertStringContainsString('id="traffic-source-owners-notice"', $without);
            self::assertStringNotContainsString('not being recorded', $without);
        }
    }

    /** The cron job's hourly tier is where the automatic split runs, and nowhere on a click's path. */
    public function testTheAutomaticSplitRunsInTheHourlyCronTierOnly(): void
    {
        $cron = self::file('202-cronjobs/index.php');
        $hourly = substr($cron, (int) strpos($cron, 'function RunHourlyCronJob()'), 6000);
        self::assertSame(1, substr_count($hourly, 'SplitTrafficSourcesOfOtherUsers();'));
        self::assertSame(1, substr_count($cron, '->splitAutomatically()'));
        $callers = [];
        foreach (\Tests\Support\SourceScan::phpFiles() as $path => $source) {
            if (str_contains($source, 'splitAutomatically(')) {
                $callers[] = strtolower($path);
            }
        }
        sort($callers);
        self::assertSame(['202-config/setup/trafficsourceowners.php', '202-cronjobs/index.php'], $callers);
    }

    /** A source the cron job splits by itself is not counted as waiting for the operator. */
    public function testTheNoticeCountsOnlyWhatWaitsForTheOperator(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-traffic-source-owners-ui.php';
        $source = static fn (bool $auto, ?int $to): array => ['auto_split' => $auto, 'repair_to_user_id' => $to];
        $report = static fn (array $sources): array => ['sources' => $sources, 'repairable' => 9, 'ambiguous' => 9,
            'splits' => []];

        $three = [$source(true, null), $source(false, 4), $source(false, null)];
        $mixed = strip_tags(\p202_ts_owners_notice($report($three), 'x.php'));
        self::assertStringContainsString('3 traffic sources are in one user', $mixed);
        self::assertStringContainsString('1 can be moved back as it is; 1 needs you to choose the user.', $mixed);
        self::assertStringContainsString('1 of them is split by the cron job within the hour, with nothing', $mixed);

        $all = strip_tags(\p202_ts_owners_notice($report([$source(true, null)]), 'x.php'));
        self::assertStringContainsString('It is split by the cron job within the hour', $all);
        self::assertStringNotContainsString('needs you', $all);
        self::assertStringNotContainsString('lost it. .', $all);
    }

    public function testEverySurfaceCarriesTheStatement(): void
    {
        $note = TrafficSourceOwners::VARIABLES_NOTE;
        self::assertStringContainsString('not being recorded', $note);
        self::assertStringContainsString('moved back or split', $note);

        $controller = self::file('api/v3/Controllers/TrafficSourceOwnersController.php');
        self::assertStringContainsString(
            "'variables_note' => \$unrecorded > 0 ? TrafficSourceOwners::VARIABLES_NOTE : null",
            $controller
        );
        self::assertStringContainsString("'variables_unrecorded' => \$unrecorded", $controller);

        $ui = self::file('202-config/functions-traffic-source-owners-ui.php');
        self::assertSame(
            2,
            substr_count($ui, '. p202_ts_owners_variables_note($report)'),
            'the notice and the panel both say it'
        );

        self::assertStringContainsString(
            "'splits_note' => \$splits === [] ? null : TrafficSourceOwners::SPLITS_NOTE",
            $controller
        );
        self::assertStringContainsString('p202_ts_owners_splits($report)', $ui, 'the panel lists what was split');
        foreach (
            ['Nothing was taken from the other user', 'record their custom variable values again',
            'values recorded earlier'] as $said
        ) {
            self::assertStringContainsString($said, TrafficSourceOwners::SPLITS_NOTE);
        }

        $cli = self::file('go-cli/cmd/system_traffic_sources.go');
        self::assertStringContainsString('`json:"splits_note"`', $cli);
        self::assertStringContainsString('tsoPrintSplits(report)', $cli);
        self::assertStringContainsString('`json:"variables_note"`', $cli);
        self::assertStringContainsString('"Note: "+*note', $cli, 'show prints the server\'s sentence');

        $spec = self::file('docs/openapi.yaml');
        foreach (
            ['variables_note:', 'variables_unrecorded:', 'auto_split:', 'auto_split_blocked_by:', 'splits:',
            'splits_note:'] as $field
        ) {
            self::assertStringContainsString($field, $spec);
        }
        self::assertStringContainsString($note, self::file('documentation/api/15-system.md'), 'the guide quotes it');
    }

    /** A script that stores a source's variables for a click is one that can lose them. */
    public function testEveryScriptThatStoresASourcesVariablesNotesTheOnesItDoesNot(): void
    {
        $root = dirname(__DIR__, 2);
        $storing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/tracking202',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, '->findOrCreateVariable(')) {
                continue;
            }
            $path = strtolower(substr($file->getPathname(), strlen($root) + 1));
            $storing[] = $path;
            self::assertSame(
                1,
                substr_count($source, 'LookupRepositoryFactory::noteUnrecordedVariables('),
                $path . ' stores a traffic source\'s variables and must note a click that loses them'
            );
            self::assertMatchesRegularExpression(
                '/noteUnrecordedVariables\\(\\s*\\$db,[^;]*,\\s*\\$_GET\\s*\\);/',
                $source,
                $path . ' hands the note its own query'
            );
        }
        sort($storing);
        self::assertSame([
            'tracking202/redirect/dl.php',
            'tracking202/redirect/rtr.php',
            'tracking202/static/record_adv.php',
            'tracking202/static/record_simple.php',
        ], array_values(array_unique($storing)));
    }
}

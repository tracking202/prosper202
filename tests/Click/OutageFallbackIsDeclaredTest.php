<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * With the database out of reach, connect2.php answers 503 for every script
 * that did not declare, before loading it, that it goes on without one
 * ($p202OutageFallback = true, in the scope that loads it). A script that
 * declares it reaches its own code with $db null, so each is listed with what
 * it does then; and a script one of them includes after the bootstrap ran is
 * past that check, so unless it is listed too it asks for the database itself
 * (p202RequireDatabase($db)). tests/live/outage-fallback.sh runs every
 * endpoint without the database.
 */
final class OutageFallbackIsDeclaredTest extends TestCase
{
    private const BOOTSTRAP = '~(?:include|require)(?:_once)?\s*\(?[^;]*connect2\.php~';

    /** file => what it does without the database */
    private const FALLBACK = [
        'tracking202/redirect/dl.php' => 'the URL it keeps for an outage, or the unavailable page',
        'tracking202/redirect/rtr.php' => 'the URL it keeps for an outage, or an error page',
        'tracking202/redirect/lp.php' => 'the URL it keeps for an outage',
        'tracking202/redirect/off.php' => 'the URL it keeps for an outage',
        'tracking202/redirect/cl.php' => 'its page without the click\'s row',
        'tracking202/redirect/lpc.php' => 'its no-connection answer',
        'tracking202/redirect/cl2.php' => 'never reads the database',
        'tracking202/redirect/go.php' => 'sets no click cookie; lp.php and off.php fall back, offrtr.php asks',
        'api/v1/reports/index.php' => 'the API\'s JSON error',
        'api/v2/reports/index.php' => 'the API\'s JSON error',
        'api/v2/categories/index.php' => 'the API\'s JSON error',
    ];

    public function testEveryScriptThatGoesOnWithoutTheDatabaseIsListed(): void
    {
        $declared = [];
        $loaders = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (preg_match(self::BOOTSTRAP, $source, $include, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $loaders++;
            $define = strpos($source, '$p202OutageFallback = true;');
            if ($define === false) {
                continue;
            }
            self::assertLessThan($include[0][1], $define, "$file declares the fallback after loading connect2.php");
            $declared[] = $file;
        }
        sort($declared);
        $listed = array_keys(self::FALLBACK);
        sort($listed);

        self::assertGreaterThan(20, $loaders, 'the scripts that load connect2.php were read');
        self::assertSame($listed, $declared, 'A script that goes on without the database, unlisted or gone.');
    }

    public function testAScriptIncludedPastTheBootstrapAsksForTheDatabase(): void
    {
        $sources = SourceScan::phpFiles();
        $asked = [];
        foreach (array_keys(self::FALLBACK) as $file) {
            preg_match_all(
                '~(?:include|require)(?:_once)?[^;]*[\'"]/(tracking202/(?:redirect|static)/\w+\.php)[\'"]~',
                $sources[$file] ?? '',
                $included
            );
            foreach ($included[1] as $script) {
                if (!isset(self::FALLBACK[$script])) {
                    $asked[$script] = str_contains($sources[$script] ?? '', 'p202RequireDatabase($db);');
                }
            }
        }

        self::assertSame(['tracking202/redirect/offrtr.php' => true], $asked);
    }

    public function testTheBootstrapAsksUnlessTheScriptDeclared(): void
    {
        $bootstrap = SourceScan::phpFiles()['202-config/connect2.php'];

        self::assertMatchesRegularExpression(
            '~if \(\(\$p202OutageFallback \?\? null\) !== true\) \{\s*p202RequireDatabase\(\$db\);\s*\}~',
            $bootstrap
        );
    }
}

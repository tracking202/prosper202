<?php

declare(strict_types=1);

namespace Tests\Release;

use Api\V3\Controllers\AdministrationController;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every script the product names, to be pasted somewhere or called, is a
 * file the install serves.
 *
 * Account › API integrations handed out tracking202/static/paykickstart.php
 * as PayKickstart's IPN URL under "No setup needed", and GET
 * /system/integrations and `p202 system integrations` listed it, from the
 * day it was written: the file never existed, so every PayKickstart IPN sent
 * there was a 404 nobody in Prosper202 saw. jvzoo.php, zpn.php and slack.php
 * were handed out the same way, and are listed below until their own
 * changes remove them; the list only shrinks.
 *
 * What it reads: every string literal in a served PHP file (SourceScan, so
 * not the tests) that names a script under tracking202/ or a 202-*
 * directory, as PHP spells it in the literal (a query string after it, a
 * leading slash or ../ before it), and AdministrationController's list of
 * integrations, read as the controller holds it. A path assembled from
 * parts (`'tracking202/' . $dir . '/x.php'`), a script named without its
 * directory, and a URL written in a script, a stylesheet or the docs are
 * not seen.
 */
final class HandedOutUrlsNameServedFilesTest extends TestCase
{
    /** A script path inside a literal. */
    private const SCRIPT = '#(?<![A-Za-z0-9_.-])((?:tracking202|202-[a-z]+)/[A-Za-z0-9_./-]*?\.php)(?![A-Za-z0-9_])#';

    /** Handed out, never written: path => where, until the change that removes it. */
    private const KNOWN_DEAD = [
        'tracking202/static/jvzoo.php' => 'JVZoo\'s IPN URL on Account › API integrations and GET /system/integrations',
        'tracking202/static/zpn.php' => 'Zaxaa\'s ZPN URL on Account › API integrations and GET /system/integrations',
        'tracking202/static/slack.php' => 'Slack\'s webhook on Account › API integrations, GET /system/integrations',
    ];

    public function testEveryScriptAServedFileNamesExists(): void
    {
        $root = SourceScan::repoRoot();
        $named = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (token_get_all($source) as $token) {
                $literal = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
                if (!is_array($token) || !in_array($token[0], $literal, true)) {
                    continue;
                }
                preg_match_all(self::SCRIPT, $token[1], $matches);
                foreach ($matches[1] as $script) {
                    $named[$script][] = $path . ':' . $token[2];
                }
            }
        }
        self::assertGreaterThan(40, count($named), 'the scan found the scripts the tree names');
        self::assertArrayHasKey('tracking202/static/cb202.php', $named, 'the scan reads the integrations page');

        $missing = [];
        foreach ($named as $script => $sites) {
            if (!is_file($root . '/' . $script) && !isset(self::KNOWN_DEAD[$script])) {
                $missing[] = $script . '  named at ' . implode(', ', array_slice($sites, 0, 3));
            }
        }
        self::assertSame([], $missing, "These scripts are named in the served tree and are not in it:\n  "
            . implode("\n  ", $missing) . "\nA URL handed out for something to call must be a file this install"
            . ' serves: write the script, or stop handing it out.');

        $stale = [];
        foreach (self::KNOWN_DEAD as $script => $where) {
            if (is_file($root . '/' . $script)) {
                $stale[] = $script . ': it exists now';
            } elseif (!isset($named[$script])) {
                $stale[] = $script . ': nothing names it any more';
            }
        }
        self::assertSame([], $stale, "KNOWN_DEAD only shrinks; remove:\n  " . implode("\n  ", $stale));
    }

    public function testEveryIntegrationGetSystemIntegrationsListsIsAServedScript(): void
    {
        $integrations = (new \ReflectionClassConstant(AdministrationController::class, 'INTEGRATIONS'))->getValue();
        self::assertIsArray($integrations);
        self::assertArrayHasKey('paykickstart', $integrations);

        $missing = [];
        foreach ($integrations as $key => $integration) {
            $script = $integration[2];
            if (!is_file(SourceScan::repoRoot() . '/' . $script) && !isset(self::KNOWN_DEAD[$script])) {
                $missing[] = $key . ' => ' . $script;
            }
        }
        self::assertSame([], $missing, 'GET /system/integrations hands out a URL no file answers');
    }

    public function testTheScanReadsAPathAsTheLiteralSpellsIt(): void
    {
        $paths = static function (string $literal): array {
            preg_match_all(self::SCRIPT, $literal, $m);

            return $m[1];
        };

        self::assertSame(['tracking202/static/gpb.php'], $paths("'/tracking202/static/gpb.php?amount=&subid='"));
        self::assertSame(['202-config/connect.php'], $paths("'../202-config/connect.php'"));
        self::assertSame(
            ['tracking202/static/x.php', 'tracking202/redirect/dl.php'],
            $paths("'tracking202/static/x.php tracking202/redirect/dl.php'")
        );
        self::assertSame([], $paths("'tracking202/static/'"), 'a directory alone is not a script');
        self::assertSame([], $paths("'mytracking202/static/a.php'"), 'part of another name');
    }
}

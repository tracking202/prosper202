<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * landing.php's dynamic content (1.9.56's dcs.t202DataObj): the shipped
 * block runs under node (dcs-harness.js) against a DOM double.
 */
final class LandingDynamicContentTest extends TestCase
{
    public function testSegmentsFormFieldsAndThePublicDcsApi(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/dcs-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/tracking202/static/landing.php') . ' 2>&1';
        exec($command, $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        self::assertSame('Dynamic content harness passed', implode("\n", $lines));
    }

    /** The IP segment is set only under the page's account's privacy setting; dates in its zone. */
    public function testTheServerSegmentsFollowThePagesAccount(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/static/landing.php');
        self::assertMatchesRegularExpression(
            '/p202ApplyOwnerPrivacy\(\$p202PageOwner\);\s*if \(trackingEnabled\(\)\) \{\s*\$t202ServerData\[\'t202IP\'\] = \$visitorIp;/',
            $source
        );
        self::assertStringContainsString("\$t202ServerData['t202IP'] = '';", $source);
        self::assertStringContainsString("\\Prosper202\\Report\\AccountZone::normalize(isset(\$identityRow) ? (\$identityRow['page_timezone'] ?? null) : null)", $source);
    }
}

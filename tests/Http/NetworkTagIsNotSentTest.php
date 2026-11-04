<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * K3QP: nothing sends the network tag. Adding an affiliate network, a DNI
 * network or a traffic source posted its name and the install hash to
 * my.tracking202.com/api/v2/tag/user/{hash}/{type}, with no timeout and the
 * curl error echoed into the page; its only effects were an Intercom tag
 * (Intercom is gone) and a row nothing reads. Removed (owner, 2026-11-04).
 */
final class NetworkTagIsNotSentTest extends TestCase
{
    public function testNoSourceCallsTheNetworkTag(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('tracking202/setup/aff_networks.php', $files, 'the scan read the tree');

        $found = [];
        foreach ($files as $path => $source) {
            foreach (['tagUserByNetwork', '/tag/user/'] as $name) {
                if (stripos($source, $name) !== false) {
                    $found[] = "$path: $name";
                }
            }
        }
        self::assertSame([], $found);
    }
}

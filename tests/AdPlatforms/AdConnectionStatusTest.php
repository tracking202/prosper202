<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionStatus;
use Tests\Support\LifecycleContract;
use Tests\Support\SourceScan;

/**
 * An ad connection's stored status is connected or key_refused, each
 * reached from the other; `credentials_unreadable` is what a read shows
 * while the credentials do not open, and the API's documented list is the
 * stored states and that reading.
 */
final class AdConnectionStatusTest extends TestCase
{
    public function testTheStoredStatesAndTheirMoves(): void
    {
        self::assertSame([], LifecycleContract::violations(AdConnectionStatus::class));
        self::assertSame(['connected', 'key_refused'], array_column(AdConnectionStatus::cases(), 'value'));
        self::assertSame([AdConnectionStatus::Connected], AdConnectionStatus::initial());
        self::assertSame([AdConnectionStatus::KeyRefused], AdConnectionStatus::Connected->next());
        self::assertSame([AdConnectionStatus::Connected], AdConnectionStatus::KeyRefused->next());
    }

    public function testUnreadableIsShownAndIsNotAState(): void
    {
        self::assertNull(AdConnectionStatus::tryFrom(AdConnectionStatus::UNREADABLE));
        self::assertSame(['connected', 'key_refused', 'credentials_unreadable'], AdConnectionStatus::shown());
    }

    public function testTheOpenApiSpecListsWhatAReadShows(): void
    {
        $spec = (string) file_get_contents(SourceScan::repoRoot() . '/docs/openapi.yaml');
        $start = strpos($spec, "\n    AdConnection:\n");
        self::assertNotFalse($start);
        self::assertSame(1, preg_match('/^        status: \{type: string, enum: \[([^\]]*)\]\}$/m', substr($spec, $start, 4000), $m), 'AdConnection.status is an enum');

        self::assertSame(AdConnectionStatus::shown(), array_map('trim', explode(',', $m[1])));
    }

    public function testTheApiGuideNamesEachShownStatus(): void
    {
        $guide = (string) file_get_contents(SourceScan::repoRoot() . '/documentation/api/28-ad-connections.md');
        foreach (AdConnectionStatus::shown() as $status) {
            self::assertStringContainsString('`' . $status . '`', $guide);
        }
    }
}

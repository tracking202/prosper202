<?php

declare(strict_types=1);

namespace Tests\Attribution\Apps;

use Api\V3\Controllers\AttributionAppsController;
use PHPUnit\Framework\TestCase;
use Tracking202\Attribution\RegisteredApps;

/**
 * The reader Setup's "Your apps" panel and Analyze's App filter share.
 *
 * The three things it exists to keep identical between them are the ceiling,
 * the ordering, and whether the list was cut — and the last one is the one
 * with a wrong answer that looks like a right one. A page that believes it
 * has every app shows a menu missing the app you want, which reads as "that
 * app is not registered" rather than "there are more".
 */
final class RegisteredAppsTest extends TestCase
{
    public function testAppsComeBackByNameRegardlessOfTheOrderTheApiSent(): void
    {
        $result = RegisteredApps::read($this->apps([
            ['app_id' => 3, 'app_name' => 'zeta'],
            ['app_id' => 1, 'app_name' => 'Alpha'],
            ['app_id' => 2, 'app_name' => 'middle'],
        ], total: 3));

        $this->assertSame(
            ['Alpha', 'middle', 'zeta'],
            array_column($result['apps'], 'app_name'),
            'case-insensitive by name, so Alpha does not sort after zeta'
        );
        $this->assertSame(3, $result['total']);
        $this->assertFalse($result['truncated']);
    }

    public function testAskingForMoreThanTheApiServesIsReported(): void
    {
        // Two rows back, six in the account: the page is showing a third of
        // what exists and has to say so.
        $result = RegisteredApps::read($this->apps([
            ['app_id' => 1, 'app_name' => 'a'],
            ['app_id' => 2, 'app_name' => 'b'],
        ], total: 6));

        $this->assertTrue($result['truncated']);
        $this->assertSame(6, $result['total'], 'the count to report is the account total, not the rows in hand');
    }

    public function testTheReadAsksForTheApisOwnCeiling(): void
    {
        $apps = $this->apps([], total: 0);
        RegisteredApps::read($apps);

        // Asking for more than the API serves would be silently clamped, so a
        // larger number here would only make the code look as though it read
        // more than it does.
        $this->assertSame(['limit' => RegisteredApps::MAX], $apps->lastParams);
        $this->assertSame(500, RegisteredApps::MAX, 'api/v3/Controller::list() clamps limit to 500');
    }

    /**
     * The fail-open case. If `total` ever goes missing, a short page still
     * proves nothing was cut — a fuller one would have filled it — but a page
     * exactly at the ceiling proves nothing either way, and "nothing was cut"
     * is the answer that keeps the page silent in the one situation the flag
     * exists to report (error pattern #11).
     */
    public function testAFullPageWithNoTotalIsTreatedAsCutRatherThanComplete(): void
    {
        $full = array_map(
            static fn(int $i): array => ['app_id' => $i, 'app_name' => 'app' . $i],
            range(1, RegisteredApps::MAX)
        );

        $this->assertTrue(
            RegisteredApps::read($this->apps($full, total: null))['truncated'],
            'a page at the ceiling with no total must not claim to be the whole list'
        );
        $this->assertFalse(
            RegisteredApps::read($this->apps(array_slice($full, 0, 3), total: null))['truncated'],
            'a page short of the ceiling is the whole list, total or no total'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function apps(array $rows, ?int $total): AttributionAppsController
    {
        return new class ($rows, $total) extends AttributionAppsController {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;

            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(private array $rows, private ?int $total)
            {
                // Deliberately not calling the parent constructor: this stands
                // in for the API read, and the reader under test touches no
                // other part of it. A real mysqli is not available here.
            }

            public function list(array $params): array
            {
                $this->lastParams = $params;
                $out = ['data' => $this->rows];
                if ($this->total !== null) {
                    $out['pagination'] = ['total' => $this->total];
                }

                return $out;
            }
        };
    }
}

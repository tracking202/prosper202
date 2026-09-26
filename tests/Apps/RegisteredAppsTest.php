<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Controllers\AppRegistrationsController;
use PHPUnit\Framework\TestCase;
use Tracking202\Apps\RegisteredApps;

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
            ['registration_id' => 3, 'app_name' => 'zeta'],
            ['registration_id' => 1, 'app_name' => 'Alpha'],
            ['registration_id' => 2, 'app_name' => 'middle'],
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
            ['registration_id' => 1, 'app_name' => 'a'],
            ['registration_id' => 2, 'app_name' => 'b'],
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
        $this->assertSame(['limit' => RegisteredApps::MAX, 'filter' => ['platform' => 'ios']], $apps->lastParams,
            'the two pages read iOS registrations only: both are about Apple postbacks');
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
            static fn(int $i): array => ['registration_id' => $i, 'app_name' => 'app' . $i],
            range(1, RegisteredApps::MAX)
        );

        $cut = RegisteredApps::read($this->apps($full, total: null));
        $this->assertTrue(
            $cut['truncated'],
            'a page at the ceiling with no total must not claim to be the whole list'
        );
        // And it must not invent one either. Falling back to the row count
        // here rendered "500 of 500 apps" — an exact figure, stated to the
        // reader, in the one case this cannot establish.
        $this->assertNull($cut['total'], 'an unknown total must stay unknown, not become the row count');

        $whole = RegisteredApps::read($this->apps(array_slice($full, 0, 3), total: null));
        $this->assertFalse($whole['truncated'], 'a page short of the ceiling is the whole list, total or no total');
        $this->assertNull($whole['total'], 'still unknown; the caller renders a plain count when nothing was cut');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    /**
     * The ceiling here must fit inside the API's `registration_ids` filter.
     *
     * MobileAppsController::developmentNudges() names every waiting app in
     * one report() call, and that list is bounded by MAX below. The filter
     * refuses more ids than AppPostbacksController::MAX_REGISTRATION_IDS with
     * a 422 — which developmentNudges() catches as an HttpException and turns
     * into "no nudges". So raising MAX without raising the filter's bound
     * would not fail; it would quietly stop showing development nudges on
     * exactly the accounts large enough to have them. The two are in
     * different tiers and cannot reference each other, so this asserts it.
     */
    public function testTheCeilingFitsTheApiFilterThatCarriesIt(): void
    {
        $filterBound = (new \ReflectionClassConstant(
            \Api\V3\Controllers\AppPostbacksController::class,
            'MAX_REGISTRATION_IDS'
        ))->getValue();

        $this->assertLessThanOrEqual(
            $filterBound,
            RegisteredApps::MAX,
            'RegisteredApps::MAX exceeds the registration_ids filter bound, so a full page of'
            . ' waiting apps would be refused and the nudges would disappear silently'
        );
    }

    private function apps(array $rows, ?int $total): AppRegistrationsController
    {
        return new class ($rows, $total) extends AppRegistrationsController {
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

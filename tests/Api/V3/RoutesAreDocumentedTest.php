<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * Every operation the v3 router serves is in docs/openapi.yaml, and every
 * operation the document describes is served.
 *
 * At the release gate the router served 210 operations and the document
 * described 138 of them: the LTV, sync, forecast-event and bulk-upsert
 * families, `/capabilities`, `/versions` and a rotator rule update had never
 * been written down, while the per-feature coverage tests (Apps, Goals,
 * ledger reads, attribution exports) each checked only their own paths.
 * RouteInventory reads both sides; this holds them equal. A route written
 * with PUT is documented as PUT or PATCH, because the router serves either
 * (Router::match()).
 */
final class RoutesAreDocumentedTest extends TestCase
{
    /** @return list<string> */
    private static function withEitherUpdateVerb(string $operation): array
    {
        if (str_starts_with($operation, 'PUT ')) {
            return [$operation, 'PATCH ' . substr($operation, 4)];
        }
        if (str_starts_with($operation, 'PATCH ')) {
            return [$operation, 'PUT ' . substr($operation, 6)];
        }

        return [$operation];
    }

    public function testTheInventoryReadsTheWholeRouter(): void
    {
        $served = RouteInventory::served();
        // A floor, so a reader that lost its way cannot agree with a document
        // it also failed to read. Every family is represented.
        self::assertGreaterThanOrEqual(200, count($served));
        foreach ([
            'GET /campaigns', 'POST /campaigns/bulk-upsert', 'DELETE /forecast-events/{}',
            'GET /ltv/customers/{}/next-offer', 'POST /sync/jobs/{}/cancel', 'GET /changes/{}',
            'POST /staged-changes/{}/apply', 'GET /apps/{}/installs/{}', 'POST /apps/installs/{}/events',
            'GET /goals/{}/outcomes', 'GET /attribution/reports/journeys', 'GET /versions', 'GET /',
        ] as $operation) {
            self::assertContains($operation, $served);
        }
    }

    public function testEveryServedOperationIsDocumented(): void
    {
        $documented = RouteInventory::documented();
        $missing = [];
        foreach (RouteInventory::served() as $operation) {
            if (array_intersect(self::withEitherUpdateVerb($operation), $documented) === []) {
                $missing[] = $operation;
            }
        }

        self::assertSame([], $missing, "served by api/v3/index.php but not described in docs/openapi.yaml:\n  "
            . implode("\n  ", $missing));
    }

    public function testEveryDocumentedOperationIsServed(): void
    {
        $served = RouteInventory::served();
        $phantom = [];
        foreach (RouteInventory::documented() as $operation) {
            if (array_intersect(self::withEitherUpdateVerb($operation), $served) === []) {
                $phantom[] = $operation;
            }
        }

        self::assertSame([], $phantom, "described in docs/openapi.yaml but not served:\n  " . implode("\n  ", $phantom));
    }
}

<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Response;

/**
 * The one mapping from what became of a notification to the status the
 * provider's delivery log shows: 2xx only where nothing is left to do.
 */
final class ResponseTest extends TestCase
{
    public function testEveryOutcomeHasItsStatus(): void
    {
        $statuses = [];
        foreach (OutcomeKind::cases() as $kind) {
            $statuses[$kind->value] = Response::status($kind);
        }

        self::assertSame([
            'recorded' => 200, 'reversed' => 200, 'duplicate' => 200, 'test' => 200, 'ignored' => 200,
            'no_click' => 200,
            'malformed' => 400, 'unsupported_body' => 415, 'unverified' => 403, 'unknown_click' => 404,
            'nothing_to_reverse' => 404, 'conflict' => 409, 'currency_mismatch' => 422, 'method_not_allowed' => 405,
            'failed' => 500,
        ], $statuses);
    }

    public function testTheBodySaysWhatBecameOfIt(): void
    {
        self::assertSame(
            ['error' => false, 'code' => 200, 'outcome' => 'recorded', 'msg' => 'Recorded.', 'conversion_id' => 12],
            Response::body(new Outcome(OutcomeKind::RECORDED, 'Recorded.', 12, 3))
        );
        self::assertSame(
            ['error' => true, 'code' => 403, 'outcome' => 'unverified', 'msg' => 'No key verifies it.'],
            Response::body(new Outcome(OutcomeKind::UNVERIFIED, 'No key verifies it.'))
        );
    }
}

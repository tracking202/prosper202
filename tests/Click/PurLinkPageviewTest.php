<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkPageview;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;

final class PurLinkPageviewTest extends TestCase
{
    protected function tearDown(): void
    {
        RecordedClicks::reset();
    }

    public function testOptedInCommittedPageviewProducesSeparateScopedProofs(): void
    {
        RecordedClicks::note(22);
        $key = str_repeat('x', 32);
        $output = PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => $key);
        self::assertSame(1, preg_match('/p202PurLinkPageview\((\{.*\})\)/', $output, $matches));
        $proofs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(22, PurLinkProof::verify($proofs['exitProof'], PurLinkProof::EXIT, 1_700_000_001, $key)['click_id']);
        self::assertSame(4, PurLinkProof::verify($proofs['pixelProof'], PurLinkProof::PIXELS, 1_700_000_001, $key)['landing_page_id']);
        self::assertNull(PurLinkProof::verify($proofs['exitProof'], PurLinkProof::PIXELS, 1_700_000_001, $key));
        self::assertNull(PurLinkProof::verify($proofs['pixelProof'], PurLinkProof::PIXELS, 1_700_086_400, $key));
    }

    /** @dataProvider refusedPageviews */
    public function testRefusedPageviewsDoNotReadTheKey(array $request, bool $trackingAllowed): void
    {
        $output = PurLinkPageview::script($request, $trackingAllowed, 22, 4, 8, 1_700_000_000,
            static function (): ?string { self::fail('a refused pageview read the signing key'); });
        self::assertSame('', $output);
    }

    public static function refusedPageviews(): iterable
    {
        yield 'not opted in' => [[], true];
        yield 'list option' => [['p202_purlink' => ['1']], true];
        yield 'withheld consent' => [['p202_purlink' => '1', 'p202_consent' => '0'], true];
        yield 'invalid consent' => [['p202_purlink' => '1', 'p202_consent' => ['1']], true];
        yield 'privacy restriction' => [['p202_purlink' => '1'], false];
    }

    public function testUnallocatedClicksAndUnavailableKeyDoNotEmitCapabilities(): void
    {
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => str_repeat('x', 32)));
        RecordedClicks::note(22);
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, fn () => null));
        self::assertSame('', PurLinkPageview::script(['p202_purlink' => '1'], true, 22, 4, 8, 1_700_000_000, static function (): ?string { throw new \RuntimeException('unavailable'); }));
    }
}

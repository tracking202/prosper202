<?php

declare(strict_types=1);

namespace Tests\Click;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\PurLinkSubid;
use Prosper202\Click\RecordedClicks;

final class PurLinkSubidTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const NOW = 1_700_000_000;

    protected function setUp(): void
    {
        RecordedClicks::reset();
        RecordedClicks::note(41);
    }

    public function testOfferBoundSubidSurvivesItsShortLivedPageProof(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $subid = PurLinkSubid::issue($proof, '987', self::NOW, self::KEY);
        self::assertMatchesRegularExpression('/\A41-987-[0-9a-f]{12}\z/', $subid);
        self::assertSame($subid, rawurlencode($subid));
        $parsed = PurLinkSubid::parse($subid);
        self::assertSame(['click_id' => 41, 'campaign_public_id' => 987], array_slice($parsed, 0, 2, true));
        // The page proof expires; the SubID a network echoes back later does not.
        self::assertTrue(PurLinkSubid::check($parsed, 12, 3, self::KEY));
        self::assertFalse(PurLinkSubid::check($parsed, 12, 3, str_repeat('x', 32)));
    }

    public function testTamperedFieldsAndLegacyValuesAreNotSignedSubids(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $subid = PurLinkSubid::issue($proof, 987, self::NOW, self::KEY);
        $parsed = PurLinkSubid::parse($subid);
        self::assertFalse(PurLinkSubid::check($parsed, 13, 3, self::KEY), 'another page');
        self::assertFalse(PurLinkSubid::check($parsed, 12, 4, self::KEY), 'another account');
        foreach (['42-', '41-988-'] as $prefix) {
            $altered = PurLinkSubid::parse($prefix === '42-' ? '42-' . substr($subid, 3) : '41-988-' . substr($subid, 7));
            self::assertFalse(PurLinkSubid::check($altered, 12, 3, self::KEY), $prefix);
        }
        foreach ([
            '41-987', $subid . 'a', '041-987-' . substr($subid, -12), '41-987-' . strtoupper(substr($subid, -12)),
            '41.987.' . substr($subid, -12), '0-987-' . substr($subid, -12), '9999999999999999999-987-' . substr($subid, -12),
            42, null,
        ] as $bad) {
            self::assertNull(PurLinkSubid::parse($bad));
        }
    }

    public function testCannotIssueFromWrongPurposeProof(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::PIXELS, 41, 12, 3, self::NOW, 60, self::KEY);
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, 987, self::NOW, self::KEY);
    }

    public function testCannotIssueAfterExitProofExpires(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, 987, self::NOW + 60, self::KEY);
    }

    public function testCannotIssueForMalformedCampaign(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, '987x', self::NOW, self::KEY);
    }

    public function testLegacySubidIsParsedExactlyWithoutTreatingItAsProof(): void
    {
        self::assertSame(['click_id' => 41, 'campaign_public_id' => 987], PurLinkSubid::legacy('41-987'));
        foreach (['41', '041-987', '41-0987', '41-987x', '41--987', '0-987', '41-0', '9999999999999999999-1'] as $bad) {
            self::assertNull(PurLinkSubid::legacy($bad));
        }
    }
}

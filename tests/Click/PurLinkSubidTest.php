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
        $subid = PurLinkSubid::issue($proof, '987', self::NOW, 86400, self::KEY);
        self::assertSame($subid, rawurlencode($subid));
        self::assertSame([
            'click_id' => 41,
            'landing_page_id' => 12,
            'user_id' => 3,
            'campaign_public_id' => 987,
            'expires' => self::NOW + 86400,
        ], PurLinkSubid::verify($subid, self::NOW + 61, self::KEY));
        self::assertNull(PurLinkSubid::verify($subid, self::NOW + 86400, self::KEY));
        self::assertNull(PurLinkSubid::verify($subid, self::NOW, str_repeat('x', 32)));
    }

    public function testTamperedFieldsAndLegacyValuesAreNotSignedSubids(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $subid = PurLinkSubid::issue($proof, 987, self::NOW, 86400, self::KEY);
        $alteredSubids = [
            str_replace('.41.', '.42.', $subid),
            str_replace('.12.', '.13.', $subid),
            str_replace('.3.', '.4.', $subid),
            str_replace('.987.', '.988.', $subid),
            str_replace('.41.', '.041.', $subid),
            str_replace('.41.', '.9999999999999999999.', $subid),
            '41-987',
            $subid . 'x',
        ];
        foreach ($alteredSubids as $bad) {
            self::assertNull(PurLinkSubid::verify($bad, self::NOW, self::KEY));
        }
    }

    public function testCannotIssueFromExpiredOrWrongPurposeProof(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::PIXELS, 41, 12, 3, self::NOW, 60, self::KEY);
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, 987, self::NOW, 60, self::KEY);
    }

    public function testCannotIssueAfterExitProofExpires(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, 987, self::NOW + 60, 60, self::KEY);
    }

    public function testCannotIssueForMalformedCampaignOrTooLongLifetime(): void
    {
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 60, self::KEY);
        try {
            PurLinkSubid::issue($proof, '987x', self::NOW, 60, self::KEY);
            self::fail('A partial campaign ID was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        PurLinkSubid::issue($proof, 987, self::NOW, PurLinkSubid::MAX_LIFETIME + 1, self::KEY);
    }

    public function testLegacySubidIsParsedExactlyWithoutTreatingItAsProof(): void
    {
        self::assertSame(['click_id' => 41, 'campaign_public_id' => 987], PurLinkSubid::legacy('41-987'));
        foreach (['41', '041-987', '41-0987', '41-987x', '41--987', '0-987', '41-0', '9999999999999999999-1'] as $bad) {
            self::assertNull(PurLinkSubid::legacy($bad));
        }
    }
}

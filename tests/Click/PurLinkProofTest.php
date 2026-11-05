<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;
use InvalidArgumentException;

final class PurLinkProofTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const NOW = 1_700_000_000;

    protected function setUp(): void
    {
        RecordedClicks::reset();
    }

    public function testAllocatedClickCanBeVerifiedForItsAccountPageAndPurpose(): void
    {
        RecordedClicks::note(41);
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 300, self::KEY);

        self::assertSame(
            ['click_id' => 41, 'landing_page_id' => 12, 'user_id' => 3, 'expires' => self::NOW + 300],
            PurLinkProof::verify($proof, PurLinkProof::EXIT, self::NOW, self::KEY)
        );
        self::assertNull(PurLinkProof::verify($proof, PurLinkProof::PIXELS, self::NOW, self::KEY));
        self::assertNull(PurLinkProof::verify($proof, PurLinkProof::EXIT, self::NOW + 300, self::KEY));
        self::assertNull(PurLinkProof::verify($proof, PurLinkProof::EXIT, self::NOW, str_repeat('x', 32)));
    }

    public function testVisitorCannotChooseAnotherClickToSign(): void
    {
        RecordedClicks::note(41);
        $this->expectException(InvalidArgumentException::class);
        PurLinkProof::issue(PurLinkProof::EXIT, 42, 12, 3, self::NOW, 300, self::KEY);
    }

    public function testChangingAnySignedFieldOrUsingANoncanonicalValueFails(): void
    {
        RecordedClicks::note(41);
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, 41, 12, 3, self::NOW, 300, self::KEY);
        $alteredProofs = [
            str_replace('.41.', '.42.', $proof),
            str_replace('.12.', '.13.', $proof),
            str_replace('.3.', '.4.', $proof),
            str_replace('.41.', '.041.', $proof),
            str_replace('.41.', '.9999999999999999999.', $proof),
            $proof . 'x',
        ];
        foreach ($alteredProofs as $altered) {
            self::assertNull(PurLinkProof::verify($altered, PurLinkProof::EXIT, self::NOW, self::KEY));
        }
    }

    public function testPixelProofCannotAuthorizeAnExitAndLifetimeIsBounded(): void
    {
        RecordedClicks::note(41);
        $proof = PurLinkProof::issue(PurLinkProof::PIXELS, 41, 12, 3, self::NOW, PurLinkProof::MAX_LIFETIME, self::KEY);
        self::assertNotNull(PurLinkProof::verify($proof, PurLinkProof::PIXELS, self::NOW, self::KEY));
        self::assertNull(PurLinkProof::verify($proof, PurLinkProof::EXIT, self::NOW, self::KEY));
        self::assertNull(PurLinkProof::verify($proof, PurLinkProof::PIXELS, self::NOW - 1, self::KEY));

        $this->expectException(InvalidArgumentException::class);
        PurLinkProof::issue(PurLinkProof::PIXELS, 41, 12, 3, self::NOW, PurLinkProof::MAX_LIFETIME + 1, self::KEY);
    }
}

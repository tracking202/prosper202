<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\InstallTokenGrant;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\RecordedClicks;

/**
 * The redirect signs only a click the request can prove is the visitor's
 * (CLAUDE.md #16). lp.php reads its click id from
 * `tracking202subid_a_<campaign>`, a cookie any client can set to a
 * sequential id; before this, replaceTokens() signed whatever that cookie
 * named, so setting it to someone else's click id returned a valid token for
 * their click in the Play store link.
 */
final class InstallTokenGrantTest extends TestCase
{
    private const KEY = "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07";

    private int $keyReads = 0;

    private function key(): callable
    {
        return function (): string {
            ++$this->keyReads;

            return self::KEY;
        };
    }

    protected function tearDown(): void
    {
        RecordedClicks::reset();
    }

    public function testAClickThisRequestAllocatedIsSigned(): void
    {
        self::assertSame(InstallToken::forClick(42, self::KEY), InstallTokenGrant::forRequest('42', true, [], $this->key()));
        self::assertSame(InstallToken::forClick(42, self::KEY), InstallTokenGrant::forRequest(42, true, [], $this->key()));
    }

    public function testAClickIdTheVisitorOnlyNamedIsNotSigned(): void
    {
        // The attack: Cookie: tracking202subid_a_7=<victim click>, no proof.
        $cookies = ['tracking202subid_a_7' => '1001', 'tracking202subid' => '1001'];
        self::assertSame('', InstallTokenGrant::forRequest('1001', false, $cookies, $this->key()));
        self::assertSame(0, $this->keyReads, 'and the key is not even read for it');
    }

    public function testTheVisitorsOwnProofGrantsItsClick(): void
    {
        $token = InstallToken::forClick(1001, self::KEY);
        foreach (['tracking202itok', 'tracking202itok_a_7'] as $name) {
            self::assertSame($token, InstallTokenGrant::forRequest('1001', false, [$name => $token], $this->key()), $name);
        }
        // Several proofs, one of them for this click: the matching one grants.
        self::assertSame($token, InstallTokenGrant::forRequest('1001', false, [
            'tracking202itok' => InstallToken::forClick(2002, self::KEY),
            'tracking202itok_a_7' => $token,
        ], $this->key()));
    }

    public function testAProofForAnotherClickOrWithAForgedMacGrantsNothing(): void
    {
        $mine = InstallToken::forClick(2002, self::KEY);
        self::assertSame('', InstallTokenGrant::forRequest('1001', false, ['tracking202itok' => $mine], $this->key()), 'my proof, your click');
        self::assertSame(0, $this->keyReads, 'a proof naming another click is not checked against the key');

        $forged = '1001.' . substr(strtr(base64_encode(random_bytes(12)), '+/', '-_'), 0, 16);
        self::assertSame('', InstallTokenGrant::forRequest('1001', false, ['tracking202itok' => $forged], $this->key()), 'a guessed MAC');

        $otherKey = InstallToken::forClick(1001, str_repeat("\x09", 32));
        self::assertSame('', InstallTokenGrant::forRequest('1001', false, ['tracking202itok' => $otherKey], $this->key()), 'another install\'s key');
    }

    public function testOnlyTheProofCookiesCount(): void
    {
        $token = InstallToken::forClick(1001, self::KEY);
        foreach (['tracking202subid', 'tracking202subid_a_7', 'xtracking202itok', 'tracking202itok_a_7_x', 'tracking202itok_a_', 'tracking202itok-legacy', 'p202itok'] as $name) {
            self::assertSame('', InstallTokenGrant::forRequest('1001', false, [$name => $token], $this->key()), $name);
        }
        self::assertSame('', InstallTokenGrant::forRequest('1001', false, ['tracking202itok' => [$token]], $this->key()), 'an array value');
    }

    public function testNothingIsSignedWithoutACanonicalIdOrAKey(): void
    {
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-install-token-grant-test.log');
        try {
            foreach (['p202', '', '0', '01001', ' 1001', '1001.0', '-1001', null, 10.01] as $click) {
                self::assertSame('', InstallTokenGrant::forRequest($click, true, [], $this->key()), var_export($click, true));
            }
            $token = InstallToken::forClick(1001, self::KEY);
            self::assertSame('', InstallTokenGrant::forRequest('1001', false, ['tracking202itok' => $token], static fn (): ?string => null), 'no key');
            self::assertSame('', InstallTokenGrant::forRequest('1001', true, [], static function (): string {
                throw new \RuntimeException('down');
            }), 'an unreadable key');
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
    }

    public function testRecordedClicksKnowsOnlyWhatWasNotedAndOnlyCanonically(): void
    {
        self::assertFalse(RecordedClicks::has('1001'));
        RecordedClicks::note(1001);
        RecordedClicks::note(0);
        RecordedClicks::note(-5);
        self::assertTrue(RecordedClicks::has('1001'));
        self::assertTrue(RecordedClicks::has(1001));
        foreach (['01001', '1001 ', '1001.0', 0, '0', '-5', -5, null, 1001.0] as $spelling) {
            self::assertFalse(RecordedClicks::has($spelling), var_export($spelling, true));
        }
        self::assertFalse(RecordedClicks::has('1002'));
    }
}

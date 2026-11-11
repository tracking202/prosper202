<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PageUpdate;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;

/** dcs.updateDataObj()'s proof and fields; PageUpdateIntegrationTest writes them. */
final class PageUpdateTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const NOW = 1_700_000_000;

    private string $log = '';

    protected function setUp(): void
    {
        RecordedClicks::reset();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-page-update-');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        RecordedClicks::reset();
        ini_restore('error_log');
        @unlink($this->log);
    }

    /** @return array<string, mixed> */
    private static function answer(string $script): array
    {
        self::assertSame(1, preg_match('/window\.p202DcsClick\((\{.*\})\); \} catch/', $script, $m), $script);

        return json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
    }

    public function testTheClickScriptCarriesAnUpdateProofForTheClickItsPageAndAccount(): void
    {
        RecordedClicks::note(41);
        $answer = self::answer(PageUpdate::clickScript([], true, 41, 12, 3, self::NOW, fn (): string => self::KEY));

        self::assertSame('41', $answer['t202Subid']);
        self::assertSame(
            ['click_id' => 41, 'landing_page_id' => 12, 'user_id' => 3, 'expires' => self::NOW + PageUpdate::LIFETIME],
            PurLinkProof::verify($answer['updateProof'], PurLinkProof::UPDATE, self::NOW + 1, self::KEY)
        );
        self::assertNull(PurLinkProof::verify($answer['updateProof'], PurLinkProof::EXIT, self::NOW + 1, self::KEY), 'not an exit proof');
        self::assertNull(PurLinkProof::verify($answer['updateProof'], PurLinkProof::PIXELS, self::NOW + 1, self::KEY));
        self::assertNull(PurLinkProof::verify($answer['updateProof'], PurLinkProof::UPDATE, self::NOW + PageUpdate::LIFETIME, self::KEY), 'expired');
        self::assertNull(PurLinkProof::verify($answer['updateProof'], PurLinkProof::UPDATE, self::NOW + 1, str_repeat('k', 32)), 'another install');
    }

    public function testAnExitOrPixelProofIsNotAnUpdateProof(): void
    {
        RecordedClicks::note(41);
        foreach ([PurLinkProof::EXIT, PurLinkProof::PIXELS] as $purpose) {
            $proof = PurLinkProof::issue($purpose, 41, 12, 3, self::NOW, 300, self::KEY);
            self::assertNull(PurLinkProof::verify($proof, PurLinkProof::UPDATE, self::NOW, self::KEY), $purpose);
            $relabelled = PurLinkProof::UPDATE . substr($proof, strlen($purpose));
            self::assertNull(PurLinkProof::verify($relabelled, PurLinkProof::UPDATE, self::NOW, self::KEY), $purpose . ' relabelled');
        }
        $proof = PurLinkProof::issue(PurLinkProof::UPDATE, 41, 12, 3, self::NOW, 300, self::KEY);
        foreach (['.41.' => '.42.', '.12.' => '.13.', '.3.' => '.4.'] as $from => $to) {
            self::assertNull(PurLinkProof::verify(str_replace($from, $to, $proof), PurLinkProof::UPDATE, self::NOW, self::KEY), $to);
        }
    }

    /** @dataProvider heldBack */
    public function testNoProofAndNoKeyReadWhenTheVisitorIsHeldBack(array $request, bool $trackingAllowed, int $clickId): void
    {
        RecordedClicks::note(41);
        $script = PageUpdate::clickScript(
            $request,
            $trackingAllowed,
            $clickId,
            12,
            3,
            self::NOW,
            static function (): ?string {
                self::fail('a held-back pageview read the signing key');
            }
        );
        self::assertSame('', $script);
    }

    public static function heldBack(): iterable
    {
        yield 'refused' => [['p202_consent' => '0'], true, 41];
        yield 'a list for consent' => [['p202_consent' => ['1']], true, 41];
        yield 'the privacy setting' => [[], false, 41];
        yield 'no click' => [[], true, 0];
    }

    public function testTheClickScriptNamesTheClicksTrafficSourceAccountOnlyToATrackedVisitor(): void
    {
        RecordedClicks::note(41);
        $key = fn (): string => self::KEY;
        $script = static fn (array $request, bool $allowed, int $source): string
            => PageUpdate::clickScript($request, $allowed, 41, 12, 3, self::NOW, $key, $source);
        self::assertSame('9', self::answer($script([], true, 9))['t202SourceId']);
        self::assertSame('', self::answer($script([], true, 0))['t202SourceId']);
        self::assertSame('', self::answer($script([], true, -4))['t202SourceId']);
        self::assertSame('', $script([], false, 9), 'held back by the privacy setting');
        self::assertSame('', $script(['p202_consent' => '0'], true, 9), 'refused');
    }

    public function testWithoutAKeyTheSubidStillGoesWithoutAProof(): void
    {
        RecordedClicks::note(41);
        $bare = ['t202Subid' => '41', 't202SourceId' => ''];
        self::assertSame($bare, self::answer(PageUpdate::clickScript([], true, 41, 12, 3, self::NOW, fn (): ?string => null)));
        self::assertSame($bare, self::answer(PageUpdate::clickScript(
            [],
            true,
            41,
            12,
            3,
            self::NOW,
            static function (): ?string {
                throw new \RuntimeException('unavailable');
            }
        )));
        self::assertSame(
            ['t202Subid' => '42', 't202SourceId' => ''],
            self::answer(PageUpdate::clickScript([], true, 42, 12, 3, self::NOW, fn (): string => self::KEY)),
            'a click this request did not allocate is never signed'
        );
        self::assertStringContainsString('dcs update proof unavailable', (string) file_get_contents($this->log));
    }

    public function testOnlyC1ToC4AndTheKeywordMayChange(): void
    {
        $fields = PageUpdate::fields([
            'proof' => 'p', 't202Subid' => '999', 't202SubidOrig' => '998', 'p202_consent' => '1',
            't202C1' => 'Spring%20sale', 't202c2' => "caf\xC3\xA9", 'T202C3' => "bad\xFFbyte", 't202c4' => '',
            't202Kw' => 'blue shoes',
            't202Id' => '7', 't202Referrer' => 'https://ref.example/', 't202Utm_source' => 'news', 'c1' => 'x',
            't202cpc' => '9.99', 't202payout' => '50', 'click_id' => '1',
        ]);

        self::assertSame(
            ['c1' => 'Spring sale', 'c2' => "caf\xC3\xA9", 'c3' => 'bad?byte', 'keyword' => 'blue shoes'],
            $fields['values'],
            'as the click recorder reads them; an empty value changes nothing'
        );
        self::assertSame(['t202id', 't202referrer', 't202utm_source', 'c1', 't202cpc', 't202payout', 'click_id'], $fields['refused']);
    }

    public function testAListOrATwiceSentFieldIsRefused(): void
    {
        self::assertSame(['values' => [], 'refused' => ['t202c1'], 'consent' => true], PageUpdate::fields(['t202c1' => ['a', 'b']]));
        self::assertNull(PageUpdate::fields(['t202C1' => 'a', 't202c1' => 'b']), 'which one is meant is unknown');
        self::assertSame(['values' => [], 'refused' => ['0'], 'consent' => true], PageUpdate::fields(['x']));
        self::assertNull(PageUpdate::fields(['p202_consent' => '1', 'P202_CONSENT' => '0']));
    }

    public function testARefusalInAnyCaseIsARefusal(): void
    {
        foreach (['p202_consent', 'P202_CONSENT', 'P202_Consent'] as $name) {
            self::assertFalse(PageUpdate::fields([$name => '0', 't202c1' => 'a'])['consent'] ?? null, $name);
        }
        self::assertTrue(PageUpdate::fields(['P202_CONSENT' => '1'])['consent'] ?? null);
        self::assertTrue(PageUpdate::fields(['t202c1' => 'a'])['consent'] ?? null, 'absent gives consent');
    }

    /** A page's c1-c4 or keyword quoted back in a server error never reaches the log. */
    public function testAFailureIsLoggedWithoutTheValuesItQuotes(): void
    {
        $failure = new \Prosper202\Database\Exceptions\QueryException(
            "MySQL execute failed: Duplicate entry 'mine\nFORGED log line' for key 'c1' [errno 1062]"
        );
        $text = PageUpdate::failureText($failure);
        self::assertStringNotContainsString('FORGED', $text);
        self::assertStringNotContainsString("\n", $text);
        self::assertStringContainsString("Duplicate entry ? for key 'c1'", $text);
        self::assertStringNotContainsString('mine', PageUpdate::failureText(new \RuntimeException("bad value 'mine'")));

        $root = dirname(__DIR__, 2);
        foreach (['202-config/Click/PageUpdate.php', 'tracking202/static/dcs-update.php'] as $path) {
            $source = (string) file_get_contents($root . '/' . $path);
            self::assertDoesNotMatchRegularExpression('/error_log\([^;]*getMessage\(\)/s', $source, $path);
        }
    }
}

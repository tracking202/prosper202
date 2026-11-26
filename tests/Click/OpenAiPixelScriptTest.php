<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\OpenAiBrowserRef;
use Prosper202\Click\OpenAiPixelScript;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;
use Prosper202\Database\Connection;
use Prosper202\Setup\OpenAiPixelSettings;
use Tests\Support\FakeMysqliConnection;

/**
 * What record_simple.php answers for the ChatGPT Ads pixel
 * (OpenAiPixelScript::forClick()), what a rotator passes on to the page
 * (opprefForLandingPage()), and what openai-ref.php takes from the page
 * (OpenAiBrowserRef::parse()). The integration test reads the same against
 * a real database across two accounts.
 */
final class OpenAiPixelScriptTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const PAGE = 'FROM `202_landing_page_openai_pixel` AS op';
    private const PIXEL = 'FROM 202_ppc_account_pixels AS p';
    private const REFUSAL = 'FROM 202_click_consent_refusals';

    protected function setUp(): void
    {
        RecordedClicks::reset();
        RecordedClicks::note(9001);
    }

    protected function tearDown(): void
    {
        RecordedClicks::reset();
    }

    /** @param list<array<string, mixed>> $pixels */
    private function db(bool $on = true, array $pixels = [['pixel_id' => 4, 'pixel_code' => 'px_1']], int $refused = 0): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows(self::PAGE, $on ? [['on_' => 1]] : []);
        $db->whenQueryContainsReturnRows(self::PIXEL, $pixels);
        $db->whenQueryContainsReturnRows(self::REFUSAL, [['refused' => $refused]]);

        return $db;
    }

    /** @return array<string, mixed> */
    private static function settings(string $answer): array
    {
        self::assertSame(1, preg_match('/^\nif \(typeof window\.p202OpenAiPixel === \'function\'\) \{ window\.p202OpenAiPixel\((\{.*\})\); \}\n$/D', $answer, $m), $answer);

        return json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
    }

    private static function answer(FakeMysqliConnection $db, bool $mayTrack = true, ?string $key = self::KEY, int $clickId = 9001): string
    {
        return OpenAiPixelScript::forClick(new Connection($db), 7, 41, 3, $clickId, $mayTrack, static fn (): ?string => $key, 1_700_000_000);
    }

    public function testThePixelIdAndAProofForTheClicksPageAreHandedToTheHook(): void
    {
        $db = $this->db();
        $settings = self::settings(self::answer($db));

        self::assertSame('px_1', $settings['pixelId']);
        $claims = PurLinkProof::verify($settings['refProof'], PurLinkProof::BROWSER_REF, 1_700_000_001, self::KEY);
        self::assertSame([9001, 41, 7], [$claims['click_id'] ?? null, $claims['landing_page_id'] ?? null, $claims['user_id'] ?? null]);
        self::assertNull(PurLinkProof::verify($settings['refProof'], PurLinkProof::UPDATE, 1_700_000_001, self::KEY), 'for this purpose only');
        // The page and the pixel are read as the account's own.
        self::assertSame([7, 41], $db->statementsContaining(self::PAGE)[0]->boundValues);
        self::assertStringContainsString('lp.user_id = ?', $db->statementsContaining(self::PAGE)[0]->sql);
        self::assertStringContainsString('lp.landing_page_type = 0', $db->statementsContaining(self::PAGE)[0]->sql);
        self::assertSame([7, 3, 7], $db->statementsContaining(self::PIXEL)[0]->boundValues);
    }

    public function testAnOpenAiCodeIsReadForItsIdAndACodeWithoutOneIsSkipped(): void
    {
        $settings = self::settings(self::answer($this->db(true, [
            ['pixel_id' => 4, 'pixel_code' => 'not an id'],
            ['pixel_id' => 5, 'pixel_code' => 'oaiq("init", {pixelId: "px_2"})'],
        ])));
        self::assertSame('px_2', $settings['pixelId']);
    }

    public function testWithoutTheKeyThePixelLoadsAndNoReferenceIsKept(): void
    {
        self::assertSame(['pixelId' => 'px_1'], self::settings(self::answer($this->db(), true, null)));
    }

    public function testNothingIsAnsweredWhenTheVisitorOrThePageSaysNo(): void
    {
        self::assertSame('', self::answer($this->db(), false), 'a visitor who may not be tracked');
        self::assertSame('', self::answer($this->db(false)), 'the switch is off, or the page is not a simple page of the account');
        self::assertSame('', self::answer($this->db(true, [])), 'no ChatGPT Ads Pixel on the source');
        self::assertSame('', self::answer($this->db(true, [['pixel_id' => 4, 'pixel_code' => 'x y']])), 'no pixel id');
        self::assertSame('', self::answer($this->db(true, refused: 1)), 'a request of the click refused consent');
        self::assertSame('', OpenAiPixelScript::forClick(new Connection($this->db()), 7, 41, 0, 9001, true, static fn (): ?string => self::KEY), 'no source');
    }

    public function testAFailedReadAnswersNothing(): void
    {
        $db = $this->db();
        $db->whenQueryContainsExecuteReturns(self::PIXEL, false);
        self::assertSame('', self::answer($db));
    }

    public function testTheRotatorPassesOpprefOnOnlyToAPageWithThePixelOn(): void
    {
        $conn = new Connection($this->db());
        self::assertSame('oppref=a%26b%3Dc&', OpenAiPixelScript::opprefForLandingPage($conn, 7, 41, ['oppref' => ' a&b=c ']));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage($conn, 7, 41, ['oppref' => 'x', 'p202_consent' => '0']));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage($conn, 7, 41, ['oppref' => ['x']]));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage($conn, 7, 41, []));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage($conn, 7, 0, ['oppref' => 'x']));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage(new Connection($this->db(false)), 7, 41, ['oppref' => 'x']));
        $failing = $this->db();
        $failing->whenQueryContainsExecuteReturns(self::PAGE, false);
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage(new Connection($failing), 7, 41, ['oppref' => 'x']));
    }

    public function testThePagesPostIsReadStrictly(): void
    {
        self::assertSame(['proof' => 'p', 'obref' => 'ob1'], OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => 'ob1']));
        self::assertSame(['proof' => 'p', 'obref' => 'ob1'], OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => 'ob1', 'p202_consent' => '1']));
        // A withdrawal removes the reference, whatever else came.
        self::assertSame(['proof' => 'p', 'obref' => null], OpenAiBrowserRef::parse(['proof' => 'p', 'p202_consent' => '0']));
        self::assertSame(['proof' => 'p', 'obref' => null], OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => 'ob1', 'P202_CONSENT' => 'no']));
        self::assertSame('no proof', OpenAiBrowserRef::parse(['obref' => 'ob1']));
        self::assertSame('no proof', OpenAiBrowserRef::parse(['proof' => ['x'], 'obref' => 'ob1']));
        self::assertSame('no obref', OpenAiBrowserRef::parse(['proof' => 'p']));
        self::assertStringContainsString('not a cookie value', (string) OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => 'a b']));
        self::assertStringContainsString('not a cookie value', (string) OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => str_repeat('a', 256)]));
        self::assertSame('a field was sent twice', OpenAiBrowserRef::parse(['proof' => 'p', 'PROOF' => 'q', 'obref' => 'ob1']));
        self::assertSame('fields a page does not send: c1, ?', OpenAiBrowserRef::parse(['proof' => 'p', 'obref' => 'ob1', 'c1' => 'x', 'a b' => 'y']));
    }

    public function testTheSwitchIsReadFromTheFormStrictly(): void
    {
        $errors = [];
        self::assertSame(['status' => 1], OpenAiPixelSettings::fromForm(['openai_pixel_status' => '1'], $errors));
        self::assertSame(['status' => 1], OpenAiPixelSettings::fromForm(['openai_pixel_status' => 'on'], $errors));
        self::assertSame(['status' => 0], OpenAiPixelSettings::fromForm(['openai_pixel_status' => '0'], $errors));
        self::assertSame(['status' => 0], OpenAiPixelSettings::fromForm([], $errors));
        self::assertSame([], $errors);
        self::assertSame(['status' => 0], OpenAiPixelSettings::fromForm(['openai_pixel_status' => 'yes'], $errors));
        self::assertArrayHasKey('openai_pixel_status', $errors);
        $errors = [];
        OpenAiPixelSettings::fromForm(['openai_pixel_status' => ['1']], $errors);
        self::assertArrayHasKey('openai_pixel_status', $errors);
    }
}

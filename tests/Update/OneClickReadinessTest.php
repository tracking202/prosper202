<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Prosper202\Update\OneClickReadiness;
use Prosper202\Update\ReleaseSignature;

/**
 * The 1-click upgraders say before the button is pressed when they cannot
 * run on this server, with the reason and what changes it; and after a
 * failed download, whether trying again can help.
 */
final class OneClickReadinessTest extends TestCase
{
    private const READY = ['sodium' => true, 'key' => true, 'zip' => true, 'curl' => true];

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-one-click-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/202-config', 0755, true);
        touch($this->dir . '/202-config/version.php');
    }

    protected function tearDown(): void
    {
        chmod($this->dir . '/202-config', 0755);
        unlink($this->dir . '/202-config/version.php');
        rmdir($this->dir . '/202-config');
        rmdir($this->dir);
    }

    public function testAServerThatHasEverythingIsReady(): void
    {
        self::assertSame([], OneClickReadiness::blockers($this->dir, self::READY));
    }

    /** @return iterable<string, array{array<string, bool>, string, string}> */
    public static function missing(): iterable
    {
        yield 'no sodium' => [
            ['sodium' => false, 'key' => false],
            'no sodium extension',
            "enable PHP's sodium extension",
        ];
        yield 'no release key' => [['key' => false], 'no release signing key', 'upgrade by hand'];
        yield 'no zip' => [['zip' => false], 'no zip extension', "enable PHP's zip extension"];
        yield 'no curl' => [['curl' => false], 'no curl extension', "enable PHP's curl extension"];
    }

    /**
     * @dataProvider missing
     * @param array<string, bool> $lacks
     */
    public function testEachThingMissingIsNamedWithWhatChangesIt(array $lacks, string $what, string $fix): void
    {
        $blockers = OneClickReadiness::blockers($this->dir, $lacks + self::READY);

        self::assertCount(1, $blockers);
        self::assertStringContainsString($what, $blockers[0]['what']);
        self::assertStringContainsString($fix, $blockers[0]['fix']);
    }

    public function testFilesPhpMayNotReplaceAreNamed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root may write anywhere');
        }
        chmod($this->dir . '/202-config', 0555);

        $blockers = OneClickReadiness::blockers($this->dir, self::READY);

        self::assertCount(1, $blockers);
        self::assertStringContainsString('PHP may not write to 202-config,', $blockers[0]['what']);
    }

    public function testTheServersOwnAnswerNamesTheKeyThisReleaseShips(): void
    {
        $key = (string) base64_decode(ReleaseSignature::PUBLIC_KEY, true);
        $shipsAKey = strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
        $named = array_filter(
            OneClickReadiness::blockers($this->dir),
            static fn (array $b): bool => str_contains($b['what'], 'no release signing key')
        );

        self::assertSame(!$shipsAKey, $named !== [], 'asked of the real release key when none is given');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function problems(): iterable
    {
        $hop = static fn (int $status, string $body = ''): callable
            => static fn (): array => [$status, $body, null];
        $download = static fn (callable $hop): string
            => (string) ReleaseSignature::download('https://my.tracking202.com/dl', 5, $hop)['error'];

        yield 'no answer' => [$download(static fn (): ?array => null), true];
        yield 'a 503' => [$download($hop(503)), true];
        yield 'a 429' => [$download($hop(429)), true];
        yield 'a 403 with a reason' => [$download($hop(403, '{"msg":"This key has no licence"}')), false];
        yield 'a 404' => [$download($hop(404)), false];
        yield 'a redirect off the host' => [
            $download(static fn (): array => [302, '', 'https://elsewhere.example/dl']),
            false,
        ];
        yield 'another host' => [(string) ReleaseSignature::download('https://elsewhere.example/dl')['error'], false];
        yield 'timed out' => [(string) ReleaseSignature::download('https://my.tracking202.com/dl', 0)['error'], true];
        $anyKey = base64_encode(str_repeat('k', 32));
        yield 'no signature' => [(string) ReleaseSignature::problem('zip', null, $anyKey), false];
    }

    /** @dataProvider problems */
    public function testAfterAFailedDownloadThePageSaysWhetherToTryAgain(string $problem, bool $retry): void
    {
        self::assertNotSame('', $problem);
        self::assertSame($retry, ReleaseSignature::worthRetrying($problem), $problem);
        self::assertStringContainsString(
            $retry ? 'Try again in a few minutes' : 'Trying again will not change this',
            OneClickReadiness::afterProblem($problem)
        );
    }

    public function testAnEmptyDownloadIsWorthAnotherTry(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $problem = ReleaseSignature::problem(
            '',
            base64_encode(sodium_crypto_sign_detached('x', sodium_crypto_sign_secretkey($pair))),
            base64_encode(sodium_crypto_sign_publickey($pair))
        );

        self::assertSame('The download was empty, so nothing was installed.', $problem);
        self::assertTrue(ReleaseSignature::worthRetrying((string) $problem));
    }

    public function testBothOneClickPagesAskBeforeTheButtonAndBeforeTheDownload(): void
    {
        foreach (['202-account/auto-upgrade.php', '202-account/auto-upgrade-premium.php'] as $page) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $page);
            $asked = strpos($source, 'OneClickReadiness::blockers(');
            $download = strpos($source, 'ReleaseSignature::download(');
            self::assertNotFalse($asked, $page);
            self::assertLessThan($download, $asked, "$page asks before it downloads");
            self::assertStringContainsString('p202_one_click_blocked($blockers)', $source, $page);
        }
    }

    public function testTheBlockedNoticeGivesEachReasonAndTheUpgradeByHand(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        $html = p202_one_click_blocked([['what' => 'No <zip>.', 'fix' => 'Ask your host.']]);

        self::assertStringContainsString('<li>No &lt;zip&gt;. Ask your host.</li>', $html);
        self::assertStringContainsString('The 1-click upgrade cannot run on this server.', $html);
        self::assertStringContainsString('href="' . OneClickReadiness::MANUAL_DOWNLOAD_URL . '"', $html);
        self::assertStringNotContainsString('id="upgrade-submit"', $html);
    }
}

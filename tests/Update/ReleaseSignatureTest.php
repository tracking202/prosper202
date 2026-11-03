<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Prosper202\Update\ReleaseSignature;

/**
 * The 1-click upgraders install only a zip signed by the release key, fetched
 * over HTTPS from my.tracking202.com. Keys here are made in the test.
 */
final class ReleaseSignatureTest extends TestCase
{
    private const string ZIP = "PK\x03\x04 a release zip's bytes";

    /** @var list<string> */
    private array $zips = [];
    private string $public;
    private string $secret;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
    }

    private function signature(string $zip): string
    {
        return ReleaseSignature::sign($zip, $this->secret, $this->public);
    }

    public function testAValidSignatureIsAccepted(): void
    {
        self::assertNull(ReleaseSignature::problem(self::ZIP, $this->signature(self::ZIP), $this->public));
        // A .sig file's trailing newline is fine.
        self::assertNull(ReleaseSignature::problem(self::ZIP, $this->signature(self::ZIP) . "\n", $this->public));
    }

    public function testATamperedZipIsRefused(): void
    {
        $signature = $this->signature(self::ZIP);
        $problem = ReleaseSignature::problem(self::ZIP . 'x', $signature, $this->public);
        self::assertSame('The download is not signed by Tracking202, so it was not installed.', $problem);
    }

    public function testASignatureByAnotherKeyIsRefused(): void
    {
        $other = sodium_crypto_sign_keypair();
        $signature = base64_encode(sodium_crypto_sign_detached(self::ZIP, sodium_crypto_sign_secretkey($other)));
        self::assertSame(
            'The download is not signed by Tracking202, so it was not installed.',
            ReleaseSignature::problem(self::ZIP, $signature, $this->public)
        );
    }

    public function testAMissingOrMalformedSignatureIsRefused(): void
    {
        $problem = fn (?string $signature): string
            => (string)ReleaseSignature::problem(self::ZIP, $signature, $this->public);
        self::assertStringContainsString('sent no signature', $problem(null));
        self::assertStringContainsString('sent no signature', $problem('  '));
        self::assertStringContainsString('malformed', $problem('not base64!'));
        self::assertStringContainsString('malformed', $problem(base64_encode('short')));
    }

    public function testAnEmptyDownloadIsRefused(): void
    {
        self::assertNotNull(ReleaseSignature::problem('', $this->signature('x'), $this->public));
    }

    public function testThePlaceholderKeyRefusesEverything(): void
    {
        if (strlen((string)base64_decode(ReleaseSignature::PUBLIC_KEY, true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            self::markTestSkipped('the release public key is set');
        }
        $problem = ReleaseSignature::problem(self::ZIP, $this->signature(self::ZIP));
        self::assertSame(
            'This install has no release signing key to check the download with. Upgrade by hand.',
            $problem
        );
    }

    public function testTheShippedKeyIsThePlaceholderOrARealKey(): void
    {
        $raw = base64_decode(ReleaseSignature::PUBLIC_KEY, true);
        self::assertTrue(
            str_starts_with(ReleaseSignature::PUBLIC_KEY, 'PLACEHOLDER')
                || (is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES),
            'ReleaseSignature::PUBLIC_KEY must be the placeholder or a base64 32-byte Ed25519 key'
        );
    }

    public function testSigningRefusesAKeyInstallsDoNotCheckAgainst(): void
    {
        $other = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        ReleaseSignature::sign(self::ZIP, $this->secret, $other);
    }

    public function testSigningRefusesAMalformedSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        ReleaseSignature::sign(self::ZIP, 'nope', $this->public);
    }

    public function testOnlyHttpsOnTheServiceHostIsAllowed(): void
    {
        self::assertNotNull(ReleaseSignature::allowedUrl('https://my.tracking202.com/api/v2/premium-p202/version'));
        self::assertNotNull(ReleaseSignature::allowedUrl('HTTPS://MY.tracking202.com/x'));
        $refused = [
            null,
            '',
            'http://my.tracking202.com/x',
            'https://evil.example/x',
            'https://my.tracking202.com.evil.example/x',
            'https://my.tracking202.com:8443/x',
            'https://user:pw@my.tracking202.com/x',
            'ftp://my.tracking202.com/x',
            '/relative',
        ];
        foreach ($refused as $url) {
            self::assertNull(ReleaseSignature::allowedUrl($url), (string)json_encode($url));
        }
    }

    public function testARedirectOffTheHostIsRefused(): void
    {
        $asked = [];
        $hop = function (string $url) use (&$asked): array {
            $asked[] = $url;
            return [302, '', 'https://evil.example/prosper202.zip'];
        };
        $result = ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop);
        self::assertNull($result['body']);
        self::assertStringContainsString(
            'Refused a redirect to https://evil.example/prosper202.zip',
            (string)$result['error']
        );
        self::assertSame(['https://my.tracking202.com/dl'], $asked, 'the off-host target is never requested');
    }

    public function testARedirectToPlainHttpIsRefused(): void
    {
        $hop = static fn (): array => [301, '', 'http://my.tracking202.com/dl'];
        $result = ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop);
        self::assertNull($result['body']);
        self::assertStringContainsString('Refused a redirect', (string)$result['error']);
    }

    public function testARedirectWithNoTargetIsRefused(): void
    {
        $hop = static fn (): array => [302, '', null];
        self::assertNull(ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop)['body']);
    }

    public function testARedirectOnTheHostIsFollowed(): void
    {
        $hop = static fn (string $url): array => $url === 'https://my.tracking202.com/dl'
            ? [302, '', 'https://my.tracking202.com/files/prosper202.zip']
            : [200, self::ZIP, null];
        $result = ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop);
        self::assertSame(['body' => self::ZIP, 'error' => null], $result);
    }

    public function testRedirectsAreBounded(): void
    {
        $calls = 0;
        $hop = function () use (&$calls): array {
            $calls++;
            return [302, '', 'https://my.tracking202.com/again'];
        };
        $result = ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop);
        self::assertNull($result['body']);
        self::assertSame(ReleaseSignature::MAX_REDIRECTS + 1, $calls);
    }

    public function testAStartingUrlOffTheHostIsNeverRequested(): void
    {
        $hop = function (): array {
            self::fail('requested');
        };
        $result = ReleaseSignature::download('http://my.tracking202.com/dl', 10, $hop);
        self::assertNull($result['body']);
        self::assertStringContainsString('Refused to download', (string)$result['error']);
    }

    public function testNoAnswerOrAnErrorStatusGivesNoBody(): void
    {
        $url = 'https://my.tracking202.com/dl';
        self::assertNull(ReleaseSignature::download($url, 10, static fn (): ?array => null)['body']);
        $result = ReleaseSignature::download($url, 10, static fn (): array => [503, 'busy', null]);
        self::assertNull($result['body']);
        self::assertSame('The update server answered HTTP 503.', $result['error']);
    }

    /** Each upgrader checks the signature before it writes the zip anywhere. */
    public function testEveryUpgraderChecksTheSignatureBeforeWriting(): void
    {
        $root = dirname(__DIR__, 2);
        $upgraders = [
            '202-account/auto-upgrade.php',
            '202-account/auto-upgrade-premium.php',
            '202-config/functions.php',
        ];
        foreach ($upgraders as $file) {
            $source = self::codeOnly((string)file_get_contents($root . '/' . $file));
            if ($file === '202-config/functions.php') {
                $start = strpos($source, 'function update_needed(');
                self::assertNotFalse($start);
                $end = strpos($source, "\n\tfunction ", $start + 1);
                $source = substr($source, $start, $end === false ? null : $end - $start);
            }
            $ready = strpos($source, 'ReleaseSignature::notReady(');
            $download = strpos($source, 'ReleaseSignature::download(');
            $check = strpos($source, 'ReleaseSignature::problem(');
            $write = strpos($source, 'file_put_contents(');
            self::assertNotFalse($ready, $file . ' checks for a key before downloading');
            self::assertLessThan($download, $ready, $file);
            self::assertSame(1, substr_count($source, 'file_put_contents('), $file . ' writes the zip in one place');
            self::assertSame(1, substr_count($source, 'ReleaseSignature::download('), $file);
            self::assertNotFalse($download, $file . ' downloads through ReleaseSignature::download()');
            self::assertNotFalse($check, $file . ' checks the signature');
            self::assertNotFalse($write, $file);
            self::assertLessThan($write, $download, $file);
            self::assertLessThan($write, $check, $file . ' checks the signature before writing');
            $versionCheck = strpos($source, 'ReleaseSignature::versionProblem(');
            $open = max((int)strpos($source, 'zip_open('), (int)strpos($source, '$zip->open('));
            self::assertNotFalse($versionCheck, $file . ' checks the release version');
            self::assertGreaterThan(0, $open, $file);
            self::assertLessThan($open, $versionCheck, $file . ' checks the version before extracting');
            self::assertStringNotContainsString('getData($link)', $source, $file);
            self::assertStringNotContainsString('getData($download_link)', $source, $file);
        }
    }

    public function testTheSigningScriptRefusesToSignWithoutTheSecret(): void
    {
        [$code, $output] = $this->runScript(['sign', __FILE__], '');
        self::assertSame(1, $code);
        self::assertStringContainsString('P202_RELEASE_SIGNING_KEY secret is not set', $output);
        self::assertFileDoesNotExist(__FILE__ . '.sig');
    }

    public function testTheSigningScriptRefusesAKeyThatIsNotTheShippedOne(): void
    {
        [$code, $output] = $this->runScript(['sign', __FILE__], $this->secret);
        self::assertSame(1, $code);
        self::assertStringContainsString('does not match ReleaseSignature::PUBLIC_KEY', $output);
        self::assertFileDoesNotExist(__FILE__ . '.sig');
    }

    public function testKeygenWritesASecretThatSignsForThePrintedKey(): void
    {
        $dir = sys_get_temp_dir() . '/p202-sign-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $keyFile = $dir . '/release.key';
        try {
            [$code, $output] = $this->runScript(['keygen', $keyFile], '');
            self::assertSame(0, $code, $output);
            self::assertSame(0600, fileperms($keyFile) & 0777);
            $public = trim($output);
            $signature = ReleaseSignature::sign(self::ZIP, (string)file_get_contents($keyFile), $public);
            self::assertNull(ReleaseSignature::problem(self::ZIP, $signature, $public));

            [$again] = $this->runScript(['keygen', $keyFile], '');
            self::assertSame(1, $again, 'an existing key file is never overwritten');
        } finally {
            @unlink($keyFile);
            @rmdir($dir);
        }
    }

    private function zipWith(?string $versionPhp): string
    {
        $file = tempnam(sys_get_temp_dir(), 'p202-zip');
        self::assertIsString($file);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::OVERWRITE));
        $zip->addFromString('index.php', '<?php');
        if ($versionPhp !== null) {
            $zip->addFromString('202-config/version.php', $versionPhp);
        }
        self::assertTrue($zip->close());
        $this->zips[] = $file;
        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $file) {
            @unlink($file);
        }
    }

    public function testOnlyTheOfferedVersionNewerThanTheInstallIsExtracted(): void
    {
        $zip = $this->zipWith("<?php\n\$version_string = '1.9.81';\nif (true) {}\n");
        self::assertSame('1.9.81', ReleaseSignature::zipVersion($zip));
        self::assertNull(ReleaseSignature::versionProblem($zip, '1.9.81', '1.9.80'));
        $problem = static fn (string $offered, string $installed): string
            => (string)ReleaseSignature::versionProblem($zip, $offered, $installed);
        self::assertStringContainsString('not 1.9.90 as offered', $problem('1.9.90', '1.9.80'));
        self::assertStringContainsString('not newer', $problem('1.9.81', '1.9.81'));
        self::assertStringContainsString('not newer', $problem('1.9.81', '1.9.85'));
    }

    public function testAReleaseThatDoesNotNameItsVersionIsNotExtracted(): void
    {
        $expected = 'The release does not say which version it is, so it was not installed.';
        self::assertSame($expected, ReleaseSignature::versionProblem($this->zipWith(null), '1.9.81', '1.9.80'));
        $odd = $this->zipWith("<?php\n\$version_string = 'latest';\n");
        self::assertSame($expected, ReleaseSignature::versionProblem($odd, '1.9.81', '1.9.80'));
        self::assertSame($expected, ReleaseSignature::versionProblem(__FILE__, '1.9.81', '1.9.80'));
    }

    public function testTheShippedVersionFileIsReadable(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/version.php');
        $zip = $this->zipWith($source);
        self::assertSame(1, preg_match("/'(\\d+\\.\\d+\\.\\d+[^']*)'/", $source, $m));
        self::assertSame($m[1], ReleaseSignature::zipVersion($zip));
    }

    public function testARefusalKeepsThePortalsReason(): void
    {
        $hop = static fn (): array => [403, '{"code":403,"msg":"This key has no <b>licence</b>"}', null];
        $result = ReleaseSignature::download('https://my.tracking202.com/dl', 10, $hop);
        self::assertSame('The update server answered HTTP 403: This key has no licence.', $result['error']);
    }

    /** The source without comments, so a mention in a comment cannot stand in for a call. */
    private static function codeOnly(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    public function testNotReadyNamesTheMissingKeyBeforeAnyDownload(): void
    {
        self::assertNull(ReleaseSignature::notReady($this->public));
        self::assertStringContainsString('no release signing key', (string)ReleaseSignature::notReady('PLACEHOLDER'));
    }

    /** The real transport: curl is held to HTTPS, so a plain-HTTP URL never leaves the machine. */
    public function testTheCurlTransportRefusesPlainHttp(): void
    {
        $hop = new \ReflectionMethod(ReleaseSignature::class, 'curlHop');
        $log = tempnam(sys_get_temp_dir(), 'p202-sig-log');
        self::assertIsString($log);
        $errorLog = ini_set('error_log', $log);
        try {
            self::assertNull($hop->invoke(null, 'http://127.0.0.1:9/prosper202.zip', 5));
            self::assertMatchesRegularExpression('/protocol/i', (string)file_get_contents($log));
        } finally {
            ini_set('error_log', (string)$errorLog);
            @unlink($log);
        }
    }

    /** @return array{int, string} */
    private function runScript(array $args, string $secret): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/build/scripts/sign-release.php'], $args);
        $env = ['PATH' => (string)getenv('PATH'), 'P202_RELEASE_SIGNING_KEY' => $secret];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string)$output];
    }
}

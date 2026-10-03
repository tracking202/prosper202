<?php

declare(strict_types=1);

namespace Tests\Standalone;

use PHPUnit\Framework\TestCase;

/**
 * No request this install makes turns off TLS verification (#169).
 *
 * 202-resources/index.php and clickserver_api_key_validate() fetched from
 * my.tracking202.com with CURLOPT_SSL_VERIFYPEER false, and the sweep found
 * 27 more calls to the same host doing the same — while getData() and
 * ClickServerKeyValidator already verified the same host's certificate, so
 * nothing needed it off. A feed or an API answer a spoofed server can write
 * is the threat the U7 escaping work was closing, and verification is what
 * keeps the server from being spoofed. This reads every PHP file outside
 * vendor/ and tests/ for a peer check turned off, a host check below 2, or a
 * stream context that skips either.
 */
final class CurlVerifiesTlsTest extends TestCase
{
    private const OFF = [
        '/CURLOPT_SSL_VERIFYPEER(?:,|=>)(?:false|0|null|\'\'|"")\b/i' => 'CURLOPT_SSL_VERIFYPEER off',
        '/CURLOPT_SSL_VERIFYHOST(?:,|=>)(?:false|0|1|null)\b/i' => 'CURLOPT_SSL_VERIFYHOST below 2',
        '/[\'"]verify_peer(?:_name)?[\'"]=>(?:false|0)\b/i' => 'a stream context with verification off',
        '/CURLOPT_SSL_VERIFYPEER(?:,|=>)\$/' => 'CURLOPT_SSL_VERIFYPEER set from a variable (cannot be read)',
    ];

    public function testNoRequestTurnsVerificationOff(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = 0;
        $calls = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|node_modules|\.git|\.claude)/#', $relative) === 1) {
                continue;
            }
            $files++;
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                if (!$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    $code .= $token->text;
                }
            }
            $calls += substr_count($code, 'CURLOPT_SSL_VERIFYPEER');
            foreach (self::OFF as $pattern => $what) {
                if (preg_match_all($pattern, $code) > 0) {
                    $found[] = "$relative: $what";
                }
            }
        }
        self::assertGreaterThan(200, $files, 'the scan read the tree');
        self::assertGreaterThan(30, $calls, 'and found the requests that set a peer check');
        self::assertSame([], $found, 'TLS verification is off here; my.tracking202.com and every other host this install calls verify');
    }
}

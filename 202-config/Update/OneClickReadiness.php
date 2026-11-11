<?php

declare(strict_types=1);

namespace Prosper202\Update;

/**
 * Whether the 1-click upgraders can run on this server, asked before the
 * button is shown: each reason they cannot, and what changes it.
 */
final class OneClickReadiness
{
    public const string MANUAL_DOWNLOAD_URL = 'https://my.tracking202.com/clickserver/download/latest/paid';

    /**
     * @param array{sodium?: bool, key?: bool, zip?: bool, curl?: bool} $has what
     *        the server has, for a test; the default asks it
     * @return list<array{what: string, fix: string}> empty when the upgrade can run
     */
    public static function blockers(string $installDir, array $has = []): array
    {
        $sodium = $has['sodium'] ?? function_exists('sodium_crypto_sign_verify_detached');
        $has += [
            'key' => $sodium && ReleaseSignature::notReady() === null,
            'zip' => function_exists('zip_open') && class_exists(\ZipArchive::class),
            'curl' => function_exists('curl_init'),
        ];
        $blockers = [];
        if (!$sodium) {
            $blockers[] = [
                'what' => "This server's PHP has no sodium extension, so a download cannot be checked before it"
                    . ' is installed.',
                'fix' => "Ask your host to enable PHP's sodium extension.",
            ];
        } elseif (!$has['key']) {
            $blockers[] = [
                'what' => 'This Prosper202 release has no release signing key to check a download with.',
                'fix' => 'Nothing on this server changes that: upgrade by hand this time.',
            ];
        }
        if (!$has['zip']) {
            $blockers[] = [
                'what' => "This server's PHP has no zip extension, so a download cannot be unpacked.",
                'fix' => "Ask your host to enable PHP's zip extension.",
            ];
        }
        if (!$has['curl']) {
            $blockers[] = [
                'what' => "This server's PHP has no curl extension, so the release cannot be downloaded.",
                'fix' => "Ask your host to enable PHP's curl extension.",
            ];
        }
        $installDir = rtrim($installDir, '/');
        foreach (['', '202-config', '202-config/version.php'] as $path) {
            if (!is_writable($installDir . '/' . $path)) {
                $blockers[] = [
                    'what' => 'PHP may not write to ' . ($path === '' ? 'the install directory' : $path)
                        . ', so the files cannot be replaced.',
                    'fix' => "Ask your host to let the web server's user write to the install's files and"
                        . ' directories.',
                ];
                break;
            }
        }

        return $blockers;
    }

    /** What to do after a download or its check answered $problem (ReleaseSignature's words). */
    public static function afterProblem(string $problem): string
    {
        return ReleaseSignature::worthRetrying($problem)
            ? 'Try again in a few minutes. If it fails the same way, check that this server can reach https://'
                . ReleaseSignature::HOST . ' over HTTPS, or upgrade by hand.'
            : 'Trying again will not change this: upgrade by hand, and tell support what this page said.';
    }
}

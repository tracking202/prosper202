<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Nothing in the install calls the hosted daily email service, which
 * Tracking202 switched off on 2026-10-29: its endpoints (the registration
 * GET /api/v2/{hash}/{domain}/{time}, push-daily-email-subscribers,
 * send-daily-email and send-daily-email-lite) answer 410 and store nothing.
 *
 * Personal Settings registered the owner's hour on every save, the installer
 * at 07, the 1.9.6 upgrade step once, and 202-cronjobs/daily-email.php posted
 * the owner's email and campaigns when the service fetched it. The
 * user_daily_email column and the API's and CLI's preference stay, so an
 * existing script that sets it is not refused; nothing reads it.
 */
final class HostedDailyEmailIsNotCalledTest extends TestCase
{
    /** Names only the daily email used: the endpoints and the helpers that called them. */
    private const NAMES = [
        'send-daily-email',
        'push-daily-email-subscribers',
        'registerDailyEmail',
        'p202_account_register_daily_email',
        'DailyEmailCampaigns',
    ];

    /** The registration was the bare v2 root with the hash appended; no other call uses it. */
    private const REGISTRATION = '~my\.tracking202\.com/api/v2/[\'"]\s*\.~';

    public function testNoSourceNamesTheDailyEmailServiceOrItsHelpers(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey('202-account/account.php', $files, 'the scan read the tree');

        $found = [];
        foreach ($files as $path => $source) {
            foreach (self::NAMES as $name) {
                if (stripos($source, $name) !== false) {
                    $found[] = "$path: $name";
                }
            }
            if (preg_match(self::REGISTRATION, $source) === 1) {
                $found[] = "$path: a call to the bare api/v2/ root (the daily email registration)";
            }
        }
        self::assertSame([], $found, 'the hosted daily email answers 410 since 2026-10-29; nothing calls it');
    }

    public function testTheRegistrationPatternMatchesTheCallItReplaced(): void
    {
        $removed = "curl_setopt(\$ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/' . \$hash . '/' . \$domain"
            . " . '/' . \$set_time);";
        self::assertSame(1, preg_match(self::REGISTRATION, $removed));
        $kept = "curl_setopt(\$ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . \$hash"
            . " . '/cache/progress');";
        self::assertSame(0, preg_match(self::REGISTRATION, $kept), 'another v2 call is not the registration');
    }

    /**
     * Personal Settings neither shows the setting nor writes it: a save
     * leaves the stored hour as it was, and nothing registers it.
     */
    public function testPersonalSettingsHasNoDailyEmailField(): void
    {
        $page = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/account.php');
        self::assertStringContainsString('name="user_timezone"', $page, 'the profile form is in this file');
        self::assertStringNotContainsString('user_daily_email', $page, 'no field, no validation, no write');
        self::assertStringNotContainsString('Daily email report', $page);
        self::assertStringNotContainsString('daily report', $page, 'the email field\'s help names no daily report');
    }

    /**
     * The callback is kept, doing nothing, because an upgrade unpacks over
     * the old files and deletes none: removed, the old one would stay.
     */
    public function testTheOldCallbackDoesNothingButSayItIsGone(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-cronjobs/daily-email.php');
        self::assertStringContainsString('http_response_code(410);', $source);
        self::assertStringNotContainsString('curl', $source);
        self::assertStringNotContainsString('$_GET', $source);
        self::assertDoesNotMatchRegularExpression('/\b(SELECT|query)\b/i', $source, 'it reads nothing');
    }
}

<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The ad setting is read from the user's preferences row, never from a
 * session copy. The home page's Special offers panel read
 * $_SESSION['user_pref_ad_settings'], which only a save of Personal Settings
 * wrote and no sign-in did, so after every sign-in the panel was hidden from
 * everyone, Show All Ads included, and a change made through the API or on
 * another browser never reached a session at all.
 *
 * What this reads is the source: a `$_SESSION` index spelled as a string
 * literal. A key held in a variable first is not seen.
 * AdSettingsIntegrationTest runs the reader against a database.
 */
final class AdSettingIsReadFromTheRowTest extends TestCase
{
    public function testNoServedFileKeepsTheAdSettingInTheSession(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $tokens = token_get_all($source);
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$_SESSION') {
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (($tokens[$j] ?? null) !== '[') {
                    continue;
                }
                $j++;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $key = $tokens[$j] ?? null;
                if (is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING
                    && strcasecmp(trim($key[1], '\'"'), 'user_pref_ad_settings') === 0) {
                    $found[] = $path . ':' . $tokens[$i][2];
                }
            }
        }

        self::assertSame([], $found, 'The ad setting is read with Prosper202\User\AdSettings::forUser(), not kept in the session: ' . implode(', ', $found));
    }

    public function testTheHomePageDecidesSpecialOffersFromTheRow(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/index.php');

        self::assertMatchesRegularExpression(
            '/\$showOffers\s*=\s*\\\\Prosper202\\\\User\\\\AdSettings::showsHomeOffers\(\s*\\\\Prosper202\\\\User\\\\AdSettings::forUser\(/',
            $source,
            'The home page asks AdSettings for the signed-in user\'s stored choice.'
        );
        self::assertSame(2, preg_match_all('/\$showOffers\b/', $source), 'The panel is drawn on that answer alone.');
        self::assertStringContainsString('<?php if ($showOffers) { ?>', $source);
    }
}

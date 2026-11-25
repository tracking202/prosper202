<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\ShownSettings;

/**
 * The version a settings form carries answers for its form and its values
 * alone, under the session's secret, and anything else a request carries
 * reads as no version (SettingsSaveOverNewerVersionIntegrationTest runs it
 * against the page).
 */
final class ShownSettingsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $session = null;

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? null;
        $_SESSION = ['p202_shown_secret' => str_repeat('5a', 32)];
    }

    protected function tearDown(): void
    {
        if ($this->session === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->session;
        }
    }

    /** @param array<string, mixed> $values */
    private static function posted(string $form, array $values): array
    {
        return ['p202_shown' => [$form => ShownSettings::version($form, $values)]];
    }

    public function testTheVersionOfWhatWasShownIsCurrent(): void
    {
        $shown = ['cb_key' => 'abc', 'other' => null];

        self::assertTrue(ShownSettings::isCurrent('cb_key', $shown, self::posted('cb_key', $shown)));
    }

    public function testAChangedValueATypeOrAnotherFormIsNotCurrent(): void
    {
        $post = self::posted('cb_key', ['cb_key' => 'abc']);

        self::assertFalse(ShownSettings::isCurrent('cb_key', ['cb_key' => 'abd'], $post));
        self::assertFalse(ShownSettings::isCurrent('cb_key', ['cb_key' => null], $post), 'null is not ""');
        $null = self::posted('cb_key', ['cb_key' => null]);
        self::assertFalse(ShownSettings::isCurrent('cb_key', ['cb_key' => ''], $null));
        $string = self::posted('cb_key', ['cb_key' => '1']);
        self::assertFalse(ShownSettings::isCurrent('cb_key', ['cb_key' => 1], $string));
        self::assertFalse(ShownSettings::isCurrent('shopify', ['cb_key' => 'abc'], $post), 'one form, one version');
    }

    public function testAVersionUnderAnotherSecretIsNotCurrent(): void
    {
        $post = self::posted('cb_key', ['cb_key' => 'abc']);
        $_SESSION['p202_shown_secret'] = str_repeat('6b', 32);

        self::assertFalse(ShownSettings::isCurrent('cb_key', ['cb_key' => 'abc'], $post));
    }

    public function testAMissingOrMalformedVersionIsNotCurrent(): void
    {
        $shown = ['cb_key' => 'abc'];
        $version = ShownSettings::version('cb_key', $shown);

        $posts = [[], ['p202_shown' => $version], ['p202_shown' => []], ['p202_shown' => ['cb_key' => [$version]]],
            ['p202_shown' => ['cb_key' => '']], ['p202_shown' => ['cb_key' => strtoupper($version)]]];
        foreach ($posts as $post) {
            self::assertFalse(ShownSettings::isCurrent('cb_key', $shown, $post), json_encode($post));
        }
    }

    public function testAFieldNamesItsFormAndCarriesTheVersion(): void
    {
        $field = ShownSettings::field('cb_key', ['cb_key' => 'abc']);

        self::assertSame('<input type="hidden" name="p202_shown[cb_key]" value="'
            . ShownSettings::version('cb_key', ['cb_key' => 'abc']) . '">', $field);
        self::assertStringNotContainsString('abc', $field, 'the page carries a version, never the value');
    }

    public function testASessionWithoutASecretGetsOneAndKeepsIt(): void
    {
        $_SESSION = [];
        $first = ShownSettings::version('cb_key', ['cb_key' => 'abc']);
        $secret = $_SESSION['p202_shown_secret'] ?? null;

        self::assertIsString($secret);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertSame($first, ShownSettings::version('cb_key', ['cb_key' => 'abc']));
    }
}

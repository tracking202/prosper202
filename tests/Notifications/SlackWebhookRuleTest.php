<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Notifications\SlackWebhook;
use Prosper202\User\PreferenceRules;

/**
 * Account › API integrations and PUT /users/{id}/preferences save the same
 * Slack webhook URLs (CLAUDE.md #5): SlackWebhook::accepted(), WebhookGuard's
 * rule without the lookup. The page saved any non-empty string, http:// and
 * internal hosts included, and the API asked only for https://.
 *
 * The page's half is its own save, processApiKeyUpdate() and
 * validateRequired(), read out of the page and run with its database write
 * and its notice stood in for; the API's is PreferenceRules::validate().
 */
final class SlackWebhookRuleTest extends TestCase
{
    private const PAGE = '202-account/api-integrations.php';
    private const COLUMN = 'user_slack_incoming_webhook';

    /** @var list<array{string, string}> the page's writes, [column, value] */
    private static array $writes = [];

    private static string $save = '';

    public static function setUpBeforeClass(): void
    {
        self::$save = self::loadPageSave();
    }

    /** @return array<string, array{string, string|null}> submitted => the value stored, or null when refused */
    public static function urls(): array
    {
        $hook = 'https://hooks.slack.com/services/T0000/B0000/abcdefXYZ';

        return [
            'a Slack webhook' => [$hook, $hook],
            'one with spaces around it' => ['  ' . $hook . "\n", $hook],
            'a webhook on another public host and port' => [
                'https://chat.example.com:8443/hooks/abc',
                'https://chat.example.com:8443/hooks/abc',
            ],
            'plain http' => ['http://hooks.slack.com/services/T0000/B0000/abcdefXYZ', null],
            'a private address' => ['https://10.0.0.5/hooks/abc', null],
            'a private address, 192.168' => ['https://192.168.1.10/hooks/abc', null],
            'loopback' => ['https://127.0.0.1/hooks/abc', null],
            'IPv6 loopback' => ['https://[::1]/hooks/abc', null],
            'link-local, where cloud metadata answers' => ['https://169.254.169.254/latest/meta-data/', null],
            'a single-label name' => ['https://localhost/hooks/abc', null],
            'a number spelling loopback' => ['https://2130706433/hooks/abc', null],
            'a user name before the host' => ['https://user:pass@hooks.slack.com/x', null],
            'a fragment' => ['https://hooks.slack.com/x#y', null],
            'not a URL' => ['hooks', null],
            'a script' => ['javascript:alert(1)', null],
        ];
    }

    /** @dataProvider urls */
    public function testThePageAndTheApiSaveTheSameUrls(string $submitted, ?string $stored): void
    {
        [$apiClean, $apiErrors] = PreferenceRules::validate([self::COLUMN => $submitted], ['USD']);
        [$pageSaved, $pageError] = self::page($submitted);

        if ($stored === null) {
            self::assertArrayHasKey(self::COLUMN, $apiErrors, 'the API refuses it');
            self::assertNull($pageSaved, 'the page refuses it');
            self::assertSame($apiErrors[self::COLUMN], $pageError, 'with the same words');
            self::assertStringNotContainsString('hooks/abc', $pageError, 'the refusal does not repeat the path');
        } else {
            self::assertSame([], $apiErrors);
            self::assertSame([self::COLUMN => $stored], $apiClean, 'the API stores it');
            self::assertSame($stored, $pageSaved, 'the page stores the same value');
        }
    }

    /** "" stops notices through the API; the page asks for a URL, as it always has, and a blank one is not one. */
    public function testAnEmptyUrlClearsThroughTheApiOnly(): void
    {
        self::assertSame([[self::COLUMN => ''], []], PreferenceRules::validate([self::COLUMN => ''], ['USD']));
        self::assertSame([[self::COLUMN => ''], []], PreferenceRules::validate([self::COLUMN => '  '], ['USD']));
        self::assertSame([null, "Slack Incoming Webhook URL can't be empty!"], self::page(''));
        self::assertSame([null, "Slack Incoming Webhook URL can't be empty!"], self::page('   '));
    }

    /** An empty field keeps the saved webhook unasked, one saved before the rule included. */
    public function testAnEmptyFieldKeepsTheSavedWebhook(): void
    {
        $saved = [self::COLUMN => 'http://10.0.0.5/hooks/abc'];
        self::assertSame([null, ''], self::page('', self::COLUMN, $saved));
        self::assertSame([null, ''], self::page('   ', self::COLUMN, $saved));
    }

    /** The operator's allowlist admits their own network, at the save as at the send. */
    public function testTheOperatorsAllowlistAdmitsTheirNetwork(): void
    {
        $own = 'https://10.0.0.5/hooks/abc';
        self::assertSame($own, SlackWebhook::accepted($own, '10.0.0.0/8'));
        $this->expectException(\InvalidArgumentException::class);
        SlackWebhook::accepted('https://169.254.169.254/x', '169.254.0.0/16');
    }

    /** The other keys the page saves are not held to it. */
    public function testOtherKeysAreSavedAsBefore(): void
    {
        [$saved, $error] = self::page('not-a-url-but-a-key', 'cb_key');
        self::assertSame('not-a-url-but-a-key', $saved);
        self::assertSame('', $error);
    }

    public static function write(string $field, mixed $value, mixed $userId, mixed $db): void
    {
        self::$writes[] = [$field, (string) $value];
    }

    public static function notice(mixed ...$args): void
    {
    }

    /**
     * The page's save of one key card, as a POST of $value runs it over
     * $saved: the value it wrote (null when nothing was), and the error it shows.
     *
     * @param array<string, string> $saved
     * @return array{string|null, string}
     */
    private static function page(string $value, string $field = self::COLUMN, array $saved = []): array
    {
        $savedPost = $_POST;
        $savedSession = $_SESSION ?? null;
        $_POST = [$field => $value];
        $_SESSION = ['user_id' => 1];
        self::$writes = [];
        $error = [];
        $changed = false;
        try {
            $config = [
                'post_key' => $field, 'field_name' => $field, 'error_key' => $field,
                'error_message' => $field === self::COLUMN ? "Slack Incoming Webhook URL can't be empty!" : 'empty',
                'slack_event' => $field . '_updated',
            ];
            (self::$save)($config, $error, $changed, $saved, false, 'admin', null);
        } finally {
            $_POST = $savedPost;
            if ($savedSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $savedSession;
            }
        }
        $written = array_values(array_filter(self::$writes, static fn (array $w): bool => $w[0] === $field));

        return [$written === [] ? null : $written[0][1], (string) ($error[$field] ?? '')];
    }

    /**
     * processApiKeyUpdate() and validateRequired() out of the page, renamed,
     * with updateUserPreference() and sendSlackNotification() calls sent here
     * and the classes the page imports by their full names.
     */
    private static function loadPageSave(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PAGE);
        $tokens = \PhpToken::tokenize($source);
        $suffix = '_' . substr(md5($source), 0, 12);
        $rename = [
            'processApiKeyUpdate' => 'p202_slack_rule_save' . $suffix,
            'validateRequired' => 'p202_slack_rule_required' . $suffix,
            'updateUserPreference' => '\\' . self::class . '::write',
            'sendSlackNotification' => '\\' . self::class . '::notice',
        ];
        preg_match_all('/^use\s+([\w\\\\]+);/m', $source, $imports);
        foreach ($imports[1] as $class) {
            $rename[substr((string) strrchr('\\' . $class, '\\'), 1)] = '\\' . $class;
        }
        $code = '';
        foreach (['processApiKeyUpdate', 'validateRequired'] as $name) {
            $body = self::functionTokens($tokens, $name);
            self::assertNotSame([], $body, self::PAGE . " no longer defines $name(); update this test");
            foreach ($body as $t) {
                $code .= $t->is(T_STRING) && isset($rename[$t->text]) ? $rename[$t->text] : $t->text;
            }
            $code .= "\n";
        }
        if (!function_exists($rename['processApiKeyUpdate'])) {
            eval($code);
        }

        return $rename['processApiKeyUpdate'];
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<\PhpToken> from `function` to its closing brace
     */
    private static function functionTokens(array $tokens, string $name): array
    {
        foreach ($tokens as $i => $t) {
            if (!$t->is(T_FUNCTION)) {
                continue;
            }
            $j = $i + 1;
            while ($tokens[$j]->is(T_WHITESPACE)) {
                $j++;
            }
            if (!$tokens[$j]->is(T_STRING) || $tokens[$j]->text !== $name) {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < count($tokens); $k++) {
                $text = $tokens[$k]->text;
                if ($text === '{' || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return array_slice($tokens, $i, $k - $i + 1);
                    }
                }
            }
        }

        return [];
    }
}

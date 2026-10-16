<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;

/**
 * A key typed into the account page is saved only on the licence service's
 * own answer for that key: AUTH::is_valid_api_key() answers from the session
 * once the signed-in key has validated, and lets an unanswered check
 * through, so the page saved any key typed after the first.
 */
final class TypedKeyIsCheckedTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    public function testOnlyAValidAnswerSaves(): void
    {
        self::assertNull(p202_typed_key_error(['valid' => true, 'paid' => true]));
        self::assertNull(p202_typed_key_error(['valid' => true, 'paid' => null]));
        self::assertSame('This API Key appears invalid.', p202_typed_key_error(['valid' => false, 'paid' => null]));
        self::assertSame(P202_ACCOUNT_KEY_UNCHECKED, p202_typed_key_error(null), 'no answer saves nothing');
    }

    public function testTheKeyChangesAskAboutTheTypedKey(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/202-account/account.php');
        foreach (
            [
                'change_user_api_key' => '/if \(\(\$typedKeyError = p202_check_typed_key\('
                    . '\$typedKey\)\) '
                    . '!== null\) \{\s*\$keyErrors\[\'user_api_key\'\] = \$typedKeyError;'
                    . '\s*\}\s*if \(!\$keyErrors\) \{/',
                'update_p202_customer_api_key' => '/if \(\(\$customerKeyError = p202_check_customer_key\('
                    . '\$postedCustomerKey\)\) '
                    . '!== null\) \{\s*\$keyErrors\[\'p202_customer_api_key\'\] = \$customerKeyError;/',
            ] as $form => $pattern
        ) {
            $start = strpos($page, "\$_POST['" . $form . "']");
            self::assertIsInt($start, $form);
            $end = strpos($page, "if (!empty(\$_POST['", $start + 10);
            $branch = substr($page, $start, ($end === false ? strlen($page) : $end) - $start);
            self::assertSame(1, preg_match($pattern, $branch), $form . ' decides on the typed key\'s own answer');
            self::assertStringNotContainsString('is_valid_api_key(', $branch, $form);
        }
    }

    public function testTheCheckAsksTheValidatorAboutTheKeyItself(): void
    {
        $fn = new \ReflectionFunction('p202_check_typed_key');
        $lines = file((string)$fn->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
        $call = 'return p202_typed_key_error(\\Prosper202\\License\\ClickServerKeyValidator::check(trim($key)));';
        self::assertStringContainsString($call, $body);
        self::assertStringNotContainsString('is_valid_api_key', $body);
    }

    /**
     * The key is trimmed once, and that one value is checked, saved and put
     * in the session: the check asked about the trimmed key while the page
     * saved what was typed, spaces and all.
     */
    public function testTheKeyCheckedIsTheKeySaved(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/202-account/account.php');
        $start = strpos($page, "\$_POST['change_user_api_key']");
        self::assertIsInt($start);
        $end = strpos($page, "if (!empty(\$_POST['", $start + 10);
        self::assertIsInt($end);
        $branch = substr($page, $start, $end - $start);
        self::assertSame(1, substr_count($branch, "\$_POST['user_api_key']"), 'the posted key is read once');
        self::assertStringContainsString("\$typedKey = trim((string) \$_POST['user_api_key'])", $branch);
        self::assertSame(0, preg_match('/\$typedKey\s*=(?!=)/', str_replace(
            "\$typedKey = trim((string) \$_POST['user_api_key'])",
            '',
            $branch
        )), 'nothing assigns it again');
        foreach (
            [
                'p202_check_typed_key($typedKey)',
                "\$mysql['user_api_key'] = \$db->real_escape_string(\$typedKey);",
                "\$_SESSION['user_api_key'] = \$typedKey;",
                "\$_SESSION['user_cirrus_link'] = \$typedKey;",
            ] as $use
        ) {
            self::assertStringContainsString($use, $branch);
        }
    }

    public function testACustomerKeyIsSavedOnlyOnAValidAnswer(): void
    {
        self::assertNull(p202_customer_key_error(['valid' => true, 'paid' => false]));
        self::assertSame(P202_ACCOUNT_KEY_UNCHECKED, p202_customer_key_error(null));
        self::assertStringContainsString('not valid', (string)p202_customer_key_error(['valid' => false, 'paid' => null]));
        // A gateway's 404 page or a 5xx is no answer as the validator reads it,
        // so it is "could not be checked", never "not valid".
        foreach ([[404, '<html>Not Found</html>'], [502, 'Bad gateway'], [404, '{"message":"Not Found"}']] as [$status, $body]) {
            $licence = \Prosper202\License\ClickServerKeyValidator::interpret($status, $body);
            self::assertSame(P202_ACCOUNT_KEY_UNCHECKED, p202_customer_key_error($licence), "$status $body");
        }
        $fn = new \ReflectionFunction('p202_check_customer_key');
        $lines = (array)file((string)$fn->getFileName());
        $body = implode('', array_slice($lines, $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
        self::assertStringContainsString(
            'return p202_customer_key_error(\\Prosper202\\License\\ClickServerKeyValidator::check($key));',
            $body
        );
    }
}

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
                    . '\(string\) \$_POST\[\'user_api_key\'\]\)\) '
                    . '!== null\) \{\s*\$keyErrors\[\'user_api_key\'\] = \$typedKeyError;'
                    . '\s*\}\s*if \(!\$keyErrors\) \{/',
                'update_p202_customer_api_key' => '/if \(\(\$customerKeyError = p202_customer_key_error\('
                    . 'validateCustomersApiKey\(\$postedCustomerKey\)\)\) '
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

    public function testACustomerKeyIsSavedOnlyOnAValidAnswer(): void
    {
        self::assertNull(p202_customer_key_error(['code' => 200, 'msg' => 'Key valid']));
        self::assertSame(P202_ACCOUNT_KEY_UNCHECKED, p202_customer_key_error(null));
        self::assertStringContainsString('not valid', (string)p202_customer_key_error(['code' => 404]));
        self::assertStringContainsString('not valid', (string)p202_customer_key_error([]));
    }
}

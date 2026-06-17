<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the installer's pure helpers: field rules, CSRF token check,
 * account validation, JSON encoding, base-URL building, and success rendering.
 *
 * @covers ::install_default_rules
 * @covers ::install_csrf_ok
 * @covers ::install_validate_account
 * @covers ::install_encode_response
 * @covers ::install_request_base_url
 * @covers ::render_install_success
 */
final class InstallHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../202-config/functions-install-helpers.php';
    }

    /** @return array{username_min:int,username_max:int,password_min:int,password_max:int} */
    private function rules(): array
    {
        return install_default_rules();
    }

    /** A fully valid submission, overridable per field. */
    private function validPost(array $overrides = []): array
    {
        return array_merge([
            'user_email'       => 'owner@example.com',
            'user_name'        => 'admin1',
            'user_pass'        => 'secret9',
            'verify_user_pass' => 'secret9',
        ], $overrides);
    }

    public function testDefaultRulesAreStable(): void
    {
        $this->assertSame(
            ['username_min' => 4, 'username_max' => 20, 'password_min' => 6, 'password_max' => 35],
            install_default_rules()
        );
    }

    public function testCsrfRejectsEmptyExpectedToken(): void
    {
        $this->assertFalse(install_csrf_ok('', ''));
        $this->assertFalse(install_csrf_ok('', 'anything'));
    }

    public function testCsrfRejectsMismatchAndEmptySubmission(): void
    {
        $this->assertFalse(install_csrf_ok('expected-token', 'wrong-token'));
        $this->assertFalse(install_csrf_ok('expected-token', ''));
    }

    public function testCsrfAcceptsExactMatch(): void
    {
        $this->assertTrue(install_csrf_ok('a-real-token', 'a-real-token'));
    }

    public function testValidSubmissionHasNoErrors(): void
    {
        $errors = install_validate_account($this->validPost(), $this->rules());

        $this->assertSame(
            ['user_email' => '', 'user_name' => '', 'user_pass' => ''],
            $errors
        );
    }

    public function testInvalidEmailIsRejected(): void
    {
        $errors = install_validate_account($this->validPost(['user_email' => 'not-an-email']), $this->rules());

        $this->assertStringContainsString('valid email address', $errors['user_email']);
        $this->assertSame('', $errors['user_name']);
        $this->assertSame('', $errors['user_pass']);
    }

    public function testMissingEmailKeyIsRejectedWithoutWarning(): void
    {
        $post = $this->validPost();
        unset($post['user_email']);

        $errors = install_validate_account($post, $this->rules());

        $this->assertStringContainsString('valid email address', $errors['user_email']);
    }

    public function testEmptyUsernameIsRejected(): void
    {
        $errors = install_validate_account($this->validPost(['user_name' => '']), $this->rules());

        $this->assertStringContainsString('type in your desired username', $errors['user_name']);
    }

    public function testNonAlphanumericUsernameIsRejected(): void
    {
        $errors = install_validate_account($this->validPost(['user_name' => 'bad name!']), $this->rules());

        $this->assertStringContainsString('alphanumeric', $errors['user_name']);
    }

    public function testTooShortUsernameIsRejected(): void
    {
        $errors = install_validate_account($this->validPost(['user_name' => 'ab']), $this->rules());

        $this->assertStringContainsString('between 4 and 20', $errors['user_name']);
    }

    public function testTooLongUsernameIsRejected(): void
    {
        $errors = install_validate_account($this->validPost(['user_name' => str_repeat('a', 21)]), $this->rules());

        $this->assertStringContainsString('between 4 and 20', $errors['user_name']);
    }

    public function testMissingPasswordIsRejected(): void
    {
        $post = $this->validPost();
        unset($post['user_pass'], $post['verify_user_pass']);

        $errors = install_validate_account($post, $this->rules());

        $this->assertStringContainsString('type in your desired password', $errors['user_pass']);
        $this->assertStringContainsString('verify your password', $errors['user_pass']);
    }

    public function testTooShortPasswordIsRejected(): void
    {
        $errors = install_validate_account(
            $this->validPost(['user_pass' => 'ab1', 'verify_user_pass' => 'ab1']),
            $this->rules()
        );

        $this->assertStringContainsString('at least 6 characters', $errors['user_pass']);
    }

    public function testTooLongPasswordIsRejected(): void
    {
        $long = str_repeat('x', 36);
        $errors = install_validate_account(
            $this->validPost(['user_pass' => $long, 'verify_user_pass' => $long]),
            $this->rules()
        );

        $this->assertStringContainsString('no more than 35 characters', $errors['user_pass']);
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        $errors = install_validate_account(
            $this->validPost(['user_pass' => 'secret9', 'verify_user_pass' => 'secret8']),
            $this->rules()
        );

        $this->assertStringContainsString('did not match', $errors['user_pass']);
    }

    public function testRuleBoundsAreHonored(): void
    {
        // With a relaxed min, a 2-char username that fails the default passes here.
        $relaxed = ['username_min' => 2, 'username_max' => 20, 'password_min' => 6, 'password_max' => 35];

        $errors = install_validate_account($this->validPost(['user_name' => 'ab']), $relaxed);

        $this->assertSame('', $errors['user_name']);
    }

    public function testEncodeResponseRoundTripsValidPayload(): void
    {
        $payload = ['success' => true, 'warnings' => ['a'], 'errors' => ['general' => '<div>x</div>']];

        $encoded = install_encode_response($payload);

        $this->assertTrue($encoded['ok']);
        $this->assertSame($payload, json_decode($encoded['body'], true));
    }

    public function testEncodeResponseFallsBackOnUnencodablePayload(): void
    {
        // Malformed UTF-8 makes json_encode() return false.
        $encoded = install_encode_response(['errors' => ['general' => "\xB1\x31"]]);

        $this->assertFalse($encoded['ok']);
        $decoded = json_decode($encoded['body'], true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
        $this->assertTrue($decoded['retryable']);
        $this->assertStringContainsString('unexpected error', $decoded['errors']['general']);
    }

    /** Capture the success panel HTML. */
    private function renderSuccess(array $html, array $warnings, string $base, string $serverName, string $baseUrl = 'https://host.test/'): string
    {
        ob_start();
        render_install_success($html, $warnings, $base, $serverName, $baseUrl);
        return (string) ob_get_clean();
    }

    public function testRequestBaseUrlPrefersHostHeaderAndScheme(): void
    {
        $this->assertSame(
            'https://host.test:8000/',
            install_request_base_url(['HTTPS' => 'on', 'HTTP_HOST' => 'host.test:8000', 'SERVER_NAME' => 'host.test'], '/')
        );
        $this->assertSame(
            'http://fallback.test/p/',
            install_request_base_url(['SERVER_NAME' => 'fallback.test'], '/p/')
        );
        $this->assertSame(
            'http://localhost/',
            install_request_base_url([], '/')
        );
    }

    public function testSuccessPanelRendersCronLine(): void
    {
        $out = $this->renderSuccess(['user_name' => 'admin1'], [], 'https://host.test/', 'host.test', 'https://host.test:8000/');

        $this->assertStringContainsString('Keep background jobs running', $out);
        $this->assertStringContainsString('https://host.test:8000/202-cronjobs/index.php', $out);
    }

    public function testSuccessPanelShowsApiKeyOnlyWhenPresent(): void
    {
        $without = $this->renderSuccess(['user_name' => 'admin1'], [], 'https://host.test/', 'host.test');
        $this->assertStringNotContainsString('Connect the CLI', $without);

        $with = $this->renderSuccess(
            ['user_name' => 'admin1', 'rest_api_key' => 'deadbeef', 'user_id' => 7],
            [],
            'https://host.test/',
            'host.test'
        );
        $this->assertStringContainsString('Connect the CLI', $with);
        $this->assertStringContainsString('deadbeef', $with);
        $this->assertStringContainsString('<code>7</code>', $with);
    }

    public function testSuccessPanelRendersAccountAndLoginLink(): void
    {
        $out = $this->renderSuccess(['user_name' => 'admin1'], [], 'https://host.test/', 'host.test');

        $this->assertStringContainsString('Success!', $out);
        $this->assertStringContainsString('admin1', $out);
        $this->assertStringContainsString('https://host.test/202-login.php', $out);
        // No warnings block when there are no warnings.
        $this->assertStringNotContainsString('need a quick follow-up', $out);
    }

    public function testSuccessPanelRendersWarnings(): void
    {
        $out = $this->renderSuccess(
            ['user_name' => 'admin1'],
            ['Cron setup did not complete.'],
            'https://host.test/',
            'host.test'
        );

        $this->assertStringContainsString('need a quick follow-up', $out);
        $this->assertStringContainsString('Cron setup did not complete.', $out);
    }

    public function testSuccessPanelEscapesServerName(): void
    {
        $out = $this->renderSuccess(
            ['user_name' => 'admin1'],
            [],
            'https://host.test/',
            '"><img src=x onerror=alert(1)>'
        );

        // The injected markup must be escaped, never reflected raw.
        $this->assertStringNotContainsString('<img src=x', $out);
        $this->assertStringContainsString('&lt;img', $out);
    }
}

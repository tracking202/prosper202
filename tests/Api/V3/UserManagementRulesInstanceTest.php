<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The users API keeps 202-account/user-management.php's rules, over HTTP,
 * against a running instance: P202_BASE with the Super user's REST key in
 * P202_API_KEY (the key the installer mints, as the Agent Evals job has it).
 *
 * Admin and Super user both passed requireAdmin(), so an Admin's key could
 * grant itself Super user, mint a full-access key for user 1, set user 1's
 * password and read user 1's integration secrets — all measured on a live
 * instance before the fix, every one refused by the page. Each case below
 * asserts the refusal positively, by status and by the sentence the guard
 * writes ("not succeeded" is not "refused"), and the paths the rules must
 * leave open are asserted to succeed.
 *
 * The suite creates one Admin user and removes it at the end.
 *
 * @group integration
 * @group instance
 */
final class UserManagementRulesInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $superKey = '';
    private static string $adminKey = '';
    private static int $adminId = 0;
    private static ?string $setupError = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$superKey = (string) getenv('P202_API_KEY');
        if (self::$superKey === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        [$status] = self::call('', 'GET', '/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }

        $run = 'umr' . bin2hex(random_bytes(4));
        [$status, $body] = self::call(self::$superKey, 'POST', '/users', [
            'user_name' => $run, 'user_email' => "$run@example.com", 'user_pass' => 'pass-' . $run,
        ]);
        self::$adminId = (int) ($body['data']['user_id'] ?? 0);
        if ($status !== 201 || self::$adminId <= 1) {
            self::$setupError = "Creating the Admin user answered $status: " . json_encode($body);
            return;
        }
        [$status, $body] = self::call(self::$superKey, 'POST', '/users/' . self::$adminId . '/roles', ['role_id' => 2]);
        if ($status !== 200) {
            self::$setupError = "Granting the Admin role answered $status: " . json_encode($body);
            return;
        }
        [$status, $body] = self::call(self::$superKey, 'POST', '/users/' . self::$adminId . '/api-keys', []);
        self::$adminKey = (string) ($body['data']['api_key'] ?? '');
        if ($status !== 201 || self::$adminKey === '') {
            self::$setupError = "Minting the Admin's key answered $status: " . json_encode($body);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$adminId > 1 && self::$superKey !== '') {
            self::call(self::$superKey, 'DELETE', '/users/' . self::$adminId);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function call(string $key, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, []];
        }
        $decoded = $response === '' ? [] : json_decode($response, true);

        return [$status, is_array($decoded) ? $decoded : ['raw' => $response]];
    }

    private function assertRefused(int $wantStatus, string $wantMessage, string $method, string $path, ?array $body = null, ?string $key = null): void
    {
        [$status, $response] = self::call($key ?? self::$adminKey, $method, $path, $body);
        $this->assertSame($wantStatus, $status, "$method $path should be refused: " . json_encode($response));
        $this->assertStringContainsString($wantMessage, (string) ($response['message'] ?? ''), "$method $path refused for the wrong reason");
    }

    public function testAnAdminCannotGrantItselfSuperUser(): void
    {
        $this->assertRefused(403, 'add_edit_delete_admin', 'POST', '/users/' . self::$adminId . '/roles', ['role_id' => 1]);
        // The same role spelled so a lenient cast reads it as 1.
        foreach (['1.0', '1abc', '01', true] as $spelling) {
            $this->assertRefused(422, 'role_id', 'POST', '/users/' . self::$adminId . '/roles', ['role_id' => $spelling]);
        }
        [, $user] = self::call(self::$superKey, 'GET', '/users/' . self::$adminId);
        $this->assertSame([2], array_map('intval', array_column($user['data']['roles'] ?? [], 'role_id')), 'the Admin must still hold only the Admin role');
    }

    public function testAnAdminCannotActOnTheSuperUser(): void
    {
        $this->assertRefused(403, 'Super user (user 1)', 'POST', '/users/1/api-keys', []);
        $this->assertRefused(403, 'Super user (user 1)', 'PUT', '/users/1', ['user_pass' => 'not-the-password-1']);
        $this->assertRefused(403, 'Super user (user 1)', 'GET', '/users/1/preferences');
        $this->assertRefused(403, 'Super user (user 1)', 'GET', '/users/1/identity-key');
        $this->assertRefused(403, 'Super user (user 1)', 'GET', '/users/1/api-keys');
        $this->assertRefused(403, 'add_edit_delete_admin', 'DELETE', '/users/1');
        // The dry-run preview of a refused delete is refused the same way.
        $this->assertRefused(403, 'add_edit_delete_admin', 'DELETE', '/users/1?dry_run=1');
    }

    public function testNobodyGrantsSuperUserOrChangesUserOneOrRemovesThemselves(): void
    {
        $this->assertRefused(403, 'Super user role cannot be granted', 'POST', '/users/' . self::$adminId . '/roles', ['role_id' => 1], self::$superKey);
        $this->assertRefused(403, "Super user's role cannot be changed", 'DELETE', '/users/1/roles/1', null, self::$superKey);
        $this->assertRefused(403, 'cannot be removed', 'DELETE', '/users/1', null, self::$superKey);
    }

    public function testChangingYourOwnPasswordNeedsTheCurrentOne(): void
    {
        $this->assertRefused(422, 'current password', 'PUT', '/users/' . self::$adminId, ['user_pass' => 'another-pass-1']);
        $this->assertRefused(422, 'incorrect', 'PUT', '/users/' . self::$adminId, ['user_pass' => 'another-pass-1', 'current_password' => 'wrong']);
        $this->assertRefused(422, 'Invalid password', 'PUT', '/users/' . self::$adminId, ['user_pass' => str_repeat('x', 73)], self::$superKey);
    }

    public function testThePathsTheRulesLeaveOpenStillWork(): void
    {
        // An Admin edits its own profile; the Super user resets another
        // user's password without knowing it, and manages its roles.
        [$status, $body] = self::call(self::$adminKey, 'PUT', '/users/' . self::$adminId, ['user_fname' => 'Rules']);
        $this->assertSame(200, $status, json_encode($body));
        [$status, $body] = self::call(self::$superKey, 'PUT', '/users/' . self::$adminId, ['user_pass' => 'reset-by-super-1']);
        $this->assertSame(200, $status, json_encode($body));
        [$status, $body] = self::call(self::$superKey, 'POST', '/users/' . self::$adminId . '/roles', ['role_id' => 3]);
        $this->assertSame(200, $status, json_encode($body));
        [$status, $body] = self::call(self::$superKey, 'DELETE', '/users/' . self::$adminId . '/roles/3');
        $this->assertSame(204, $status, json_encode($body));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * A deactivated user's API keys stop working on every API version, as the
 * sign-in and the remember-me cookie stop them (functions-auth.php asks for
 * `user_active = 1`). The three key lookups asked for `user_deleted = 0`
 * alone, so a user turned off in Account › Users kept every key with its
 * role: measured live, a Publisher switched off still answered 200 on v3 and
 * read reports through v1 and v2.
 *
 * Over HTTP against a running instance: P202_BASE with the Super user's REST
 * key in P202_API_KEY. The suite creates one user, mints it a key, switches
 * it off and on again, and removes it at the end. The refusal is asserted
 * positively, by status and by the sentence the guard writes, and the same
 * key is shown to work before and after, so a key that never worked cannot
 * pass as one that was refused.
 *
 * @group integration
 * @group instance
 */
final class DeactivatedUserKeyInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $superKey = '';
    private static string $key = '';
    private static int $userId = 0;
    private static ?string $setupError = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$superKey = (string) getenv('P202_API_KEY');
        if (self::$superKey === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        $run = 'deact' . bin2hex(random_bytes(4));
        [$status, $body] = self::call(self::$superKey, 'POST', '/users', [
            'user_name' => $run, 'user_email' => "$run@example.com", 'user_pass' => 'pass-' . $run,
        ]);
        self::$userId = (int) ($body['data']['user_id'] ?? 0);
        if ($status !== 201 || self::$userId <= 1) {
            self::$setupError = "Creating the user answered $status: " . json_encode($body);
            return;
        }
        [$status, $body] = self::call(self::$superKey, 'POST', '/users/' . self::$userId . '/roles', ['role_id' => 6]);
        if ($status !== 200) {
            self::$setupError = "Granting the Publisher role answered $status: " . json_encode($body);
            return;
        }
        [$status, $body] = self::call(self::$superKey, 'POST', '/users/' . self::$userId . '/api-keys', []);
        self::$key = (string) ($body['data']['api_key'] ?? '');
        if ($status !== 201 || self::$key === '') {
            self::$setupError = "Minting the user's key answered $status: " . json_encode($body);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$userId > 1 && self::$superKey !== '') {
            self::call(self::$superKey, 'DELETE', '/users/' . self::$userId);
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
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

    /**
     * A legacy API's answer for the key: v1's and v2's reports read it from
     * `apikey` and answer JSON whose `msg` says why when they refuse.
     *
     * @return array<string, mixed>
     */
    private static function legacy(string $version): array
    {
        $query = http_build_query(['apikey' => self::$key, 'type' => 'keywords', 'date_from' => '10/01/2026', 'date_to' => '10/02/2026']);
        $ch = curl_init(self::$base . '/api/' . $version . '/reports/?' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $response = curl_exec($ch);
        curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;

        return is_array($decoded) ? $decoded : ['raw' => $response];
    }

    private function setActive(int $active): void
    {
        [$status, $body] = self::call(self::$superKey, 'PUT', '/users/' . self::$userId, ['user_active' => $active]);
        $this->assertSame(200, $status, 'setting user_active answered: ' . json_encode($body));
        $this->assertSame($active, $body['data']['user_active'] ?? null);
    }

    private function assertKeyWorks(string $when): void
    {
        [$status, $body] = self::call(self::$key, 'GET', '/capabilities');
        $this->assertSame(200, $status, "v3 $when: " . json_encode($body));
        $this->assertSame(self::$userId, $body['data']['principal']['user_id'] ?? null, "v3 $when answers as the key's user");
        foreach (['v1', 'v2'] as $version) {
            $answer = self::legacy($version);
            $this->assertArrayHasKey('date_range', $answer, "$version $when reads the report: " . json_encode($answer));
        }
    }

    public function testADeactivatedUsersKeyIsRefusedOnEveryVersionAndWorksAgainWhenReactivated(): void
    {
        $this->assertKeyWorks('while the user is active');

        $this->setActive(0);
        [$status, $body] = self::call(self::$key, 'GET', '/capabilities');
        $this->assertSame(401, $status, 'v3 refuses a deactivated user\'s key: ' . json_encode($body));
        $this->assertStringContainsString('deactivated', (string) ($body['message'] ?? ''), 'and says why');
        [$status] = self::call(self::$key, 'GET', '/reports/summary');
        $this->assertSame(401, $status, 'on every route, not only the probe');
        foreach (['v1', 'v2'] as $version) {
            $answer = self::legacy($version);
            $this->assertSame(401, $answer['status'] ?? null, "$version refuses it: " . json_encode($answer));
            $this->assertStringContainsString('deactivated', (string) ($answer['msg'] ?? ''), "$version says why");
            $this->assertArrayNotHasKey('date_range', $answer);
        }

        $this->setActive(1);
        $this->assertKeyWorks('once the user is active again');
    }
}

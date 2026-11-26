<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * Every route under /users/{id}/ answers 404 "User not found" for a user
 * who has been removed, and for an id no user has, as GET /users/{id}
 * does — against a running instance: P202_BASE with the Super user's REST
 * key in P202_API_KEY.
 *
 * Measured on 1.9.81 before the fix, for a removed user: GET and PUT
 * /preferences answered 200 and PUT wrote, GET /api-keys answered 200 and
 * POST minted a key (for an id no user had, too), and the click filter's
 * routes answered 200 and wrote. The routes are every one index.php serves
 * under /users/{id}/ (RouteInventory), so a route added later is asked too.
 * Each refusal is asserted by the guard's own sentence: the API key, role
 * and click filter entry the removed user had are named in the paths, so
 * a 404 from the record's own lookup ("API key not found") would not pass.
 *
 * @group integration
 * @group instance
 */
final class RemovedUserRoutesInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static int $userId = 0;
    /** @var array<string, string> the removed user's records, named in the paths */
    private static array $named = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/RouteInventory.php';
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        [$status] = self::call('GET', '/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }

        $run = 'rmu' . bin2hex(random_bytes(4));
        [$status, $body] = self::call('POST', '/users', [
            'user_name' => $run, 'user_email' => "$run@example.com", 'user_pass' => 'pass-' . $run,
        ]);
        self::$userId = (int) ($body['data']['user_id'] ?? 0);
        if ($status !== 201 || self::$userId <= 1) {
            self::$setupError = "Creating the user answered $status: " . json_encode($body);
            return;
        }
        $id = self::$userId;
        [, $roles] = self::call('GET', '/users/roles');
        foreach ($roles['data'] ?? [] as $role) {
            if ((int) $role['role_id'] > 2) {
                self::$named['roles'] = (string) $role['role_id'];
                break;
            }
        }
        $steps = [
            ['POST', "/users/$id/roles", ['role_id' => (int) (self::$named['roles'] ?? 0)], 200],
            ['POST', "/users/$id/api-keys", [], 201],
            ['POST', "/users/$id/click-filter/entries", ['address' => '198.51.100.7', 'action' => 'filter'], 201],
        ];
        foreach ($steps as [$method, $path, $send, $want]) {
            [$status, $body] = self::call($method, $path, $send);
            if ($status !== $want) {
                self::$setupError = "$method $path answered $status: " . json_encode($body);
                return;
            }
            if (str_ends_with($path, '/api-keys')) {
                self::$named['api-keys'] = (string) $body['data']['api_key'];
            } elseif (str_ends_with($path, '/entries')) {
                self::$named['entries'] = (string) $body['data']['entry_id'];
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$userId > 1 && self::$key !== '') {
            self::call('DELETE', '/users/' . self::$userId);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function call(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . self::$key],
            CURLOPT_TIMEOUT => 20,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $options);
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
     * Every operation served under /users/{id}/, its parameters filled: the
     * user, then the record of the segment before each later one.
     *
     * @return list<array{0: string, 1: string}> [method, path]
     */
    private static function routes(int $userId): array
    {
        $out = [];
        foreach (RouteInventory::served() as $op) {
            [$method, $path] = explode(' ', $op, 2);
            if (!str_starts_with($path, '/users/{}/')) {
                continue;
            }
            $segments = explode('/', $path);
            foreach ($segments as $i => $segment) {
                if ($segment !== '{}') {
                    continue;
                }
                $segments[$i] = $i === 2 ? (string) $userId : (self::$named[$segments[$i - 1]] ?? '1');
            }
            $filled = implode('/', $segments);
            if (str_ends_with($filled, '/click-filter/test')) {
                $filled .= '?ip=198.51.100.7';
            }
            $out[] = [$method, $filled];
            if ($method === 'DELETE') {
                $out[] = [$method, $filled . '?dry_run=1'];
            }
        }
        if (count($out) < 19) {
            self::fail('read ' . count($out) . ' operations under /users/{id}/, fewer than there are');
        }

        return $out;
    }

    public function testEveryRouteUnderARemovedUserAnswersThatTheUserIsNotThere(): void
    {
        $id = self::$userId;
        foreach (self::routes($id) as [$method, $path]) {
            if ($method === 'GET') {
                [$status, $body] = self::call($method, $path);
                $this->assertSame(200, $status, "before the user is removed, $method $path: " . json_encode($body));
            }
        }
        [$status, $body] = self::call('DELETE', "/users/$id");
        $this->assertSame(204, $status, 'removing the user: ' . json_encode($body));

        foreach ([$id, 2000000000] as $absent) {
            foreach (self::routes($absent) as [$method, $path]) {
                // The role to grant is read by the route's authorization.
                $send = str_ends_with($path, '/roles') ? ['role_id' => (int) self::$named['roles']] : [];
                [$status, $body] = self::call($method, $path, $method === 'GET' ? null : $send);
                $this->assertSame(404, $status, "$method $path: " . json_encode($body));
                $why = "$method $path refused for another reason";
                $this->assertSame('User not found', $body['message'] ?? null, $why);
            }
        }
    }
}

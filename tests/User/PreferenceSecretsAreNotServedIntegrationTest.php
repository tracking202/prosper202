<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\ServedPreferences;

/**
 * GET /users/{id}/preferences against a real database serves no secret, and
 * PUT with the body GET answered keeps every one of them.
 *
 * Skips unless a scratch test database is configured: P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME. It installs the schema there and writes user 5002.
 *
 * @group integration
 */
final class PreferenceSecretsAreNotServedIntegrationTest extends TestCase
{
    private const USER = 5002;

    /** column => the secret stored in it */
    private const SECRETS = [
        'cb_key' => 'it-cb-secret',
        'jvzoo_ipn_secret_key' => 'it-jvzoo-secret',
        'zaxaa_api_signature' => 'it-zaxaa-secret',
        'ipqs_api_key' => 'it-ipqs-secret',
        'revcontent_user_id' => 'it-rc-id-secret',
        'revcontent_user_secret' => 'it-rc-secret',
        'user_slack_incoming_webhook' => 'https://hooks.slack.com/services/T0/B0/it-slack-secret',
        'lpo_site_key' => 'it-lpo-site-secret',
        'lpo_bridge_config' => '{"webhook_id":3,"ctx_key":"it-ctx-secret"}',
        'paykickstart_ipn_secret_keys' => "it-pk-secret-0001\nit-pk-secret-0002",
    ];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        $u = self::USER;
        foreach (['202_users', '202_users_pref', '202_charts'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = $u");
        }
        self::$db->query("INSERT INTO 202_users SET user_id = $u, user_name = 'secrets$u', user_pass = 'x',"
            . " user_email = 's$u@example.com', user_dash_email = '', user_timezone = 'UTC', user_time_register = 0,"
            . " install_hash = '', user_hash = '', user_deleted = 0");
        $sets = ["user_id = $u", "user_account_currency = 'USD'", 'cb_verified = 1', "lpo_status = 'active'"];
        foreach (self::SECRETS as $column => $secret) {
            $sets[] = "`$column` = '" . self::$db->real_escape_string($secret) . "'";
        }
        self::$db->query('INSERT INTO 202_users_pref SET ' . implode(', ', $sets));
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $row = self::$db->query('SELECT * FROM 202_users_pref WHERE user_id = ' . self::USER)->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function put(UsersController $users, array $body): array
    {
        try {
            return $users->updatePreferences(self::USER, $body)['data'];
        } catch (ValidationException $e) {
            self::fail('the body GET answered was refused: ' . json_encode($e->getFieldErrors()));
        }
    }

    public function testGetServesNoSecretAndSaysWhichAreStored(): void
    {
        self::$db->query("UPDATE 202_users_pref SET ipqs_api_key = '' WHERE user_id = " . self::USER);

        $served = (new UsersController(self::$db))->getPreferences(self::USER)['data'];

        $bytes = json_encode($served, JSON_THROW_ON_ERROR);
        foreach (self::SECRETS as $column => $secret) {
            self::assertStringNotContainsString($secret, $bytes, "$column's secret is served");
            self::assertSame(['stored' => $column !== 'ipqs_api_key'], $served[$column], $column);
        }
        self::assertStringNotContainsString('it-ctx-secret', $bytes);
        self::assertStringNotContainsString('hooks.slack.com', $bytes);
        self::assertSame(1, (int) $served['cb_verified']);
        self::assertSame('active', $served['lpo_status']);
        $unanswered = array_values(array_diff(array_keys($this->stored()), array_keys($served)));
        self::assertSame([], $unanswered, 'every column is answered');
    }

    public function testPutWithTheBodyGetAnsweredKeepsEverySecret(): void
    {
        $users = new UsersController(self::$db);
        $before = $this->stored();
        $body = self::overJson($users->getPreferences(self::USER)['data']);

        $answer = $this->put($users, $body);

        $after = $this->stored();
        foreach (array_keys(self::SECRETS) as $column) {
            self::assertSame($before[$column], $after[$column], "$column was changed by its own stored state");
        }
        self::assertSame('1', (string) $after['cb_verified'], 'an unchanged ClickBank key keeps its verification');
        self::assertSame($body, self::overJson($answer), 'the answer is the body sent');
    }

    public function testASecretSentAsAStringIsStoredAndTheRestKept(): void
    {
        $users = new UsersController(self::$db);
        $body = self::overJson($users->getPreferences(self::USER)['data']);
        $body['cb_key'] = 'it-cb-rotated';

        $answer = $this->put($users, $body);

        $after = $this->stored();
        self::assertSame('it-cb-rotated', $after['cb_key']);
        self::assertSame('0', (string) $after['cb_verified'], 'a new ClickBank key is not verified yet');
        foreach (self::SECRETS as $column => $secret) {
            if ($column !== 'cb_key') {
                self::assertSame($secret, $after[$column], $column);
            }
        }
        self::assertSame(['stored' => true], $answer['cb_key']);
        self::assertStringNotContainsString('it-cb-rotated', json_encode($answer, JSON_THROW_ON_ERROR));
    }

    public function testAStoredStateThatIsNotTheRecordsWritesNothing(): void
    {
        $before = $this->stored();
        try {
            (new UsersController(self::$db))->updatePreferences(self::USER, [
                'user_pref_limit' => 100,
                'cb_key' => ['stored' => false],
                'lpo_site_key' => 'it-planted',
                'user_id' => 1,
            ]);
            self::fail('a changed stored state and a changed read-only column must be refused');
        } catch (ValidationException $e) {
            self::assertSame(['cb_key', 'lpo_site_key', 'user_id'], array_keys($e->getFieldErrors()));
        }
        self::assertSame($before, $this->stored(), 'nothing in the request was written');
    }

    public function testOnlyStoredStatesSentBackIsNothingToUpdate(): void
    {
        $before = $this->stored();
        try {
            (new UsersController(self::$db))->updatePreferences(
                self::USER,
                ['cb_key' => ['stored' => true], 'user_id' => self::USER]
            );
            self::fail('a body of only unchanged keys writes nothing, and says so');
        } catch (ValidationException $e) {
            self::assertStringContainsString('No valid fields to update', $e->getMessage());
        }
        self::assertSame($before, $this->stored());
        self::assertSame(ServedPreferences::SECRET, array_keys(self::SECRETS), 'this test stores every listed secret');
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed> as a client reads it from the response
     */
    private static function overJson(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}

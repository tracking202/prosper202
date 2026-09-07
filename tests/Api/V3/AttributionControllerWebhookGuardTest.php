<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ValidationException;
use Tests\Support\FakeMysqliConnection;
use Tests\TestCase;

/**
 * scheduleExport() is the api/v3 write boundary for an export webhook URL. The
 * guard there must (a) reject an unsafe URL before anything is written and
 * (b) NOT resolve hostnames -- a resolver stall must not block a PHP-FPM
 * worker or turn a valid URL into a 422. The full check runs in the cron.
 *
 * The controller is exercised for real against a mocked mysqli: getModel()'s
 * SELECT is answered, and the test counts how many statements were prepared
 * to tell "rejected before the INSERT" from "inserted".
 */
final class AttributionControllerWebhookGuardTest extends TestCase
{
    private FakeMysqliConnection $db;

    private function controller(): AttributionController
    {
        $this->db = new FakeMysqliConnection();
        $this->db->whenQueryContainsReturnRows('FROM 202_attribution_models', [
            ['model_id' => 7, 'user_id' => 1, 'model_name' => 'm', 'model_type' => 'linear'],
        ]);
        $this->db->whenQueryContainsInsertId('INSERT INTO 202_attribution_exports', 42);

        return new AttributionController($this->db, 1);
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function testAnUnsafeWebhookUrlIsRejectedBeforeTheInsert(string $url): void
    {
        $controller = $this->controller();

        try {
            $controller->scheduleExport(7, ['webhook_url' => $url]);
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('webhook_url', $e->getFieldErrors());
        }

        self::assertCount(1, $this->db->preparedSql, 'only getModel() may have run; nothing was inserted');
        self::assertStringStartsWith('SELECT', $this->db->preparedSql[0]);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'cleartext'  => ['http://203.0.113.10/hook'],
            'loopback'   => ['https://127.0.0.1/hook'],
            'metadata'   => ['https://169.254.169.254/hook'],
            'private'    => ['https://10.0.0.5/hook'],
            'bad port'   => ['https://203.0.113.10:9000/hook'],
            'garbage'    => ['not a url'],
        ];
    }

    public function testAHostnameIsAcceptedWithoutBeingResolved(): void
    {
        // .invalid can never resolve (RFC 6761). If the write boundary did DNS,
        // this would be rejected as "does not resolve" -- or hang on a slow
        // resolver -- for a URL the cron is perfectly able to check later.
        $controller = $this->controller();
        $this->scheduleExpectingTheInsertToLand($controller, ['webhook_url' => 'https://hooks.example.invalid/export']);

        $insert = $this->db->statementsContaining('INSERT INTO 202_attribution_exports');
        self::assertCount(1, $insert);
        self::assertSame(1, $insert[0]->executeCount);
        self::assertSame('https://hooks.example.invalid/export', $insert[0]->boundValues[11]);
    }

    /**
     * Runs scheduleExport() up to and including the INSERT. The controller then
     * reads the native mysqli_stmt::$insert_id, which a constructor-skipping
     * fake cannot provide (PHP throws "object is already closed"); as in
     * ControllerTest, reaching that Error is the proof that validation passed
     * and the write executed -- a rejection would have thrown a
     * ValidationException before any INSERT was prepared.
     *
     * @param array<string, mixed> $payload
     */
    private function scheduleExpectingTheInsertToLand(AttributionController $controller, array $payload): void
    {
        try {
            $controller->scheduleExport(7, $payload);
        } catch (\Error $e) {
            self::assertStringContainsString('already closed', $e->getMessage());
        }
    }

    public function testNoWebhookUrlSkipsTheGuardEntirely(): void
    {
        $controller = $this->controller();
        $this->scheduleExpectingTheInsertToLand($controller, []);

        $insert = $this->db->statementsContaining('INSERT INTO 202_attribution_exports');
        self::assertCount(1, $insert);
        self::assertSame(1, $insert[0]->executeCount);
        self::assertSame('', $insert[0]->boundValues[11]);
    }
}

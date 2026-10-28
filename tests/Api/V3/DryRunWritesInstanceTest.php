<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * `?dry_run=1` on a write previews or is refused, over HTTP: a POST, PUT or
 * PATCH whose route has no preview answers 422 naming dry_run and changes
 * nothing; the routes that preview still preview.
 *
 * The parameter was read for a DELETE alone, so elsewhere it was ignored
 * and the write ran -- measured live, PUT /system/retention?dry_run=1 (the
 * spelling that route's own 422 for a body dry_run gave) changed the
 * retention setting, and POST /aff-networks?dry_run=1 created a category.
 * Each refusal is asserted positively (status and the sentence) and its
 * absence of effect read back, so a request that failed some other way
 * cannot pass as refused.
 *
 * Runs against a live instance: P202_BASE with the Super user's REST key in
 * P202_API_KEY. It creates one category and removes it, and puts the
 * retention setting back as it found it.
 *
 * @group integration
 * @group instance
 */
final class DryRunWritesInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $key = '';
    private static int $category = 0;
    private static ?int $retention = null;
    private static ?string $setupError = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        [$status, $body] = self::call('GET', '/system/retention');
        if ($status !== 200 || !is_int($body['data']['auto_delete_days'] ?? null)) {
            self::$setupError = "Reading the retention setting answered $status: " . json_encode($body);
            return;
        }
        self::$retention = $body['data']['auto_delete_days'];
        [$status, $body] = self::call('POST', '/aff-networks', ['aff_network_name' => 'dryrun-' . bin2hex(random_bytes(4))]);
        self::$category = (int) ($body['data']['aff_network_id'] ?? 0);
        if ($status !== 201 || self::$category <= 0) {
            self::$setupError = "Creating the category answered $status: " . json_encode($body);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$key === '') {
            return;
        }
        if (self::$retention !== null) {
            self::call('PUT', '/system/retention', ['auto_delete_days' => self::$retention]);
        }
        if (self::$category > 0) {
            self::call('DELETE', '/aff-networks/' . self::$category);
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
    private static function call(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . self::$key],
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

    /** @param array<string, mixed> $body */
    private function assertNoPreview(string $method, string $path, array $body): void
    {
        [$status, $answer] = self::call($method, $path, $body);
        $this->assertSame(422, $status, "$method $path is refused, not performed: " . json_encode($answer));
        $this->assertSame('This route has no preview; remove dry_run to perform the write.', $answer['field_errors']['dry_run'] ?? null, "$method $path names dry_run");
    }

    public function testASettingWithNoPreviewRefusesDryRunAndKeepsItsValue(): void
    {
        $other = self::$retention === 45 ? 46 : 45;
        $this->assertNoPreview('PUT', '/system/retention?dry_run=1', ['auto_delete_days' => $other]);
        $this->assertNoPreview('PATCH', '/system/retention?dry_run=true', ['auto_delete_days' => $other]);
        [, $now] = self::call('GET', '/system/retention');
        $this->assertSame(self::$retention, $now['data']['auto_delete_days'] ?? null, 'the refused preview changed nothing');

        [$status, $isp] = self::call('GET', '/system/isp-lookup');
        $this->assertSame(200, $status);
        $this->assertNoPreview('PUT', '/system/isp-lookup?dry_run=1', ['enabled' => false]);

        // The body's dry_run no longer sends the caller to the query string.
        [$status, $answer] = self::call('PUT', '/system/retention', ['auto_delete_days' => $other, 'dry_run' => true]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('has no preview', (string) ($answer['field_errors']['dry_run'] ?? ''));
        $this->assertStringNotContainsString('?dry_run=1 previews', (string) ($answer['field_errors']['dry_run'] ?? ''));
        [, $now] = self::call('GET', '/system/retention');
        $this->assertSame(self::$retention, $now['data']['auto_delete_days'] ?? null);
    }

    public function testACrudWriteRefusesDryRunAndWritesNothing(): void
    {
        $name = 'dryrun-new-' . bin2hex(random_bytes(4));
        $this->assertNoPreview('POST', '/aff-networks?dry_run=1', ['aff_network_name' => $name]);
        $this->assertNoPreview('PUT', '/aff-networks/' . self::$category . '?dry_run=1', ['aff_network_name' => $name]);
        $this->assertNoPreview('PATCH', '/aff-networks/' . self::$category . '?dry_run=yes', ['aff_network_name' => $name]);
        $this->assertNoPreview('POST', '/aff-networks/bulk-upsert?dry_run=1', ['rows' => [['aff_network_name' => $name]]]);
        [$status, $list] = self::call('GET', '/aff-networks?limit=500');
        $this->assertSame(200, $status);
        $this->assertNotContains($name, array_column($list['data'] ?? [], 'aff_network_name'), 'no category was created or renamed');

        [$status, $answer] = self::call('POST', '/aff-networks?dry_run=1&staged=1', ['aff_network_name' => $name]);
        $this->assertSame(422, $status, 'staged and dry_run together: ' . json_encode($answer));
        $this->assertArrayHasKey('staged', $answer['field_errors'] ?? []);

        [$status, $answer] = self::call('POST', '/aff-networks?dry_run=tru', ['aff_network_name' => $name]);
        $this->assertSame(422, $status, 'a misspelt value is refused, never the write');
        $this->assertStringContainsString("got 'tru'", (string) ($answer['field_errors']['dry_run'] ?? ''));
    }

    public function testTheRoutesThatPreviewStillPreview(): void
    {
        [$status, $answer] = self::call('POST', '/system/retention/delete-before?dry_run=1', ['before' => '2000-01-01']);
        $this->assertSame(200, $status, json_encode($answer));
        $this->assertTrue($answer['data']['dry_run'] ?? null);
        $this->assertFalse($answer['data']['scheduled'] ?? null, 'the preview scheduled nothing');

        [$status, $answer] = self::call('DELETE', '/aff-networks/' . self::$category . '?dry_run=1');
        $this->assertSame(200, $status, json_encode($answer));
        $this->assertTrue($answer['data']['dry_run'] ?? null);
        [$status] = self::call('GET', '/aff-networks/' . self::$category);
        $this->assertSame(200, $status, 'the previewed delete removed nothing');
    }
}

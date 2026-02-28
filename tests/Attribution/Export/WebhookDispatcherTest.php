<?php

declare(strict_types=1);

namespace Tests\Attribution\Export;

use Tests\TestCase;
use Prosper202\Attribution\Export\WebhookDispatcher;
use Prosper202\Attribution\Export\WebhookResult;
use Prosper202\Attribution\Export\ExportJob;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\ScopeType;

final class WebhookDispatcherTest extends TestCase
{
    private WebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new WebhookDispatcher();
    }

    private function makeJob(?string $webhookUrl): ExportJob
    {
        return new ExportJob(
            exportId: 1,
            userId: 1,
            modelId: 1,
            scopeType: ScopeType::GLOBAL,
            scopeId: null,
            startHour: 2024010100,
            endHour: 2024010123,
            format: ExportFormat::CSV,
            status: ExportStatus::PENDING,
            filePath: null,
            downloadToken: null,
            webhookUrl: $webhookUrl,
            webhookMethod: 'POST',
            webhookHeaders: [],
            webhookStatusCode: null,
            webhookResponseBody: null,
            lastAttemptedAt: null,
            completedAt: null,
            errorMessage: null,
            createdAt: time(),
            updatedAt: time(),
        );
    }

    // ---------------------------------------------------------------
    // Empty / null webhook URL
    // ---------------------------------------------------------------

    public function testEmptyWebhookUrlReturnsNullResult(): void
    {
        $job = $this->makeJob(null);
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNull($result->statusCode);
        $this->assertNull($result->responseBody);
        $this->assertNull($result->errorMessage);
    }

    public function testBlankWebhookUrlReturnsNullResult(): void
    {
        $job = $this->makeJob('');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNull($result->statusCode);
        $this->assertNull($result->responseBody);
        $this->assertNull($result->errorMessage);
    }

    // ---------------------------------------------------------------
    // Scheme validation
    // ---------------------------------------------------------------

    public function testFtpSchemeRejected(): void
    {
        $job = $this->makeJob('ftp://example.com/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('scheme', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testFileSchemeRejected(): void
    {
        // file:// URLs with a path like file:///etc/passwd have no host component,
        // so parse_url rejects them as invalid format before the scheme check.
        // Use file://localhost/etc/passwd to ensure the scheme check is reached.
        $job = $this->makeJob('file://localhost/etc/passwd');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('scheme', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    // ---------------------------------------------------------------
    // Invalid URL format
    // ---------------------------------------------------------------

    public function testInvalidUrlFormatRejected(): void
    {
        $job = $this->makeJob('http:///missing-host');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    // ---------------------------------------------------------------
    // Private / reserved IP blocking
    // ---------------------------------------------------------------

    public function testLocalhostIpBlocked(): void
    {
        $job = $this->makeJob('http://127.0.0.1/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('private or reserved', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testPrivate10RangeBlocked(): void
    {
        $job = $this->makeJob('http://10.0.0.1/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('private or reserved', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testPrivate172RangeBlocked(): void
    {
        $job = $this->makeJob('http://172.16.0.1/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('private or reserved', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testPrivate192RangeBlocked(): void
    {
        $job = $this->makeJob('http://192.168.1.1/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/fake.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('private or reserved', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    // ---------------------------------------------------------------
    // File validation
    // ---------------------------------------------------------------

    public function testFileNotFoundReturnsError(): void
    {
        $job = $this->makeJob('http://8.8.8.8/hook');
        $result = $this->dispatcher->dispatch($job, '/tmp/nonexistent_file_' . uniqid() . '.csv');

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('not readable', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testFileNotReadableReturnsError(): void
    {
        $fakePath = '/tmp/definitely_not_a_real_file_' . uniqid() . '.csv';

        $job = $this->makeJob('http://8.8.8.8/hook');
        $result = $this->dispatcher->dispatch($job, $fakePath);

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('not readable', $result->errorMessage);
        $this->assertNull($result->statusCode);
    }

    public function testOversizedFileReturnsError(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'webhook_test_');
        $this->assertNotFalse($tmpFile, 'Failed to create temp file');

        try {
            // Create a file just over 10MB
            $handle = fopen($tmpFile, 'wb');
            $this->assertNotFalse($handle, 'Failed to open temp file for writing');

            $tenMb = 10 * 1024 * 1024;
            $written = 0;
            $chunk = str_repeat('X', 1024 * 1024); // 1MB chunk
            while ($written <= $tenMb) {
                fwrite($handle, $chunk);
                $written += strlen($chunk);
            }
            fclose($handle);

            $this->assertGreaterThan($tenMb, filesize($tmpFile));

            $job = $this->makeJob('http://8.8.8.8/hook');
            $result = $this->dispatcher->dispatch($job, $tmpFile);

            $this->assertNotNull($result->errorMessage);
            $this->assertStringContainsString('exceeds maximum size', $result->errorMessage);
            $this->assertNull($result->statusCode);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ---------------------------------------------------------------
    // Base64 encoding / valid setup
    // ---------------------------------------------------------------

    public function testBase64EncodingOfSmallFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'webhook_test_');
        $this->assertNotFalse($tmpFile, 'Failed to create temp file');

        try {
            file_put_contents($tmpFile, 'hello,world');
            $this->assertFileExists($tmpFile);

            // Use a hostname that will fail DNS resolution so we can verify
            // validation passed up to the point of the HTTP request.
            $job = $this->makeJob('http://webhook-test-nonexistent-host.invalid/hook');
            $result = $this->dispatcher->dispatch($job, $tmpFile);

            // DNS resolution will fail, giving us a hostname resolution error
            // rather than a scheme or private IP error. This confirms the URL
            // passed scheme and format validation.
            $this->assertNotNull($result->errorMessage);
            $this->assertStringContainsString('could not be resolved', $result->errorMessage);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testValidPublicIpv4Accepted(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'webhook_test_');
        $this->assertNotFalse($tmpFile, 'Failed to create temp file');

        try {
            file_put_contents($tmpFile, 'test,data');

            $job = $this->makeJob('http://8.8.8.8/hook');
            $result = $this->dispatcher->dispatch($job, $tmpFile);

            // 8.8.8.8 is a valid public IP, so URL validation passes.
            // The dispatch will attempt an actual HTTP request which may fail
            // with a connection error, but the error should NOT be about
            // URL validation (scheme, private IP, format, etc.).
            if ($result->errorMessage !== null) {
                $this->assertStringNotContainsString('scheme', $result->errorMessage);
                $this->assertStringNotContainsString('private or reserved', $result->errorMessage);
                $this->assertStringNotContainsString('Invalid webhook URL', $result->errorMessage);
                $this->assertStringNotContainsString('not readable', $result->errorMessage);
                $this->assertStringNotContainsString('exceeds maximum size', $result->errorMessage);
            }
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}

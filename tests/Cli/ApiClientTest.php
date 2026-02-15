<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\ApiClient;
use P202Cli\ApiException;
use P202Cli\Config;
use Tests\TestCase;

class ApiClientTest extends TestCase
{
    public function testConstructorAppendsApiV3ToBaseUrl(): void
    {
        $client = new ApiClient('https://example.com', 'test-key');

        // We can verify this by using reflection to inspect the private baseUrl
        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('baseUrl');
        $prop->setAccessible(true);

        $this->assertSame('https://example.com/api/v3', $prop->getValue($client));
    }

    public function testConstructorStripsTrailingSlashBeforeAppending(): void
    {
        $client = new ApiClient('https://example.com/', 'test-key');

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('baseUrl');
        $prop->setAccessible(true);

        $this->assertSame('https://example.com/api/v3', $prop->getValue($client));
    }

    public function testConstructorWithMultipleTrailingSlashes(): void
    {
        $client = new ApiClient('https://example.com///', 'test-key');

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('baseUrl');
        $prop->setAccessible(true);

        $this->assertSame('https://example.com/api/v3', $prop->getValue($client));
    }

    public function testConstructorStoresApiKey(): void
    {
        $client = new ApiClient('https://example.com', 'my-api-key-123');

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('apiKey');
        $prop->setAccessible(true);

        $this->assertSame('my-api-key-123', $prop->getValue($client));
    }

    public function testConstructorDefaultTimeout(): void
    {
        $client = new ApiClient('https://example.com', 'key');

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('timeout');
        $prop->setAccessible(true);

        $this->assertSame(30, $prop->getValue($client));
    }

    public function testConstructorCustomTimeout(): void
    {
        $client = new ApiClient('https://example.com', 'key', 120);

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('timeout');
        $prop->setAccessible(true);

        $this->assertSame(120, $prop->getValue($client));
    }

    public function testFromConfigCreatesClientCorrectly(): void
    {
        $tmpDir = sys_get_temp_dir() . '/p202_api_test_' . uniqid();
        mkdir($tmpDir, 0700, true);
        $origHome = getenv('HOME') ?: '';
        putenv("HOME={$tmpDir}");

        try {
            $config = new Config();
            $config->set('url', 'https://tracker.example.com');
            $config->set('api_key', 'secret-key-456');
            $config->set('timeout', 45);

            $client = ApiClient::fromConfig($config);

            $reflection = new \ReflectionClass($client);

            $baseUrl = $reflection->getProperty('baseUrl');
            $baseUrl->setAccessible(true);
            $this->assertSame('https://tracker.example.com/api/v3', $baseUrl->getValue($client));

            $apiKey = $reflection->getProperty('apiKey');
            $apiKey->setAccessible(true);
            $this->assertSame('secret-key-456', $apiKey->getValue($client));

            $timeout = $reflection->getProperty('timeout');
            $timeout->setAccessible(true);
            $this->assertSame(45, $timeout->getValue($client));
        } finally {
            putenv("HOME={$origHome}");
            // Cleanup
            array_map('unlink', glob($tmpDir . '/.p202/*') ?: []);
            if (is_dir($tmpDir . '/.p202')) {
                rmdir($tmpDir . '/.p202');
            }
            rmdir($tmpDir);
        }
    }

    public function testFromConfigThrowsWhenUrlMissing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/p202_api_test_' . uniqid();
        mkdir($tmpDir, 0700, true);
        $origHome = getenv('HOME') ?: '';
        putenv("HOME={$tmpDir}");

        try {
            $config = new Config();
            $config->set('api_key', 'some-key');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No URL configured');
            ApiClient::fromConfig($config);
        } finally {
            putenv("HOME={$origHome}");
            rmdir($tmpDir);
        }
    }

    public function testFromConfigThrowsWhenApiKeyMissing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/p202_api_test_' . uniqid();
        mkdir($tmpDir, 0700, true);
        $origHome = getenv('HOME') ?: '';
        putenv("HOME={$tmpDir}");

        try {
            $config = new Config();
            $config->set('url', 'https://example.com');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No API key configured');
            ApiClient::fromConfig($config);
        } finally {
            putenv("HOME={$origHome}");
            rmdir($tmpDir);
        }
    }

    public function testFromConfigDefaultTimeoutWhenNotSet(): void
    {
        $tmpDir = sys_get_temp_dir() . '/p202_api_test_' . uniqid();
        mkdir($tmpDir, 0700, true);
        $origHome = getenv('HOME') ?: '';
        putenv("HOME={$tmpDir}");

        try {
            $config = new Config();
            $config->set('url', 'https://example.com');
            $config->set('api_key', 'key');

            $client = ApiClient::fromConfig($config);

            $reflection = new \ReflectionClass($client);
            $timeout = $reflection->getProperty('timeout');
            $timeout->setAccessible(true);
            $this->assertSame(30, $timeout->getValue($client));
        } finally {
            putenv("HOME={$origHome}");
            rmdir($tmpDir);
        }
    }

    // --- ApiException tests ---

    public function testApiExceptionStoresResponseData(): void
    {
        $data = [
            'message' => 'Validation failed',
            'field_errors' => [
                'email' => 'Email is invalid',
                'name' => 'Name is required',
            ],
        ];

        $exception = new ApiException('Validation failed', 422, $data);
        $this->assertSame($data, $exception->responseData);
    }

    public function testApiExceptionMessageAndCode(): void
    {
        $exception = new ApiException('Not Found', 404, ['error' => 'Resource not found']);
        $this->assertSame('Not Found', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
    }

    public function testApiExceptionWithEmptyResponseData(): void
    {
        $exception = new ApiException('Internal Server Error', 500);
        $this->assertSame([], $exception->responseData);
        $this->assertSame('Internal Server Error', $exception->getMessage());
        $this->assertSame(500, $exception->getCode());
    }

    public function testApiExceptionExtendsRuntimeException(): void
    {
        $exception = new ApiException('test', 400);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testApiExceptionResponseDataIsPublic(): void
    {
        $exception = new ApiException('error', 400, ['key' => 'value']);
        // Verify the property is public by accessing it directly
        $this->assertSame(['key' => 'value'], $exception->responseData);

        // Also verify via reflection
        $reflection = new \ReflectionProperty(ApiException::class, 'responseData');
        $this->assertTrue($reflection->isPublic());
    }
}

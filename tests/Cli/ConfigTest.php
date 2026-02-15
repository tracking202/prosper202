<?php

declare(strict_types=1);

namespace Tests\Cli;

use P202Cli\Config;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    private string $tmpDir;
    private string $origHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/p202_config_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // Preserve and override HOME so Config uses our temp directory
        $this->origHome = getenv('HOME') ?: '';
        putenv("HOME={$this->tmpDir}");
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tmpDir);

        // Restore HOME
        putenv("HOME={$this->origHome}");

        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $config = new Config();
        $this->assertNull($config->get('nonexistent'));
        $this->assertSame('fallback', $config->get('nonexistent', 'fallback'));
        $this->assertSame(42, $config->get('missing_key', 42));
    }

    public function testSetThenGetReturnsValue(): void
    {
        $config = new Config();
        $config->set('url', 'https://example.com');
        $this->assertSame('https://example.com', $config->get('url'));

        // Overwrite existing key
        $config->set('url', 'https://other.com');
        $this->assertSame('https://other.com', $config->get('url'));
    }

    public function testSaveAndReloadRoundTrip(): void
    {
        $config = new Config();
        $config->set('url', 'https://example.com');
        $config->set('api_key', 'test-key-abc123');
        $config->set('timeout', 60);
        $config->save();

        // Create a new Config instance that reads from disk
        $config2 = new Config();
        $this->assertSame('https://example.com', $config2->get('url'));
        $this->assertSame('test-key-abc123', $config2->get('api_key'));
        $this->assertSame(60, $config2->get('timeout'));
    }

    public function testGetUrlReturnsUrlConfigValue(): void
    {
        $config = new Config();
        $config->set('url', 'https://tracker.example.com/');
        // getUrl() should rtrim trailing slashes
        $this->assertSame('https://tracker.example.com', $config->getUrl());
    }

    public function testGetUrlReturnsEmptyStringWhenNotSet(): void
    {
        $config = new Config();
        $this->assertSame('', $config->getUrl());
    }

    public function testGetApiKeyReturnsApiKeyConfigValue(): void
    {
        $config = new Config();
        $config->set('api_key', 'my-secret-key');
        $this->assertSame('my-secret-key', $config->getApiKey());
    }

    public function testGetApiKeyReturnsEmptyStringWhenNotSet(): void
    {
        $config = new Config();
        $this->assertSame('', $config->getApiKey());
    }

    public function testConfigPathReturnsExpectedPath(): void
    {
        $config = new Config();
        $expected = $this->tmpDir . '/.p202/config.json';
        $this->assertSame($expected, $config->configPath());
    }

    public function testLoadingNonexistentConfigFileDoesNotCrash(): void
    {
        // HOME points to tmpDir which has no .p202 dir yet
        $config = new Config();
        $this->assertSame([], $config->all());
        // Should be fully functional despite no file on disk
        $config->set('key', 'value');
        $this->assertSame('value', $config->get('key'));
    }

    public function testSaveCreatesDirectoryIfNeeded(): void
    {
        $config = new Config();
        $config->set('url', 'https://tracker.example.com');
        $config->save();

        $this->assertDirectoryExists($this->tmpDir . '/.p202');
        $this->assertFileExists($this->tmpDir . '/.p202/config.json');
    }

    public function testSavedConfigFileContainsValidJson(): void
    {
        $config = new Config();
        $config->set('url', 'https://tracker.example.com');
        $config->set('api_key', 'key123');
        $config->save();

        $contents = file_get_contents($this->tmpDir . '/.p202/config.json');
        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://tracker.example.com', $decoded['url']);
        $this->assertSame('key123', $decoded['api_key']);
    }

    public function testAllReturnsAllData(): void
    {
        $config = new Config();
        $config->set('url', 'https://example.com');
        $config->set('api_key', 'key');
        $all = $config->all();
        $this->assertSame(['url' => 'https://example.com', 'api_key' => 'key'], $all);
    }

    public function testGetUrlStripsMultipleTrailingSlashes(): void
    {
        $config = new Config();
        $config->set('url', 'https://example.com///');
        $this->assertSame('https://example.com', $config->getUrl());
    }
}

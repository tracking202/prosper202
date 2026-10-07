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

    /** ~/.p202/config.json as the Go CLI writes it. */
    private function writeGoConfig(array $data): string
    {
        mkdir($this->tmpDir . '/.p202', 0700, true);
        $path = $this->tmpDir . '/.p202/config.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Both CLIs read ~/.p202/config.json. The Go CLI keeps the server under
     * profiles.<active_profile>, and folds this CLI's top-level url and
     * api_key into that profile on its next save, clearing them — after
     * which this CLI read no url and no key at all.
     */
    public function testTheGoCliActiveProfileIsReadFromTheSharedFile(): void
    {
        $this->writeGoConfig([
            'active_profile' => 'prod',
            'profiles' => [
                'default' => ['url' => 'https://staging.example', 'api_key' => 'staging-key-123456'],
                'prod' => ['url' => 'https://prod.example/', 'api_key' => 'prod-key-1234567890'],
            ],
        ]);

        $config = new Config();
        $this->assertSame('https://prod.example', $config->getUrl());
        $this->assertSame('prod-key-1234567890', $config->getApiKey());
        $this->assertSame('prod', $config->profileName());
    }

    public function testAWriteGoesToTheActiveProfileAndLeavesTheOthers(): void
    {
        $path = $this->writeGoConfig([
            'active_profile' => 'prod',
            'profiles' => [
                'default' => ['url' => 'https://staging.example', 'api_key' => 'staging-key-123456', 'tags' => ['x']],
                'prod' => ['url' => 'https://prod.example', 'api_key' => 'prod-key-1234567890'],
            ],
        ]);

        $config = new Config();
        $config->set('url', 'https://new.example');
        $config->save();

        $saved = json_decode((string) file_get_contents($path), true);
        $this->assertSame('https://new.example', $saved['profiles']['prod']['url']);
        $this->assertSame('prod-key-1234567890', $saved['profiles']['prod']['api_key']);
        $this->assertSame(
            ['url' => 'https://staging.example', 'api_key' => 'staging-key-123456', 'tags' => ['x']],
            $saved['profiles']['default'],
            'another profile is untouched'
        );
        $this->assertArrayNotHasKey('url', $saved, 'no top-level copy for the Go CLI to fold over the profile');
        $this->assertSame('https://new.example', (new Config())->getUrl());
    }

    public function testAProfileFileWithNoActiveProfileUsesDefault(): void
    {
        $this->writeGoConfig(['profiles' => ['default' => ['url' => 'https://d.example', 'api_key' => 'k']]]);
        $this->assertSame('https://d.example', (new Config())->getUrl());
        $this->assertSame('default', (new Config())->profileName());
    }

    public function testAFileWithoutProfilesKeepsTheTopLevelKeys(): void
    {
        $path = $this->tmpDir . '/.p202/config.json';
        $config = new Config();
        $config->set('url', 'https://legacy.example');
        $config->save();
        $this->assertSame(['url' => 'https://legacy.example'], json_decode((string) file_get_contents($path), true));
        $this->assertNull((new Config())->profileName());
    }
}

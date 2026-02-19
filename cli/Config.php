<?php

declare(strict_types=1);

namespace P202Cli;

class Config
{
    private readonly string $configDir;
    private readonly string $configFile;
    private array $data = [];

    public function __construct()
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $this->configDir = $home . '/.p202';
        $this->configFile = $this->configDir . '/config.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->configFile)) {
            $json = file_get_contents($this->configFile);
            $this->data = json_decode($json, true) ?: [];
        }
    }

    public function save(): void
    {
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, true);
        }
        $oldUmask = umask(0077);
        file_put_contents(
            $this->configFile,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        chmod($this->configFile, 0600);
        umask($oldUmask);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getUrl(): string
    {
        return rtrim((string) $this->get('url', ''), '/');
    }

    public function getApiKey(): string
    {
        return $this->get('api_key', '');
    }

    public function all(): array
    {
        return $this->data;
    }

    public function configPath(): string
    {
        return $this->configFile;
    }
}

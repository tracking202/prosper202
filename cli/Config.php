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
        if (!file_exists($this->configFile)) {
            return;
        }

        $json = file_get_contents($this->configFile);
        if ($json === false) {
            throw new \RuntimeException("Unable to read config file: {$this->configFile}");
        }
        if (trim($json) === '') {
            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            // A corrupt config must not be silently treated as empty — the
            // next save would overwrite it and destroy the remaining keys
            // (api_key, url) without the user ever knowing.
            throw new \RuntimeException(
                "Config file {$this->configFile} contains invalid JSON. "
                . 'Fix or remove it, then re-run configuration.'
            );
        }
        $this->data = $decoded;
    }

    public function save(): void
    {
        if (!is_dir($this->configDir) && !mkdir($this->configDir, 0700, true) && !is_dir($this->configDir)) {
            throw new \RuntimeException("Unable to create config directory: {$this->configDir}");
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode config: ' . json_last_error_msg());
        }

        // Write to a temp file and rename so a killed process can never leave
        // a truncated config.json behind.
        $tmp = $this->configFile . '.tmp';
        $oldUmask = umask(0077);
        try {
            if (file_put_contents($tmp, $json . "\n") === false) {
                throw new \RuntimeException("Unable to write config file: {$this->configFile}");
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $this->configFile)) {
                throw new \RuntimeException("Unable to finalize config file: {$this->configFile}");
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            umask($oldUmask);
        }
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

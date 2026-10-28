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

    /**
     * The keys that name a server, which the Go CLI keeps per profile.
     *
     * Both CLIs read and write ~/.p202/config.json. This one kept `url` and
     * `api_key` at the top level; the Go CLI keeps them under
     * `profiles.<active_profile>` and folds top-level ones into that profile
     * on its next save, clearing them. So after any Go CLI write this CLI
     * found no url and no key ("not configured"), and a url set here
     * replaced the Go CLI's on its next save. They are read from and written
     * to the active profile now whenever the file has profiles, which is the
     * same place both CLIs end up with.
     */
    private const PROFILE_KEYS = ['url', 'api_key'];

    /** The profile the Go CLI would use, when the file has profiles. */
    private function activeProfile(): ?string
    {
        if (!isset($this->data['profiles']) || !is_array($this->data['profiles'])) {
            return null;
        }
        $name = $this->data['active_profile'] ?? '';

        return is_string($name) && trim($name) !== '' ? trim($name) : 'default';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $profile = $this->activeProfile();
        if ($profile !== null && in_array($key, self::PROFILE_KEYS, true)) {
            $value = $this->data['profiles'][$profile][$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $profile = $this->activeProfile();
        if ($profile !== null && in_array($key, self::PROFILE_KEYS, true)) {
            if (!is_array($this->data['profiles'][$profile] ?? null)) {
                $this->data['profiles'][$profile] = [];
            }
            $this->data['profiles'][$profile][$key] = $value;
            // No top-level copy for the Go CLI to fold over the profile.
            unset($this->data[$key]);

            return;
        }
        $this->data[$key] = $value;
    }

    /** The active profile's name, or null for a file with no profiles. */
    public function profileName(): ?string
    {
        return $this->activeProfile();
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

<?php

declare(strict_types=1);

namespace Prosper202\Security;

use Prosper202\Database\Connection;

/**
 * The key-encryption key of the integration secrets store
 * (IntegrationSecretStore), and the one place that decides where it comes
 * from. In order:
 *
 * 1. the environment variable P202_SECRETS_KEY: 64 hexadecimal characters
 *    (`openssl rand -hex 32`);
 * 2. the environment variable P202_SECRETS_KEY_FILE, or the constant of that
 *    name in 202-config.php (which the web server and the cron read alike):
 *    the absolute path of a file holding such a key;
 * 3. with none of them set, the documented fallback: a key minted once in
 *    202_deployment_secrets (`integration_secrets`), which lives in the same
 *    database as what it seals.
 *
 * More than one set is refused, as is a set one that cannot be read: a key
 * the operator configured and this process cannot use never falls back to
 * the database's key (CLAUDE.md #11). Each sealed row names its key by id
 * (keyId()), so a row is opened only by the key that sealed it, and one
 * sealed by a key this process does not have is an error naming that key.
 */
final class SecretsKeyring
{
    public const ENV_KEY = 'P202_SECRETS_KEY';
    public const ENV_FILE = 'P202_SECRETS_KEY_FILE';
    public const DATABASE_KEY_NAME = 'integration_secrets';
    public const EXTERNAL = 'external';
    public const DATABASE = 'database';

    private const MAX_FILE_BYTES = 4096;

    /** @var array{source: string, id: string, key: string}|\RuntimeException|null|false false: not resolved yet */
    private array|\RuntimeException|null|false $external = false;

    /**
     * @param \Closure(string): (string|false) $env an environment variable, false when it is not set
     * @param \Closure(string): mixed $constant a constant's value, null when it is not defined
     */
    public function __construct(
        private readonly Connection $conn,
        private readonly \Closure $env,
        private readonly \Closure $constant
    ) {
    }

    public static function fromEnvironment(Connection $conn): self
    {
        return new self(
            $conn,
            static fn (string $name): string|false => getenv($name),
            static fn (string $name): mixed => defined($name) ? constant($name) : null
        );
    }

    /** The id a sealed row records: the key's kind and a fingerprint that does not reveal it. */
    public static function keyId(string $kind, #[\SensitiveParameter] string $key): string
    {
        return $kind . ':' . substr(hash_hmac('sha256', 'p202-secrets-key-id-v1', $key), 0, 16);
    }

    public static function kindOf(string $keyId): string
    {
        return explode(':', $keyId, 2)[0];
    }

    /**
     * Where new seals take their key from: P202_SECRETS_KEY, P202_SECRETS_KEY_FILE, the 202-config.php constant,
     * or "database".
     *
     * @throws MalformedSecretKey when a configured key cannot be read
     */
    public function source(): string
    {
        return $this->external()['source'] ?? self::DATABASE;
    }

    /**
     * The key new values are sealed with, and its id: the external one when it is configured, otherwise the
     * database's, minted first if absent.
     *
     * @return array{0: string, 1: string} [id, key]
     * @throws MalformedSecretKey when a configured key cannot be read
     */
    public function sealingKey(): array
    {
        $external = $this->external();
        if ($external !== null) {
            return [$external['id'], $external['key']];
        }
        SealedSecret::mintKey($this->conn, self::DATABASE_KEY_NAME);
        $key = $this->databaseKey();
        if ($key === null) {
            throw new \RuntimeException('The integration secrets key was minted in 202_deployment_secrets but cannot be read back');
        }

        return [self::keyId(self::DATABASE, $key), $key];
    }

    /**
     * The key a row sealed under $keyId opens with.
     *
     * @throws SecretKeyUnavailable naming the key when this process does not have it
     * @throws MalformedSecretKey when a configured key, or the database's, cannot be read
     */
    public function keyFor(string $keyId): string
    {
        // A configured key that cannot be read stops every open, the database key's too: nothing reads past it.
        $external = $this->external();
        $kind = self::kindOf($keyId);
        if ($kind === self::EXTERNAL) {
            if ($external === null) {
                throw new SecretKeyUnavailable('it is sealed with the external key ' . $keyId . ', and neither '
                    . self::ENV_KEY . ' nor ' . self::ENV_FILE . ' is set for this process');
            }
            if (!hash_equals($external['id'], $keyId)) {
                throw new SecretKeyUnavailable('it is sealed with the external key ' . $keyId . ', and the key '
                    . $external['source'] . ' gives is ' . $external['id']);
            }

            return $external['key'];
        }
        if ($kind === self::DATABASE) {
            $key = $this->databaseKey();
            if ($key === null) {
                throw new SecretKeyUnavailable('it is sealed with the database key ' . $keyId . ', which is missing from'
                    . ' 202_deployment_secrets (' . self::DATABASE_KEY_NAME . ')');
            }
            if (!hash_equals(self::keyId(self::DATABASE, $key), $keyId)) {
                throw new SecretKeyUnavailable('it is sealed with the database key ' . $keyId . ', and the key in'
                    . ' 202_deployment_secrets is ' . self::keyId(self::DATABASE, $key));
            }

            return $key;
        }

        throw new SecretKeyUnavailable('it names the key "' . $keyId . '", which is not an external or a database key');
    }

    /**
     * The configured external key, or null when none is configured.
     *
     * @return array{source: string, id: string, key: string}|null
     * @throws MalformedSecretKey
     */
    private function external(): ?array
    {
        if ($this->external === false) {
            try {
                $this->external = $this->resolveExternal();
            } catch (MalformedSecretKey $e) {
                $this->external = $e;
            }
        }
        if ($this->external instanceof \RuntimeException) {
            throw $this->external;
        }

        return $this->external;
    }

    /**
     * @return array{source: string, id: string, key: string}|null
     * @throws MalformedSecretKey
     */
    private function resolveExternal(): ?array
    {
        $set = [];
        $inline = ($this->env)(self::ENV_KEY);
        if ($inline !== false) {
            $set[self::ENV_KEY] = static fn (): string => self::parse((string) $inline, self::ENV_KEY);
        }
        $file = ($this->env)(self::ENV_FILE);
        if ($file !== false) {
            $set[self::ENV_FILE] = static fn (): string => self::readFile((string) $file, self::ENV_FILE);
        }
        $constant = ($this->constant)(self::ENV_FILE);
        if ($constant !== null) {
            $label = 'the ' . self::ENV_FILE . ' constant in 202-config.php';
            $set[$label] = static fn (): string => self::readFile(is_string($constant) ? $constant : '', $label);
        }
        if ($set === []) {
            return null;
        }
        if (count($set) > 1) {
            throw new MalformedSecretKey('More than one integration secrets key is configured (' . implode(', ', array_keys($set))
                . '); set exactly one');
        }
        $source = (string) array_key_first($set);
        $key = $set[$source]();

        return ['source' => $source, 'id' => self::keyId(self::EXTERNAL, $key), 'key' => $key];
    }

    /** @throws MalformedSecretKey naming $from, never the value */
    private static function parse(#[\SensitiveParameter] string $value, string $from): string
    {
        $hex = trim($value, " \t\n\r\0\x0B");
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $hex) !== 1) {
            throw new MalformedSecretKey($from . ' is set but is not 64 hexadecimal characters (a 32-byte key; make one'
                . ' with `openssl rand -hex 32`); nothing is sealed or opened with another key until it is');
        }

        return (string) hex2bin($hex);
    }

    /** @throws MalformedSecretKey naming the file */
    private static function readFile(string $path, string $from): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new MalformedSecretKey($from . ' is set but is not an absolute path to the key file');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new MalformedSecretKey($from . ' names ' . $path . ', which is not a file this process can read');
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if (!is_string($contents) || strlen($contents) > self::MAX_FILE_BYTES) {
            throw new MalformedSecretKey($from . ' names ' . $path . ', which could not be read as a key');
        }

        return self::parse($contents, $from . ' (' . $path . ')');
    }

    /** @throws \RuntimeException for a stored key that is not 64 lower-case hex characters */
    private function databaseKey(): ?string
    {
        return SealedSecret::readKey($this->conn, self::DATABASE_KEY_NAME);
    }
}

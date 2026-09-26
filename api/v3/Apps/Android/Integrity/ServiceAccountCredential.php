<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

use Api\V3\Exception\ValidationException;

/**
 * The operator's Google service account, as the Play Integrity client needs
 * it: the key file Google Cloud's console downloads (type
 * `service_account`), reduced to what signing an OAuth token takes.
 *
 * Validation is strict (CLAUDE.md #4): the fields must be there and typed,
 * the private key must be an RSA key openssl can load, and a `token_uri`
 * other than Google's own is refused rather than followed — the client
 * sends its signed assertion only to the pinned endpoint
 * (GooglePlayIntegrityClient::TOKEN_URL), and a file that names another is
 * not a Google credential. Fields Google adds to the file that the client
 * does not need are ignored, never stored.
 *
 * The private key is never part of anything this class shows: summary()
 * carries the account's email, key id and project only.
 */
final class ServiceAccountCredential
{
    public const MAX_BYTES = 16384;

    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKeyId,
        #[\SensitiveParameter]
        public readonly string $privateKey,
        public readonly ?string $projectId,
    ) {
    }

    /**
     * @param mixed $value the decoded key file (a JSON object)
     * @throws ValidationException naming the field at fault, never echoing the key
     */
    public static function fromKeyFile(mixed $value, string $field = 'credential'): self
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ValidationException('The credential must be a service-account key file', [
                $field => 'must be the JSON object of a Google service-account key file (type "service_account")',
            ]);
        }
        $e = [];
        if (($value['type'] ?? null) !== 'service_account') {
            $e[$field . '.type'] = 'must be "service_account": create a key for a service account in Google Cloud (IAM › Service accounts › Keys › JSON)';
        }
        $email = $value['client_email'] ?? null;
        if (!is_string($email) || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $e[$field . '.client_email'] = 'is required: the service account\'s email address';
        }
        $keyId = $value['private_key_id'] ?? null;
        if (!is_string($keyId) || preg_match('/^[0-9a-f]{8,64}$/D', $keyId) !== 1) {
            $e[$field . '.private_key_id'] = 'is required: the key\'s id (hexadecimal)';
        }
        $project = $value['project_id'] ?? null;
        if ($project !== null && (!is_string($project) || preg_match('/^[a-z][a-z0-9-]{3,62}$/D', $project) !== 1)) {
            $e[$field . '.project_id'] = 'must be a Google Cloud project id';
        }
        $tokenUri = $value['token_uri'] ?? null;
        if ($tokenUri !== null && $tokenUri !== GooglePlayIntegrityClient::TOKEN_URL) {
            $e[$field . '.token_uri'] = 'must be ' . GooglePlayIntegrityClient::TOKEN_URL . ' (the only token endpoint this server sends a signed assertion to)';
        }
        $pem = $value['private_key'] ?? null;
        if (!is_string($pem) || !self::isRsaPrivateKey($pem)) {
            $e[$field . '.private_key'] = 'is required: the RSA private key (PEM) from the key file';
        }
        if ($e !== []) {
            ksort($e);
            throw new ValidationException('The service-account key file is invalid', $e);
        }

        return new self((string) $email, (string) $keyId, (string) $pem, $project === null ? null : (string) $project);
    }

    /** The encrypted form's plaintext: the four fields, as JSON. */
    public function toStoredJson(): string
    {
        return json_encode([
            'client_email' => $this->clientEmail,
            'private_key_id' => $this->privateKeyId,
            'private_key' => $this->privateKey,
            'project_id' => $this->projectId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Read back a decrypted plaintext. A plaintext that does not parse is a
     * corrupt row, and throws (it is never read as "no credential").
     */
    public static function fromStoredJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('not an object');
            }

            return self::fromKeyFile(['type' => 'service_account'] + $decoded);
        } catch (\JsonException | ValidationException | \RuntimeException $e) {
            throw new \RuntimeException('The stored Play Integrity credential is not a readable service account', 0, $e);
        }
    }

    /** @return array{client_email: string, private_key_id: string, project_id: string|null} */
    public function summary(): array
    {
        return ['client_email' => $this->clientEmail, 'private_key_id' => $this->privateKeyId, 'project_id' => $this->projectId];
    }

    /** What an access token is cached under: the account and its exact key. */
    public function cacheKey(): string
    {
        return hash('sha256', $this->clientEmail . "\0" . $this->privateKeyId . "\0" . $this->privateKey);
    }

    private static function isRsaPrivateKey(#[\SensitiveParameter] string $pem): bool
    {
        if (strlen($pem) > self::MAX_BYTES || !str_contains($pem, 'PRIVATE KEY')) {
            return false;
        }
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) >= 2048;
    }

    public function __debugInfo(): array
    {
        return $this->summary() + ['private_key' => '[redacted]'];
    }
}

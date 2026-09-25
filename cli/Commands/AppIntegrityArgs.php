<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Argument checks the app:integrity:* commands share, so the same typo is
 * the same error on each of them, before any request.
 */
final class AppIntegrityArgs
{
    public const MODES = ['off', 'observe', 'require'];

    private function __construct()
    {
    }

    public static function registrationId(InputInterface $input): string
    {
        $id = (string) $input->getArgument('registration_id');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
            throw new \RuntimeException('The registration id must be a positive whole number, got "' . $id . '" (the Go CLI\'s `p202 app list --platform android` lists them).');
        }

        return $id;
    }

    /**
     * The service-account key file as a JSON object. Errors never quote the
     * content: it is a private key.
     *
     * @return array<string, mixed>
     */
    public static function keyFile(string $path): array
    {
        if ($path === '') {
            throw new \RuntimeException('--file is required: the service-account key file (JSON) Google Cloud downloaded.');
        }
        $raw = @file_get_contents($path, false, null, 0, 65536);
        if (!is_string($raw)) {
            throw new \RuntimeException('Could not read ' . $path . '.');
        }
        try {
            $key = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The key file is not JSON; use the JSON key exactly as Google Cloud downloaded it.');
        }
        if (!is_array($key) || array_is_list($key)) {
            throw new \RuntimeException('The key file is not a JSON object; use the JSON key exactly as Google Cloud downloaded it.');
        }
        if (($key['type'] ?? null) !== 'service_account') {
            throw new \RuntimeException('The key file is not a service account\'s (type must be "service_account"); create a JSON key for a service account in Google Cloud IAM.');
        }

        return $key;
    }
}

<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Click\ClickRequest;
use Prosper202\Database\Connection;

/**
 * A redirector's "High fraud score" criterion: whether IPQualityScore scores
 * the visitor 80 or more with the account's key (Settings › API
 * Integrations). As in 1.9.56, no key or no answer reads as not fraud; a
 * failed answer is logged, so a rule that stopped matching can be traced.
 */
final class FraudScore
{
    private function __construct()
    {
    }

    /** The account's IPQualityScore key, '' when none is set. */
    public static function accountKey(Connection $conn, int $userId): string
    {
        $stmt = $conn->prepareRead('SELECT ipqs_api_key FROM 202_users_pref WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return trim((string) ($row['ipqs_api_key'] ?? ''));
    }

    /**
     * @param array<array-key, mixed> $server
     * @param (\Closure(array<string, mixed>): \FraudDetectionInterface)|null $detector the IPQS client, for tests
     */
    public static function isHigh(string $key, string $ip, array $server, ?\Closure $detector = null): bool
    {
        if (trim($key) === '' || $ip === '') {
            return false;
        }
        $config = [
            'key' => trim($key),
            'ip' => (object) ['address' => $ip],
            'user_agent' => ClickRequest::text($server, 'HTTP_USER_AGENT'),
            'language' => ClickRequest::text($server, 'HTTP_ACCEPT_LANGUAGE'),
        ];
        try {
            $client = $detector !== null ? $detector($config) : new \FraudDetectionIPQS($config);

            return $client->isFraud($config['ip']) === true;
        } catch (\Throwable $e) {
            error_log('Redirector fraud rule: IPQualityScore gave no score (' . $e->getMessage() . '); read as not fraud');

            return false;
        }
    }
}

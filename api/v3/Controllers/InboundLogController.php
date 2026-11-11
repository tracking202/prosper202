<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\QueryInt;
use Prosper202\InboundLog\InboundLog;

/**
 * GET /conversions/inbound-log: the postbacks, pixels and payment
 * notifications this install answered, newest first, with why each was
 * refused (Prosper202\InboundLog). The same list as Setup › Inbound Log.
 */
final class InboundLogController
{
    public const PARAMS = ['outcome', 'endpoint', 'limit', 'before'];

    /**
     * @param bool $seesUnattributed whether the requests no account was found for are listed too (admins)
     * @param bool $seesUnverifiedShopify whether the Shopify webhooks no secret verified are listed, without
     *        their fields (an account that stores a Shopify secret)
     */
    public function __construct(
        private readonly \mysqli $db,
        private readonly int $userId,
        private readonly bool $seesUnattributed,
        private readonly bool $seesUnverifiedShopify = false,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: list<array<string, mixed>>, next_before: int|null, kept_per_account: int}
     */
    public function list(array $params): array
    {
        $filters = self::filters($params);
        $limit = QueryInt::param($params, 'limit', InboundLog::DEFAULT_LIMIT, 1, InboundLog::MAX_LIMIT, 'requests to list');
        $rows = (new InboundLog($this->db))->list(
            $this->userId,
            $this->seesUnattributed,
            $filters,
            $limit,
            $this->seesUnverifiedShopify
        );
        $last = $rows === [] ? null : $rows[count($rows) - 1]['inbound_id'];

        return [
            'data' => $rows,
            'next_before' => count($rows) === $limit ? $last : null,
            'kept_per_account' => InboundLog::KEEP_PER_ACCOUNT,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{outcome?: string, endpoint?: string, before?: int}
     */
    public static function filters(array $params): array
    {
        $errors = [];
        foreach (array_keys($params) as $name) {
            if (!in_array($name, self::PARAMS, true)) {
                $errors[(string) $name] = 'is not a filter of the inbound log; it takes ' . implode(', ', self::PARAMS);
            }
        }
        $filters = [];
        $outcome = $params['outcome'] ?? null;
        if ($outcome !== null && $outcome !== '') {
            if (!is_string($outcome) || preg_match('/^[a-z_]{1,32}$/D', $outcome) !== 1) {
                $errors['outcome'] = 'refused, accepted, or one outcome name (recorded, unknown_click, unverified, ...)';
            } else {
                $filters['outcome'] = $outcome;
            }
        }
        $endpoint = $params['endpoint'] ?? null;
        if ($endpoint !== null && $endpoint !== '') {
            if (!is_string($endpoint) || !in_array($endpoint, InboundLog::ENDPOINTS, true)) {
                $errors['endpoint'] = 'one of ' . implode(', ', InboundLog::ENDPOINTS);
            } else {
                $filters['endpoint'] = $endpoint;
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid inbound log filter', $errors);
        }
        if (array_key_exists('before', $params) && $params['before'] !== '' && $params['before'] !== null) {
            $filters['before'] = QueryInt::param($params, 'before', 0, 1, PHP_INT_MAX, 'the next_before of the previous page');
        }

        return $filters;
    }
}

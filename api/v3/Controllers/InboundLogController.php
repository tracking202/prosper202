<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\QueryInt;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\InboundReplay;

/**
 * GET /conversions/inbound-log: the postbacks, pixels and payment
 * notifications this install answered, newest first, with why each was
 * refused (Prosper202\InboundLog). The same list as Setup › Inbound Log.
 */
final class InboundLogController
{
    public const PARAMS = ['outcome', 'endpoint', 'search', 'limit', 'before'];

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
     * POST /conversions/inbound-log/{id}/record: the conversion of a refused
     * server postback, recorded from the log (InboundReplay). Sent twice it
     * records once; a row that cannot be recorded is a 422 saying why.
     *
     * @return array{data: array{status: string, message: string, conversion_id: int|null, click_id: int|null, inbound_id: int}}
     */
    public function recordNow(int $inboundId): array
    {
        $result = (new InboundReplay($this->db))->replay($this->userId, $inboundId);
        $data = ['data' => $result + ['inbound_id' => $inboundId]];

        return match ($result['status']) {
            'recorded' => ['_status' => 201] + $data,
            'duplicate', 'already_recorded' => $data,
            'not_found' => throw new NotFoundException($result['message']),
            'in_progress' => throw new ConflictException($result['message']),
            'failed' => throw new DatabaseException($result['message']),
            default => throw new ValidationException($result['message'], ['id' => $result['message']]),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array{outcome?: string, endpoint?: string, search?: string, before?: int}
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
        $search = $params['search'] ?? null;
        if ($search !== null && $search !== '') {
            if (!is_string($search) || trim($search) === '' || mb_strlen(trim($search), 'UTF-8') > InboundLog::MAX_SEARCH) {
                $errors['search'] = 'a subid (click id), conversion id or transaction id, whole, at most ' . InboundLog::MAX_SEARCH . ' characters';
            } else {
                $filters['search'] = trim($search);
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

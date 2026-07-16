<?php

declare(strict_types=1);

namespace Tracking202\Report\Json;

use InvalidArgumentException;
use JsonException;

final class ReportDispatchRequest
{
    /**
     * Keep the JSON dispatcher limited to the flat report family for now.
     *
     * @var list<string>
     */
    private const SUPPORTED_REPORT_TYPES = [
        'keyword',
        'textad',
        'referer',
        'ip',
        'country',
        'region',
        'city',
        'isp',
        'landingpage',
        'device',
        'browser',
        'platform',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'reportType',
        'offset',
        'order',
        'includeDependentFilters',
    ];

    /**
     * The legacy flat reports all route through the same sort token family.
     *
     * @var list<string>
     */
    private const SUPPORTED_ORDER_TOKENS = [
        '',
        'sort_breakdown_clicks asc',
        'sort_breakdown_clicks desc',
        'sort_breakdown_click_throughs asc',
        'sort_breakdown_click_throughs desc',
        'sort_breakdown_ctr asc',
        'sort_breakdown_ctr desc',
        'sort_breakdown_leads asc',
        'sort_breakdown_leads desc',
        'sort_breakdown_su_ratio asc',
        'sort_breakdown_su_ratio desc',
        'sort_breakdown_payout asc',
        'sort_breakdown_payout desc',
        'sort_breakdown_epc asc',
        'sort_breakdown_epc desc',
        'sort_breakdown_cpc asc',
        'sort_breakdown_cpc desc',
        'sort_breakdown_income asc',
        'sort_breakdown_income desc',
        'sort_breakdown_cost asc',
        'sort_breakdown_cost desc',
        'sort_breakdown_net asc',
        'sort_breakdown_net desc',
        'sort_breakdown_roi asc',
        'sort_breakdown_roi desc',
    ];

    public function __construct(
        public readonly string $reportType,
        public readonly int $offset,
        public readonly string $order,
        public readonly bool $includeDependentFilters
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== 'POST') {
            throw new InvalidArgumentException('Only POST is supported for report dispatch.', 405);
        }

        $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new InvalidArgumentException('Content-Type must be application/json.', 415);
        }

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || trim($rawBody) === '') {
            throw new InvalidArgumentException('Request body must contain a JSON object.', 400);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Request body contains invalid JSON.', 400, $e);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Request body must decode to a JSON object.', 400);
        }

        return self::fromArray($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $unknownKeys = array_diff(array_keys($payload), self::ALLOWED_KEYS);
        if ($unknownKeys !== []) {
            sort($unknownKeys);
            throw new InvalidArgumentException(
                'Unsupported request field(s): ' . implode(', ', $unknownKeys) . '.',
                422
            );
        }

        $reportType = trim((string) ($payload['reportType'] ?? ''));
        if ($reportType === '') {
            throw new InvalidArgumentException('reportType is required.', 422);
        }

        if (!in_array($reportType, self::SUPPORTED_REPORT_TYPES, true)) {
            throw new InvalidArgumentException(
                'Unsupported reportType "' . $reportType . '".',
                422
            );
        }

        // array_key_exists, not ??: an omitted field takes the default, but a field the
        // client explicitly sent as null is malformed input and must be rejected rather
        // than silently coalesced (CLAUDE.md #4).
        $offset = array_key_exists('offset', $payload)
            ? self::parseOffset($payload['offset'])
            : 0;
        $order = array_key_exists('order', $payload)
            ? self::parseOrder($payload['order'])
            : '';
        $includeDependentFilters = array_key_exists('includeDependentFilters', $payload)
            ? self::parseBoolean($payload['includeDependentFilters'], 'includeDependentFilters')
            : true;

        return new self($reportType, $offset, $order, $includeDependentFilters);
    }

    /**
     * Adapt the new JSON transport onto the current report-generation seam.
     */
    public function applyLegacyGlobals(): void
    {
        $_POST = [];
        $_POST['offset'] = (string) $this->offset;

        if ($this->order !== '') {
            $_POST['order'] = $this->order;
        }
    }

    /**
     * @return list<string>
     */
    public static function supportedOrderTokens(): array
    {
        return self::SUPPORTED_ORDER_TOKENS;
    }

    private static function parseOffset(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('offset must be greater than or equal to 0.', 422);
            }

            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('offset must be a non-negative integer.', 422);
    }

    private static function parseOrder(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('order must be a string.', 422);
        }

        $order = trim($value);
        if (!in_array($order, self::SUPPORTED_ORDER_TOKENS, true)) {
            throw new InvalidArgumentException('order is not a supported sort token.', 422);
        }

        return $order;
    }

    private static function parseBoolean(mixed $value, string $fieldName): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException($fieldName . ' must be a boolean.', 422);
    }
}

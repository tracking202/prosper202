<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\QueryInt;
use Api\V3\Support\ReportFilter;
use Api\V3\Support\StatementHelpers;
use Prosper202\Database\Connection;
use Prosper202\Spend\PlatformConversions;

/**
 * GET /spend-ledger/conversions: the conversions each ad platform reports
 * per ad per day (in the ad account's zone), beside Prosper202's own
 * conversions of the clicks that carried each ad, for reconciliation.
 */
final class PlatformConversionsController
{
    use StatementHelpers;
    use AccountTimezone;

    private const array PARAMS = ['time_from', 'time_to', 'period', 'platform', 'limit'];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function list(array $params): array
    {
        $unknown = array_values(array_diff(array_keys($params), self::PARAMS));
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter', array_fill_keys(
                array_map('strval', $unknown),
                'Not a parameter of the platform conversions. Valid: ' . implode(', ', self::PARAMS)
            ));
        }
        foreach ($params as $name => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new ValidationException('Invalid ' . $name, [(string) $name => 'Takes one value, not a list']);
            }
        }
        $platform = $params['platform'] ?? '';
        if (!is_string($platform) || ($platform !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platform) !== 1)) {
            throw new ValidationException('Invalid platform', ['platform' => 'must be a platform name such as openai_ads']);
        }
        $limit = QueryInt::param($params, 'limit', 500, 1, 5000);
        $timezone = $this->accountTimezone();
        [$from, $to] = ReportFilter::bounds($params, static fn (): string => $timezone);
        // The window's days in the account's zone; each row's day is in its
        // ad account's zone, as the platform reports it.
        $zone = new \DateTimeZone($timezone);
        $fromDay = $from === null ? '2000-01-01' : (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->format('Y-m-d');
        $toDay = $to === null ? '9999-12-31' : (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->format('Y-m-d');

        $read = (new PlatformConversions(new Connection($this->db)))->read($this->userId, $fromDay, $toDay, $platform === '' ? null : $platform, $limit);

        return ['data' => $read['rows'], 'from_day' => $fromDay, 'to_day' => $toDay, 'truncated' => $read['truncated'], 'limit' => $limit];
    }
}

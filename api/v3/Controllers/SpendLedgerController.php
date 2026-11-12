<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\QueryInt;
use Api\V3\Support\ReportFilter;
use Api\V3\Support\StatementHelpers;
use Prosper202\Database\Connection;
use Prosper202\Spend\SpendLedger;
use Prosper202\Spend\SpendRow;

/**
 * /spend-ledger: what each ad platform billed per ad per hour
 * (Prosper202\Spend\SpendLedger), written by whoever reads the platform and
 * read by the reports as cost where present.
 *
 * POST replaces each hour it names, so sending the same rows again changes
 * nothing and a retry is safe; `?dry_run=1` answers what it would do.
 */
final class SpendLedgerController
{
    use StatementHelpers;
    use AccountTimezone;

    /** The body POST reads. */
    private const array WRITE_FIELDS = ['rows'];

    /** The query GET reads. */
    private const array READ_PARAMS = ['time_from', 'time_to', 'period', 'interval', 'platform', 'limit'];

    private const int READ_MAX_LIMIT = 5000;

    private readonly SpendLedger $ledger;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->ledger = new SpendLedger(new Connection($db));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function write(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, self::WRITE_FIELDS, 'a spend ledger write', [
            'dry_run' => 'is not a body field: send ?dry_run=1 in the query',
        ]);
        if (!array_key_exists('rows', $payload)) {
            throw new ValidationException('Invalid rows', ['rows' => 'is required: a list of spend rows (fields: ' . implode(', ', SpendRow::FIELDS) . ')']);
        }
        [$rows, $errors] = $this->ledger->parse($this->userId, $payload['rows']);
        PayloadKeys::refuse($errors);

        $counts = $dryRun ? $this->ledger->preview($this->userId, $rows) : $this->ledger->write($this->userId, $rows);

        return ['data' => ['dry_run' => $dryRun, 'received' => count($rows)] + $counts];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function read(array $params): array
    {
        $unknown = array_values(array_diff(array_keys($params), self::READ_PARAMS));
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter', array_fill_keys(
                array_map('strval', $unknown),
                'Not a parameter of the spend ledger. Valid: ' . implode(', ', self::READ_PARAMS)
            ));
        }
        foreach ($params as $name => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new ValidationException('Invalid ' . $name, [(string) $name => 'Takes one value, not a list']);
            }
        }
        $interval = $params['interval'] ?? '';
        $interval = $interval === '' ? 'day' : $interval;
        if (!in_array($interval, ['hour', 'day'], true)) {
            throw new ValidationException('Invalid interval', ['interval' => 'Valid values: hour, day']);
        }
        $platform = $params['platform'] ?? '';
        if (!is_string($platform) || ($platform !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $platform) !== 1)) {
            throw new ValidationException('Invalid platform', ['platform' => 'must be a platform name such as openai_ads']);
        }
        $limit = QueryInt::param($params, 'limit', 500, 1, self::READ_MAX_LIMIT);
        $timezone = $this->accountTimezone();
        [$from, $to] = ReportFilter::bounds($params, static fn (): string => $timezone);

        $read = $this->ledger->read($this->userId, $from, $to, $interval, $timezone, $platform === '' ? null : $platform, $limit);

        return [
            'data' => $read['rows'],
            'interval' => $interval,
            'timezone' => $timezone,
            'currency' => $this->ledger->accountCurrency($this->userId),
            'reaches' => $this->ledger->reaches($this->userId),
            'truncated' => $read['truncated'],
            'limit' => $limit,
        ];
    }
}

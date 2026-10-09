<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Conversion\BatchInterrupted;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\Update\CpcUpdate;
use Prosper202\Update\SubidBatch;

/**
 * The Update section of the UI (tracking202/update/) as REST: update the CPC
 * of past clicks, mark subids converted, delete subids, reset a campaign's or
 * category's subids, and upload a network's revenue report.
 *
 * Every write here is the page's own, through the same domain code
 * (Prosper202\Update\CpcUpdate and SubidBatch, RevenueUploadImporter), so a
 * request through the API and a form through the page cannot do different
 * things (CLAUDE.md #5). What this class adds is what an HTTP caller needs and
 * a form never sends: strict input reading (an unknown field, a value of the
 * wrong type, a float CPC that is not exactly five decimals — each refused
 * by name, never cast or dropped: CLAUDE.md #4, #18), a write-free preview
 * for every operation (`?dry_run=1`, read through the same handler, so it is
 * gated exactly as the write is), and a report of a batch that stopped
 * part-way that says what stands (CLAUDE.md #13).
 *
 * The role permissions (access_to_update_section, and delete_individual_subids
 * to delete subids) are checked by the routes, as the pages check them;
 * api/v3/index.php holds that and UpdateRoutesPermissionTest pins it.
 */
final class UpdateController
{
    /** The most subids one request may name; a longer list is sent in parts. */
    public const MAX_SUBIDS = 5000;

    /**
     * The largest request body POST /conversions/uploads accepts — the report
     * travels inside it — where every other route takes 1 MB.
     */
    public const UPLOAD_MAX_BYTES = 8_388_608;

    /** The fields of POST /clicks/cpc. */
    private const CPC_FIELDS = ['from', 'to', 'cpc', 'aff_network_id', 'aff_campaign_id', 'ppc_network_id', 'ppc_account_id',
        'landing_page_id', 'text_ad_id', 'method_of_promotion', 'expect_clicks', 'through_click_id'];

    /** Each id filter: what it names, and the list endpoint that has its ids. */
    private const ID_LISTS = [
        'aff_network_id' => ['category', 'aff-networks'],
        'aff_campaign_id' => ['campaign', 'campaigns'],
        'ppc_network_id' => ['traffic source', 'ppc-networks'],
        'ppc_account_id' => ['traffic source account', 'ppc-accounts'],
        'landing_page_id' => ['landing page', 'landing-pages'],
        'text_ad_id' => ['text ad', 'text-ads'],
    ];

    private readonly Connection $conn;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($this->db);
    }

    // ─── POST /clicks/cpc ───────────────────────────────────────────────

    /**
     * Set what a set of past clicks cost (Update CPC). A dry run counts the
     * clicks and names the highest click id among them; the write carries both
     * back as expect_clicks and through_click_id and runs only if that bounded
     * selection still counts the same, in the transaction that writes —
     * otherwise nothing is written and the answer is a 409 with the new count.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cpc(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, self::CPC_FIELDS, 'a CPC update', self::QUERY_NOT_BODY);

        $errors = [];
        $in = [];
        foreach (['from' => 'first', 'to' => 'last'] as $field => $which) {
            if (!array_key_exists($field, $payload)) {
                $errors[$field] = 'is required: the ' . $which . ' day to update, YYYY-MM-DD, in the account\'s time zone';
            } elseif (!is_string($payload[$field])) {
                $errors[$field] = 'must be a day written YYYY-MM-DD';
            } else {
                $in[$field] = $payload[$field];
            }
        }
        $in['cpc'] = self::cpcInput($payload, $errors);
        foreach (self::ID_LISTS as $field => [$what, $list]) {
            $in[$field] = self::filterId($payload, $field, $what, $list, $errors);
        }
        if (array_key_exists('method_of_promotion', $payload)) {
            if (!is_string($payload['method_of_promotion'])) {
                $errors['method_of_promotion'] = 'must be "directlink", "landingpage", or "" for both';
            } else {
                $in['method_of_promotion'] = $payload['method_of_promotion'];
            }
        }
        $snapshot = null;
        if (!$dryRun) {
            $snapshot = self::cpcSnapshot($payload, $errors);
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid CPC update', $errors);
        }

        return $this->inAccountTimezone(function (string $timezone) use ($in, $dryRun, $snapshot): array {
            $parsed = CpcUpdate::parse($in);
            $values = $parsed['values'];
            if ($parsed['errors'] !== []) {
                throw new ValidationException('Invalid CPC update', self::cpcParseErrors($parsed['errors'], $values));
            }
            // Called directly, not through guard()'s arrow function: labels()
            // reports a foreign id through its by-reference $errors, and an
            // arrow function captures $ownership by value, so the refusal
            // would land in its copy and the request would go through
            // (CLAUDE.md #8; measured: a foreign campaign id previewed 200).
            $ownership = [];
            try {
                $labels = CpcUpdate::labels($this->conn, $values, $this->userId, $ownership);
            } catch (\Throwable $e) {
                throw new DatabaseException('Update: checking the filters\' owners failed', $e);
            }
            if ($ownership !== []) {
                $errors = [];
                foreach (array_keys($ownership) as $field) {
                    [$what, $list] = self::ID_LISTS[$field];
                    $errors[$field] = $what . ' ' . (int) $values[$field] . ' is not one of this account\'s; use an id from GET /' . $list;
                }
                throw new ValidationException('Invalid CPC update', $errors);
            }

            $selection = [
                'cpc' => (string) $values['cpc'],
                'from' => (string) $values['from'],
                'to' => (string) $values['to'],
                'from_time' => (int) $values['from_time'],
                'to_time' => (int) $values['to_time'],
                'timezone' => $timezone,
                'filters' => self::cpcFilters($values, $labels),
            ];

            if ($dryRun) {
                $count = $this->guard(fn (): array => CpcUpdate::preview($this->conn, $values, $this->userId));
                return ['data' => ['dry_run' => true, 'matching' => $count['matching'], 'through_click_id' => $count['through_click_id']] + $selection];
            }

            if ($snapshot === null) {
                throw new \LogicException('a CPC write reached apply without what it confirms');
            }
            // One transaction: when it throws, nothing was written.
            $updated = $this->guard(fn (): ?int => CpcUpdate::apply($this->conn, $values, $this->userId, $snapshot));
            if ($updated === null) {
                // Nothing was written. Count again, as the page does, so the
                // caller can confirm the new number.
                $now = $this->guard(fn (): array => CpcUpdate::preview($this->conn, $values, $this->userId));
                throw new ConflictException(
                    'The clicks in this selection changed after they were counted: ' . $snapshot['count'] . ' were confirmed and '
                    . $now['matching'] . ' match now. Nothing was changed. Confirm the new count by sending it as expect_clicks, with '
                    . 'through_click_id ' . $now['through_click_id'] . ' (or run the request again with ?dry_run=1).',
                    ['expect_clicks' => $snapshot['count'], 'matching' => $now['matching'], 'through_click_id' => $now['through_click_id']]
                );
            }

            return ['data' => ['dry_run' => false, 'updated' => $updated, 'matching' => $snapshot['count'], 'through_click_id' => $snapshot['through']] + $selection];
        });
    }

    // ─── POST /conversions/subids ───────────────────────────────────────

    /**
     * Mark subids converted (Update Subids): each subid's click is recorded as
     * converted at its campaign's payout, once per click.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function markSubids(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['subids'], 'a subid update', self::QUERY_NOT_BODY);
        $items = self::subidList($payload);
        $batch = new SubidBatch($this->conn);

        if ($dryRun) {
            $preview = $this->guard(fn (): array => $batch->markPreview($this->userId, $items));
            return ['data' => ['dry_run' => true] + self::countLines($preview['lines'], [SubidBatch::WOULD_MARK, SubidBatch::ALREADY_CONVERTED,
                SubidBatch::NOT_FOUND, SubidBatch::NOT_A_SUBID, SubidBatch::DUPLICATE_IN_LIST]) + ['lines' => $preview['lines']]];
        }

        set_time_limit(0);
        try {
            $result = $batch->mark($this->userId, $items);
        } catch (BatchInterrupted $e) {
            throw self::interrupted($e, static fn (BatchInterrupted $e): string => 'Marking stopped at ' . $e->at . ': '
                . $e->committed . ' ' . self::plural($e->committed, 'subid was marked before it and stays marked', 'subids were marked before it and stay marked') . '. '
                . 'Sending the same list again is safe: a subid already marked is left as it is, so only the rest are marked.');
        }

        return ['data' => ['dry_run' => false] + self::countLines($result['lines'], [SubidBatch::MARKED, SubidBatch::ALREADY_CONVERTED,
            SubidBatch::NOT_FOUND, SubidBatch::NOT_A_SUBID, SubidBatch::DUPLICATE_IN_LIST]) + ['lines' => $result['lines']]];
    }

    // ─── POST /conversions/subids/delete ────────────────────────────────

    /**
     * Delete subids (Delete Subids): every conversion of each subid's click is
     * cleared through the ledger, and the click is no longer a lead. A subid of
     * another account is left alone.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function deleteSubids(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['subids'], 'a subid deletion', self::QUERY_NOT_BODY);
        $items = self::subidList($payload);
        $batch = new SubidBatch($this->conn);

        if ($dryRun) {
            $preview = $this->guard(fn (): array => $batch->clearPreview($this->userId, $items));
            return ['data' => ['dry_run' => true]
                + self::countLines($preview['lines'], [SubidBatch::WOULD_CLEAR, SubidBatch::NOT_FOUND, SubidBatch::NOT_A_SUBID, SubidBatch::DUPLICATE_IN_LIST])
                + ['conversions' => $preview['conversions'], 'lines' => $preview['lines']]];
        }

        set_time_limit(0);
        try {
            $result = $batch->clear($this->userId, $items);
        } catch (BatchInterrupted $e) {
            throw self::interrupted($e, static fn (BatchInterrupted $e): string => 'Deleting stopped at ' . $e->at . ': the conversions of '
                . $e->committed . ' ' . self::plural($e->committed, 'subid', 'subids') . ' were deleted before it and stay deleted. '
                . 'Sending the same list again is safe: a subid already cleared has nothing more to delete.');
        }

        return ['data' => ['dry_run' => false]
            + self::countLines($result['lines'], [SubidBatch::CLEARED, SubidBatch::NOT_FOUND, SubidBatch::NOT_A_SUBID, SubidBatch::DUPLICATE_IN_LIST])
            + ['lines' => $result['lines']]];
    }

    // ─── POST /conversions/subids/reset ─────────────────────────────────

    /**
     * Reset a category's subids, or one campaign's in it (Reset Campaign
     * Subids): every converted click is cleared through the ledger, so a
     * report uploaded by mistake can be uploaded again.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resetSubids(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['aff_network_id', 'aff_campaign_id'], 'a subid reset', self::QUERY_NOT_BODY);
        $errors = [];
        $network = self::filterId($payload, 'aff_network_id', 'category', 'aff-networks', $errors, 18);
        $campaign = self::filterId($payload, 'aff_campaign_id', 'campaign', 'campaigns', $errors, 18);
        if ($errors === [] && ($network === '' || $network === '0')) {
            $errors['aff_network_id'] = 'is required: the category whose subids to reset (an id from GET /aff-networks)';
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid subid reset', $errors);
        }

        $batch = new SubidBatch($this->conn);
        $scope = $this->guard(fn (): array => $batch->resetScope($this->userId, $network, $campaign));
        if ($scope['problems'] !== []) {
            $errors = [];
            foreach ($scope['problems'] as $field => $problem) {
                $errors[$field] = match ($problem) {
                    'not_owned' => $field === 'aff_network_id'
                        ? 'category ' . $network . ' is not one of this account\'s; use an id from GET /aff-networks'
                        : 'campaign ' . $campaign . ' is not one of this account\'s; use an id from GET /campaigns',
                    'other_category' => 'campaign ' . $campaign . ' is in category ' . (int) ($scope['campaign']['aff_network_id'] ?? 0)
                        . ', not ' . $network . '; omit aff_campaign_id to reset the whole category',
                    default => 'is required: the category whose subids to reset (an id from GET /aff-networks)',
                };
            }
            throw new ValidationException('Invalid subid reset', $errors);
        }
        $named = [
            'aff_network' => ['id' => $scope['network_id'], 'name' => (string) ($scope['network']['aff_network_name'] ?? '')],
            'aff_campaign' => $scope['campaign_id'] > 0
                ? ['id' => $scope['campaign_id'], 'name' => (string) ($scope['campaign']['aff_campaign_name'] ?? '')]
                : null,
        ];

        if ($dryRun) {
            $rows = $this->guard(fn (): array => $batch->resetClicks($this->userId, $scope['network_id'], $scope['campaign_id']));
            return ['data' => ['dry_run' => true, 'matching' => count($rows)] + $named];
        }

        set_time_limit(0);
        try {
            $cleared = $batch->reset($this->userId, $scope['network_id'], $scope['campaign_id']);
        } catch (BatchInterrupted $e) {
            throw self::interrupted($e, static fn (BatchInterrupted $e): string => 'The reset stopped at ' . $e->at . ': '
                . $e->committed . ' ' . self::plural($e->committed, 'click was cleared before it and stays cleared', 'clicks were cleared before it and stay cleared') . '. '
                . 'Sending the same request again is safe: it clears what is left.');
        } catch (\Throwable $e) {
            // Before the first clear (reading the clicks): nothing was written.
            throw new DatabaseException('Reading the clicks to reset failed', $e);
        }

        return ['data' => ['dry_run' => false, 'cleared' => $cleared] + $named];
    }

    // ─── POST /conversions/uploads ──────────────────────────────────────

    /**
     * Upload a network's revenue report (Upload Revenue Reports): every CSV
     * line becomes a ledger row of a new upload batch on its subid's click;
     * a click's lines are summed, and the newest batch replaces what earlier
     * uploads and conversions set for it.
     *
     * The columns are 0-based indexes or header names; either may be left out
     * when the header names it plainly (the page's guess).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function uploadRevenue(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['csv', 'file_name', 'subid_column', 'amount_column'], 'a revenue upload', self::QUERY_NOT_BODY);
        $errors = [];
        $csv = is_string($payload['csv'] ?? null) ? $payload['csv'] : '';
        if (trim($csv) === '') {
            $errors['csv'] = 'is required: the report\'s text, a CSV with a header line';
        }
        $fileName = 'api-upload.csv';
        if (array_key_exists('file_name', $payload)) {
            if (!is_string($payload['file_name']) || trim($payload['file_name']) === '') {
                $errors['file_name'] = 'must be a non-empty string (the name the upload is listed under); omit it for "api-upload.csv"';
            } else {
                $fileName = trim($payload['file_name']);
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid revenue upload', $errors);
        }
        $header = self::firstRecord($csv);
        $columns = self::uploadColumns($payload, $header);

        $handle = self::stream($csv);
        try {
            $importer = new RevenueUploadImporter($this->conn, new MysqlConversionRepository($this->conn));
            if ($dryRun) {
                $preview = $this->guard(fn (): array => $importer->preview($this->userId, $handle, $columns['subid']['index'], $columns['amount']['index']));
                return ['data' => [
                    'dry_run' => true,
                    'batch_id' => null,
                    'would_record' => $preview['would_record'],
                    'skipped' => $preview['skipped'],
                    'columns' => $columns,
                ] + self::uploadAnswer($preview)];
            }

            set_time_limit(0);
            try {
                $import = $importer->import($this->userId, $fileName, $handle, $columns['subid']['index'], $columns['amount']['index']);
            } catch (BatchInterrupted $e) {
                throw self::interrupted($e, static fn (BatchInterrupted $e): string => 'The report stopped at ' . $e->at . ': batch '
                    . (int) $e->batchId . ' recorded ' . $e->committed . ' ' . self::plural($e->committed, 'line before it, which stands', 'lines before it, which stand')
                    . '. Uploading the same report again is safe: it records every line as a new batch, '
                    . 'whose values replace batch ' . (int) $e->batchId . '\'s.');
            } catch (\Throwable $e) {
                // Before the batch row existed: nothing was written.
                throw new DatabaseException('Creating the upload batch failed', $e);
            }
        } finally {
            fclose($handle);
        }

        return ['data' => [
            'dry_run' => false,
            'batch_id' => $import['batch_id'],
            'recorded' => $import['recorded'],
            'skipped' => $import['skipped'],
            'columns' => $columns,
        ] + self::uploadAnswer($import)];
    }

    /** The most per-click totals an upload's answer lists. */
    private const LISTED_CLICKS = 1000;

    /**
     * What an upload's answer says beyond its counts, sized by the clicks and
     * lines it lists rather than by the report: a recorded line is counted
     * and in its click's sum; a line not recorded — the header, each skipped
     * line with its reason — is listed as the page lists it, up to
     * RevenueUploadImporter::LISTED_LINES, and every skipped line is counted
     * by its reason. clicks and total are exact; totals lists the first
     * LISTED_CLICKS clicks in the order the report names them. Listing every
     * line made a 440,000-line report answer about 48 MB, and every line
     * skipped (the wrong subid column) or every click distinct still made one
     * the CLI, which reads 10 MB, could not read.
     *
     * @param array{lines: list<array<string, mixed>>, unlisted: int, reasons: array<string, int>, totals: array<int, string>, total: string} $result
     * @return array<string, mixed>
     */
    private static function uploadAnswer(array $result): array
    {
        $reasons = [];
        foreach ($result['reasons'] as $reason => $count) {
            $reasons[] = ['reason' => (string) $reason, 'lines' => $count];
        }

        return [
            'skipped_reasons' => $reasons,
            'clicks' => count($result['totals']),
            'total' => $result['total'],
            'totals' => self::totals(array_slice($result['totals'], 0, self::LISTED_CLICKS, true)),
            'totals_unlisted' => max(0, count($result['totals']) - self::LISTED_CLICKS),
            'lines' => $result['lines'],
            'lines_unlisted' => $result['unlisted'],
        ];
    }

    // ─── Reading the request ────────────────────────────────────────────

    /**
     * Refuse a field this endpoint does not read. Ignoring one is how a
     * misspelled filter widens an update to every click (CLAUDE.md #11).
     *
     * @param array<string, mixed> $payload
     * @param list<string> $allowed
     */
    private const QUERY_NOT_BODY = [
        'dry_run' => 'goes in the query string, not the body: POST …?dry_run=1 previews; without it the request writes',
    ];

    /**
     * An id filter as the page's reader takes it: '' when absent (every one),
     * otherwise its digits. A JSON integer or a string of digits without a
     * leading zero; anything else is refused under its field, never read as
     * 0, which would widen the write to every row (CLAUDE.md #11, #18).
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function filterId(array $payload, string $field, string $what, string $list, array &$errors, int $maxDigits = 9): string
    {
        if (!array_key_exists($field, $payload)) {
            return '';
        }
        $value = $payload[$field];
        $max = (int) str_repeat('9', $maxDigits);
        if (is_int($value) && $value >= 0 && $value <= $max) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,' . ($maxDigits - 1) . '})$/D', $value) === 1) {
            return $value;
        }
        $errors[$field] = 'must be the id of one of this account\'s ' . $what . ' rows (GET /' . $list . '), or 0 for every ' . $what
            . '; omit it for every ' . $what;
        return '';
    }

    /**
     * The CPC as the page's reader takes it, a string. A JSON number arrives
     * as a double, which is read only when it is exactly a value of five
     * decimals or fewer (0.25 is; 0.123456 and 1e-6 are not) — never rounded
     * into one the caller did not send (CLAUDE.md #18).
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function cpcInput(array $payload, array &$errors): string
    {
        if (!array_key_exists('cpc', $payload)) {
            $errors['cpc'] = 'is required: what each click cost, in dollars with up to five decimals, e.g. "0.25"';
            return '';
        }
        $cpc = $payload['cpc'];
        if (is_string($cpc)) {
            return $cpc;
        }
        if (is_int($cpc)) {
            return (string) $cpc;
        }
        if (is_float($cpc) && is_finite($cpc)) {
            $fixed = sprintf('%.5F', $cpc);
            if ((float) $fixed === $cpc) {
                return $fixed;
            }
            $errors['cpc'] = 'must have at most five decimals; send it as a string, e.g. "0.00125", to be exact';
            return '';
        }
        $errors['cpc'] = 'must be a number of dollars with up to five decimals, e.g. "0.25"';
        return '';
    }

    /**
     * What the write confirms: the dry run's matching count and highest click
     * id. Both are required; the write runs only if that selection still
     * counts the same.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     * @return array{count: int, through: int}|null
     */
    private static function cpcSnapshot(array $payload, array &$errors): ?array
    {
        $read = [];
        foreach (['expect_clicks' => 'matching', 'through_click_id' => 'through_click_id'] as $field => $answer) {
            $value = $payload[$field] ?? null;
            if (is_int($value) && $value >= 0) {
                $value = (string) $value;
            }
            // The rule CpcUpdate::snapshot() applies, checked here per field
            // so the refusal names the one that is wrong.
            if (is_string($value) && $value !== '' && strlen($value) <= 18 && ctype_digit($value)) {
                $read[$field] = $value;
                continue;
            }
            $errors[$field] = array_key_exists($field, $payload)
                ? 'must be the whole number a dry run answered as ' . $answer
                : 'is required to write: send the request with ?dry_run=1 first, and confirm its ' . $answer . ' here';
        }
        if (count($read) < 2) {
            return null;
        }
        $snapshot = CpcUpdate::snapshot($read);
        if ($snapshot === null) {
            $errors['expect_clicks'] = 'with through_click_id, must be the whole numbers a dry run answered';
        }
        return $snapshot;
    }

    /**
     * CpcUpdate::parse()'s refusals in the API's terms: the page's sentences
     * speak of a date picker and a list to choose from.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function cpcParseErrors(array $errors, array $values): array
    {
        $out = [];
        foreach ($errors as $field => $sentence) {
            $out[$field] = match (true) {
                $field === 'from' || $field === 'to' => $sentence === 'The last day is before the first day.'
                    ? 'is before from (' . (string) $values['from'] . ')'
                    : "'" . (string) ($values[$field] ?? '') . "' is not a day; write it YYYY-MM-DD",
                $field === 'method_of_promotion' => 'must be "directlink", "landingpage", or "" for both',
                isset(self::ID_LISTS[$field]) => 'must be the id of one of this account\'s ' . self::ID_LISTS[$field][0]
                    . ' rows (GET /' . self::ID_LISTS[$field][1] . '), or 0 for every ' . self::ID_LISTS[$field][0],
                default => $sentence,
            };
        }
        return $out;
    }

    /**
     * Each filter as applied: its id (0 = every one) and the name the account
     * gave it, as the page's summary shows it.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $labels
     * @return array<string, array<string, int|string>>
     */
    private static function cpcFilters(array $values, array $labels): array
    {
        $filters = [];
        foreach (array_keys(self::ID_LISTS) as $field) {
            $filters[$field] = ['id' => (int) $values[$field], 'name' => (string) ($labels[$field] ?? '')];
        }
        $filters['method_of_promotion'] = ['value' => (string) $values['method_of_promotion'], 'name' => (string) ($labels['method_of_promotion'] ?? '')];
        return $filters;
    }

    /**
     * The subids of a request: a list of strings or integers, at most
     * MAX_SUBIDS, at least one not blank. An item of any other type is
     * refused by position rather than read as some click's id (CLAUDE.md #18).
     *
     * @param array<string, mixed> $payload
     * @return list<string|int>
     */
    private static function subidList(array $payload): array
    {
        $items = $payload['subids'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ValidationException('Invalid subids', ['subids' => 'is required: a list of subids (click ids), e.g. ["940001", "940002"]']);
        }
        if (count($items) > self::MAX_SUBIDS) {
            throw new ValidationException('Too many subids', ['subids' => 'holds ' . count($items) . ' items; send at most ' . self::MAX_SUBIDS . ' per request']);
        }
        $errors = [];
        $blank = true;
        foreach ($items as $i => $item) {
            if (!is_string($item) && !is_int($item)) {
                $errors['subids.' . $i] = 'must be a string or an integer subid, got ' . get_debug_type($item);
                continue;
            }
            if (trim((string) $item) !== '') {
                $blank = false;
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid subids', $errors);
        }
        if ($blank) {
            throw new ValidationException('Invalid subids', ['subids' => 'holds no subid: every item is blank']);
        }
        return $items;
    }

    /**
     * The report's first record, as the importer will read it: the header the
     * columns are named and guessed from.
     *
     * @return list<string>
     */
    private static function firstRecord(string $csv): array
    {
        $handle = self::stream($csv);
        try {
            $row = fgetcsv($handle, 100000, ',', '"', '\\');
        } finally {
            fclose($handle);
        }
        if (!is_array($row) || $row === [null]) {
            throw new ValidationException('Invalid revenue upload', ['csv' => 'has no header line to name the columns from']);
        }
        return array_map(static fn ($cell): string => trim((string) $cell), $row);
    }

    /**
     * Which column holds the subid and which the commission: each named by a
     * 0-based index or a header name, or guessed from the header when left
     * out. A name that matches no header or more than one, an index past the
     * header, and the same column for both are refused.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $header
     * @return array{subid: array{index: int, header: string}, amount: array{index: int, header: string}, guessed: list<string>}
     */
    private static function uploadColumns(array $payload, array $header): array
    {
        $guess = RevenueUploadImporter::guessColumns($header);
        $listed = implode(', ', array_map(static fn (string $name, int $i): string => $i . ' "' . $name . '"', $header, array_keys($header)));
        $errors = [];
        $chosen = [];
        $guessed = [];
        foreach (['subid' => 'subid_column', 'amount' => 'amount_column'] as $role => $field) {
            $what = $role === 'subid' ? 'subid' : 'commission';
            if (!array_key_exists($field, $payload)) {
                if ($guess[$role] === null) {
                    $errors[$field] = 'no header names the ' . $what . ' column plainly; name it by index or header (columns: ' . $listed . ')';
                    continue;
                }
                $chosen[$role] = $guess[$role];
                $guessed[] = $field;
                continue;
            }
            $value = $payload[$field];
            if (is_int($value)) {
                if ($value < 0 || $value >= count($header)) {
                    $errors[$field] = 'index ' . $value . ' is not a column of the header (columns: ' . $listed . ')';
                    continue;
                }
                $chosen[$role] = $value;
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                $errors[$field] = 'must be a 0-based column index or a header name (columns: ' . $listed . ')';
                continue;
            }
            $matches = array_keys(array_filter($header, static fn (string $name): bool => strcasecmp($name, trim($value)) === 0));
            if (count($matches) !== 1) {
                $errors[$field] = (count($matches) === 0 ? 'no header is named "' : 'more than one header is named "') . trim($value)
                    . '"; name the column by its index (columns: ' . $listed . ')';
                continue;
            }
            $chosen[$role] = $matches[0];
        }
        if ($errors === [] && $chosen['subid'] === $chosen['amount']) {
            $errors['amount_column'] = 'is the subid column too (column ' . $chosen['subid'] . '); name the commission column (columns: ' . $listed . ')';
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid revenue upload', $errors);
        }

        return [
            'subid' => ['index' => $chosen['subid'], 'header' => $header[$chosen['subid']]],
            'amount' => ['index' => $chosen['amount'], 'header' => $header[$chosen['amount']]],
            'guessed' => $guessed,
        ];
    }

    /**
     * The report as a stream, for the importer that reads files.
     *
     * @return resource
     */
    private static function stream(string $csv)
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new DatabaseException('Opening a buffer for the report failed');
        }
        if (fwrite($handle, $csv) !== strlen($csv) || !rewind($handle)) {
            fclose($handle);
            throw new DatabaseException('Buffering the report failed');
        }
        return $handle;
    }

    // ─── Answering ──────────────────────────────────────────────────────

    /**
     * How many lines ended in each status, every status named (0 included),
     * so a caller reads a count instead of inferring one from absence.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<string> $statuses
     * @return array<string, int>
     */
    private static function countLines(array $lines, array $statuses): array
    {
        $counts = array_fill_keys($statuses, 0);
        foreach ($lines as $line) {
            // A status not listed is still counted, under its own name: this
            // runs after the batch has written, so it must not throw, and a
            // line must never vanish from the counts.
            $status = (string) $line['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param array<int, string> $totals click_id => amount
     * @return list<array{click_id: int, total: string}>
     */
    private static function totals(array $totals): array
    {
        $out = [];
        foreach ($totals as $clickId => $total) {
            $out[] = ['click_id' => (int) $clickId, 'total' => (string) $total];
        }
        return $out;
    }

    /**
     * A batch that stopped part-way, as the caller must hear it: when nothing
     * had been written, an ordinary failure a retry repeats safely; when some
     * of it stands, WriteCommittedException with the sentence that says how
     * much and that sending it again is safe (CLAUDE.md #13). The cause's text
     * goes to the log, never to the client.
     *
     * @param callable(BatchInterrupted): string $describe
     */
    private static function interrupted(BatchInterrupted $e, callable $describe): \Throwable
    {
        if (!$e->wroteSomething()) {
            return new DatabaseException('stopped at ' . $e->at . ' before any write', $e);
        }
        return new WriteCommittedException('conversions', $e, $describe($e));
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? $one : $many;
    }

    /**
     * Run a read or a single-transaction write, turning a database failure
     * into a 500 that names nothing internal. Validation and HTTP errors pass
     * through.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Api\V3\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DatabaseException('Update: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Run $fn with the process's default time zone set to the account's, as
     * the pages run after AUTH::set_timezone(): the days of a CPC update are
     * the account's days. Restored afterwards.
     *
     * A stored zone PHP does not know is refused rather than replaced by UTC:
     * reading the days in the wrong zone moves the window by hours and
     * updates clicks nobody chose. An empty one is UTC, as the session reads
     * it.
     *
     * @template T
     * @param callable(string): T $fn
     * @return T
     */
    private function inAccountTimezone(callable $fn): mixed
    {
        $row = $this->guard(function (): ?array {
            $stmt = $this->conn->prepareWrite('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
            $this->conn->bind($stmt, 'i', [$this->userId]);
            return $this->conn->fetchOne($stmt);
        });
        if ($row === null) {
            throw new DatabaseException('Update: the authenticated user ' . $this->userId . ' has no 202_users row');
        }
        $timezone = trim((string) ($row['user_timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = 'UTC';
        }
        if (!\Prosper202\Report\AccountZone::isZone($timezone)) {
            throw new ConflictException(
                'The account\'s time zone "' . $timezone . '" is not a zone this server knows, so its days cannot be read. '
                . 'Set user_timezone to an IANA zone such as America/New_York (PUT /users/' . $this->userId . ').'
            );
        }

        $previous = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            return $fn($timezone);
        } finally {
            date_default_timezone_set($previous);
        }
    }
}

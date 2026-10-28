<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ReportFilter;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\OfflineExport;
use Prosper202\OfflineConversion\OfflineFile;
use Prosper202\Database\Connection;

/**
 * Offline conversion import files for Google Ads and Microsoft Advertising
 * (Prosper202\OfflineConversion):
 *
 * - GET /conversions/offline-export: the file, as text/csv;
 * - GET /conversions/offline-export/summary: what it would hold, and every
 *   conversion it would leave out, by reason;
 * - GET /offline-conversions/{key}: a scheduled fetch URL made on Setup ›
 *   Offline Conversions, before authentication (serveFeed()).
 */
final class OfflineConversionsController
{
    /** The query parameters of the two authenticated reads. */
    public const PARAMS = ['format', 'days', 'campaign_id', 'conversion_name', 'ad_user_data', 'ad_personalization'];

    /** A download covers every conversion the platforms still import unless told otherwise. */
    public const DEFAULT_DAYS = ExportOptions::MAX_DAYS;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * The file, written whole before anything is sent (OfflineFile).
     *
     * @param array<string, mixed> $params
     * @return array{_stream: array<string, mixed>}
     */
    public function export(array $params): array
    {
        $options = self::options($params, time());
        [$handle, $summary] = OfflineFile::write(new Connection($this->db), $this->userId, $options);

        return ['_stream' => [
            'handle' => $handle,
            'filename' => OfflineFile::filename($options),
            'summary' => $summary,
        ]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>}
     */
    public function summary(array $params): array
    {
        $options = self::options($params, time());
        $summary = (new OfflineExport(new Connection($this->db)))->write($this->userId, $options, null);
        $data = $summary->toArray();
        $data['days'] = $options->days;
        $data['reasons'] = [];
        foreach (\Prosper202\OfflineConversion\LeftOut::cases() as $reason) {
            $data['reasons'][$reason->value] = $reason->explanation();
        }

        return ['data' => $data];
    }

    /**
     * The options a request asks for, read strictly (ExportOptions::
     * fromRequest()): every parameter named in PARAMS or refused by name,
     * format required, days 1 to 90.
     *
     * @param array<string, mixed> $params
     */
    public static function options(array $params, int $now): ExportOptions
    {
        ReportFilter::rejectUnknown($params, self::PARAMS);
        [$options, $errors] = ExportOptions::fromRequest($params, $now, self::DEFAULT_DAYS);
        if ($options === null) {
            throw new ValidationException(
                'Invalid offline conversion file: ' . implode(', ', array_keys($errors)),
                $errors
            );
        }

        return $options;
    }

    /**
     * GET /offline-conversions/{key}, before authentication: the file of the
     * feed the key opens. A key of the wrong shape, a key that opens nothing,
     * and a key replaced or deleted all answer the same 404, so a request
     * that fails learns nothing of any account. The work is done only for a
     * key that opens a feed.
     *
     * The URL handed out ends `?file=conversions.csv`: Google asks for a URL
     * that ends in the file's extension, and a path with no dot reaches this
     * script on every server (php -S before 8.4 serves a dotted path as a
     * static file, and an nginx rule for static files can take one). `file`
     * is the only parameter, and `{key}.csv` is answered as well.
     *
     * @param array<string, mixed> $query
     */
    public static function serveFeed(\mysqli $db, string $tail, array $query, string $method): void
    {
        if (preg_match('/\A([A-Za-z0-9_-]{43})(?:\.csv)?\z/D', $tail, $m) !== 1) {
            Bootstrap::errorResponse('Not found', 404);
            return;
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($query)), ['file']));
        if ($unknown !== [] || (array_key_exists('file', $query) && !is_string($query['file']))) {
            Bootstrap::errorResponse('A fetch URL takes no parameter but file', 422, [
                'field_errors' => array_fill_keys($unknown !== [] ? $unknown : ['file'], 'Not a parameter of a fetch URL; its settings are on Setup › Offline Conversions.'),
            ]);
            return;
        }
        $pending = Bootstrap::pendingUpgrade($db);
        if ($pending !== null) {
            header('Retry-After: 300');
            Bootstrap::errorResponse($pending, 503);
            return;
        }
        $conn = new Connection($db);
        $feeds = new FeedStore($conn);
        try {
            $feed = $feeds->findByKey($m[1]);
            if ($feed === null) {
                Bootstrap::errorResponse('Not found', 404);
                return;
            }
            $now = time();
            [$handle, $summary] = OfflineFile::write($conn, $feed->userId, $feed->options($now));
        } catch (\Throwable $e) {
            error_log(p202UncaughtLogLine('Offline conversion fetch URL answered 500', $e));
            Bootstrap::errorResponse('The file could not be written; try again later', 500);
            return;
        }
        // The Setup page's "last fetched"; the file is served whether or not it is noted.
        if ($method === 'GET') {
            try {
                $feeds->recordFetch($feed->feedId, $summary->rows, $now);
            } catch (\Throwable $e) {
                error_log(p202UncaughtLogLine('Offline conversion fetch URL ' . $feed->feedId . ' was served, and its fetch not noted', $e));
            }
        }
        try {
            OfflineFile::send($handle, 'conversions.csv', null, true);
        } catch (\RuntimeException $e) {
            error_log(p202UncaughtLogLine('Offline conversion fetch URL answered 500', $e));
            Bootstrap::errorResponse('The file could not be written; try again later', 500);
        }
    }
}

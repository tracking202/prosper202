<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Database\Connection;

/**
 * An offline conversion file on its way to whoever asked: written whole to a
 * temporary stream (memory, then disk past 2 MB) before anything is sent, so
 * a failure part way is answered as an error rather than as a short file
 * that reads as complete, and sent from there without being read into
 * memory. The Setup page's download, the API's and the fetch URL's.
 */
final class OfflineFile
{
    private function __construct()
    {
    }

    /**
     * @return array{0: resource, 1: ExportSummary} the file, positioned at its end, and what it holds
     */
    public static function write(Connection $conn, int $userId, ExportOptions $options): array
    {
        $handle = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('could not open a temporary stream for the offline conversion file');
        }
        try {
            $summary = (new OfflineExport($conn))->write($userId, $options, $handle);
        } catch (\Throwable $e) {
            fclose($handle);
            throw $e;
        }

        return [$handle, $summary];
    }

    /** The name a download is saved under. */
    public static function filename(ExportOptions $options): string
    {
        return 'offline-conversions-' . $options->format->value . '-' . gmdate('Ymd', $options->now) . '.csv';
    }

    /**
     * Send a file write() made, as an attachment, and close it: with
     * $summary, how many conversions it holds and left out (X-P202-Rows,
     * X-P202-Left-Out), and with $noIndex, a word to crawlers that a fetch
     * URL is not a page.
     *
     * @param resource $handle
     * @throws \RuntimeException when the file cannot be read back; nothing has been sent then
     */
    public static function send($handle, string $filename, ?ExportSummary $summary = null, bool $noIndex = false): void
    {
        $length = ftell($handle);
        if ($length === false || !rewind($handle)) {
            fclose($handle);
            throw new \RuntimeException('the offline conversion file could not be read back');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'conversions.csv';
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($summary !== null) {
            header('X-P202-Rows: ' . $summary->rows);
            header('X-P202-Left-Out: ' . ($summary->conversions - $summary->rows));
        }
        if ($noIndex) {
            header('X-Robots-Tag: noindex, nofollow');
        }
        $out = fopen('php://output', 'wb');
        if ($out === false || stream_copy_to_stream($handle, $out) === false) {
            error_log('Offline conversion file: the answer could not be written to the response');
        }
        if ($out !== false) {
            fclose($out);
        }
        fclose($handle);
    }
}

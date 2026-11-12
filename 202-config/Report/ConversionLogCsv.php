<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Attribution\ExportCsv;
use Prosper202\Conversion\ConversionLog;

/**
 * Conversion Logs' Download CSV: the filtered list, newest first, up to
 * $max rows, with a last line saying so when the list is longer, and one
 * saying the file is not whole when the read stops part way (the headers
 * are sent by then, so the file is the only place to say it). Every text a
 * visitor or a sender chose (campaign, type, transaction id, user agent)
 * goes through ExportCsv::cell(), so a spreadsheet does not run it.
 */
final class ConversionLogCsv
{
    public const HEADER = ['conversion_id', 'subid', 'campaign', 'click_time', 'conversion_time', 'elapsed', 'ip_address', 'type',
        'reverses_conversion_id', 'transaction_id', 'payout', 'currency', 'sent_payout', 'sent_currency', 'deleted', 'user_agent'];

    private function __construct()
    {
    }

    /**
     * @param resource $out
     * @param array<string, mixed> $filters as ConversionLog::readQuery() gives them
     * @param bool $masked whether the role is shown amounts (CampaignDataMask)
     */
    public static function write($out, ConversionLog $log, int $userId, array $filters, bool $masked, int $max = ConversionLog::EXPORT_MAX): void
    {
        $cell = ExportCsv::cell(...);
        $stamp = static fn (int $ts): string => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
        self::put($out, self::HEADER);
        try {
            $written = $log->export($userId, $filters, static function (array $row) use ($out, $cell, $stamp, $masked): void {
                self::put($out, [
                    $row['conv_id'], $row['click_id'], $cell((string) ($row['campaign_name'] ?? '')), $stamp($row['click_time']),
                    $stamp($row['conv_time']), (string) ($row['elapsed'] ?? ''), $row['ip'], $cell($row['type']),
                    (string) ($row['reverses_conv_id'] ?? ''), $cell((string) ($row['transaction_id'] ?? '')),
                    // Each payout in the currency its row recorded ('' when none was), and what was sent when it was converted.
                    $masked ? '?' : (string) $row['amount'], $row['currency'],
                    $masked ? '?' : (string) ($row['stated_amount'] ?? ''),
                    $row['stated_amount'] === null ? '' : $row['stated_currency'],
                    $row['deleted'] ? 'yes' : 'no', $cell($row['user_agent']),
                ]);
            }, 1000, $max);
            if ($written['more']) {
                self::put($out, ['This file holds the newest ' . ($max === 1 ? 'conversion' : number_format($max) . ' conversions') . ' of a longer list.'
                    . ' Narrow the dates or a filter to download the rest.']);
            }
        } catch (\Throwable $failed) {
            error_log('Conversion Logs: the download stopped: ' . $failed->getMessage());
            self::put($out, ['The download stopped here and is not the whole list. Download it again.']);
        }
    }

    /**
     * @param resource $out
     * @param list<mixed> $fields
     */
    private static function put($out, array $fields): void
    {
        if (fputcsv($out, $fields, ',', '"', '') === false) {
            throw new \RuntimeException('Conversion Logs: a line of the download could not be written');
        }
    }
}

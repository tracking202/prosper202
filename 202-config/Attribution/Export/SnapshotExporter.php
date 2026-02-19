<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Export;

use Prosper202\Attribution\Snapshot;

final class SnapshotExporter
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? dirname(__DIR__, 2) . '/storage/attribution-exports', DIRECTORY_SEPARATOR);
    }

    /**
     * @param Snapshot[] $snapshots
     */
    public function export(ExportJob $job, array $snapshots): string
    {
        $directory = $this->basePath;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to initialise export directory: ' . $directory);
        }

        $timestamp = time();
        // Use exportId if available, otherwise use a hash of userId, modelId, and timestamp
        if (property_exists($job, 'exportId') && !empty($job->exportId)) {
            $fileBase = sprintf('attribution-export-%s-%d', $job->exportId, $timestamp);
        } else {
            $hash = sha1($job->userId . '-' . $job->modelId . '-' . $timestamp);
            $fileBase = sprintf('attribution-export-%s-%d', $hash, $timestamp);
        }

        return match ($job->format) {
            ExportFormat::CSV => $this->writeCsv($directory, $fileBase, $snapshots),
            ExportFormat::XLS => $this->writeXls($directory, $fileBase, $snapshots),
        };
    }

    /**
     * @param Snapshot[] $snapshots
     */
    private function writeCsv(string $directory, string $fileBase, array $snapshots): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . $fileBase . '.csv';
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open export file for writing: ' . $path);
        }

        fputcsv($handle, ['Date (UTC)', 'Attributed Clicks', 'Attributed Conversions', 'Attributed Revenue', 'Attributed Cost', 'ROI %', 'Profit']);

        foreach ($snapshots as $snapshot) {
            $row = $this->normaliseSnapshot($snapshot);
            fputcsv($handle, [
                gmdate('Y-m-d H:i', $row['date_hour']),
                $row['attributed_clicks'],
                $row['attributed_conversions'],
                number_format($row['attributed_revenue'], 2, '.', ''),
                number_format($row['attributed_cost'], 2, '.', ''),
                $row['roi'] !== null ? number_format($row['roi'], 2, '.', '') : '',
                number_format($row['profit'], 2, '.', ''),
            ]);
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param Snapshot[] $snapshots
     */
    private function writeXls(string $directory, string $fileBase, array $snapshots): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . $fileBase . '.xls';
        $rows = array_map($this->normaliseSnapshot(...), $snapshots);

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<table border="1">';
        $html .= '<thead><tr>'
            . '<th>Date (UTC)</th>'
            . '<th>Attributed Clicks</th>'
            . '<th>Attributed Conversions</th>'
            . '<th>Attributed Revenue</th>'
            . '<th>Attributed Cost</th>'
            . '<th>ROI %</th>'
            . '<th>Profit</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td>' . $escape(gmdate('Y-m-d H:i', $row['date_hour'])) . '</td>'
                . '<td>' . $escape((string) $row['attributed_clicks']) . '</td>'
                . '<td>' . $escape((string) $row['attributed_conversions']) . '</td>'
                . '<td>' . $escape(number_format($row['attributed_revenue'], 2)) . '</td>'
                . '<td>' . $escape(number_format($row['attributed_cost'], 2)) . '</td>'
                . '<td>' . ($row['roi'] !== null ? $escape(number_format($row['roi'], 2)) : '') . '</td>'
                . '<td>' . $escape(number_format($row['profit'], 2)) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        if (file_put_contents($path, $html) === false) {
            throw new \RuntimeException('Unable to write export spreadsheet: ' . $path);
        }

        return $path;
    }

    /**
     * @return array{date_hour:int,attributed_clicks:int,attributed_conversions:int,attributed_revenue:float,attributed_cost:float,roi:float|null,profit:float}
     */
    private function normaliseSnapshot(Snapshot $snapshot): array
    {
        $revenue = $snapshot->attributedRevenue;
        $cost = $snapshot->attributedCost;
        $profit = $revenue - $cost;
        $roi = null;
        if ($cost > 0.0) {
            $roi = (($revenue - $cost) / $cost) * 100.0;
        }

        return [
            'date_hour' => $snapshot->dateHour,
            'attributed_clicks' => $snapshot->attributedClicks,
            'attributed_conversions' => $snapshot->attributedConversions,
            'attributed_revenue' => $revenue,
            'attributed_cost' => $cost,
            'roi' => $roi,
            'profit' => $profit,
        ];
    }
}

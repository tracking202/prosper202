<?php

declare(strict_types=1);

namespace P202Cli;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class Formatter
{
    public static function output(OutputInterface $output, array $data, bool $json = false): void
    {
        if ($json) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        // If 'data' key exists and is a list, render as table
        if (isset($data['data']) && is_array($data['data'])) {
            $rows = $data['data'];

            // Single record (associative array)
            if ($rows && !isset($rows[0])) {
                self::renderKeyValue($output, $rows);
                if (isset($data['pagination'])) {
                    $output->writeln('');
                    self::renderKeyValue($output, $data['pagination']);
                }
                return;
            }

            // List of records
            if (empty($rows)) {
                $output->writeln('<comment>No results found.</comment>');
                return;
            }

            self::renderTable($output, $rows);

            if (isset($data['pagination'])) {
                $p = $data['pagination'];
                $output->writeln(sprintf(
                    "\n<info>Showing %d-%d of %s</info>",
                    $p['offset'] + 1,
                    min($p['offset'] + $p['limit'], $p['total'] ?? $p['offset'] + count($rows)),
                    $p['total'] ?? '?'
                ));
            }
            return;
        }

        // Generic object
        self::renderKeyValue($output, $data);
    }

    public static function renderTable(OutputInterface $output, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $headers = array_keys($rows[0]);

        // Truncate wide columns for display
        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($rows as $row) {
            $displayRow = [];
            foreach ($headers as $h) {
                $val = $row[$h] ?? '';
                if (is_array($val)) {
                    $val = json_encode($val);
                }
                $val = (string)$val;
                // Truncate long values
                if (strlen($val) > 60) {
                    $val = substr($val, 0, 57) . '...';
                }
                $displayRow[] = $val;
            }
            $table->addRow($displayRow);
        }

        $table->render();
    }

    public static function renderKeyValue(OutputInterface $output, array $data, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $output->writeln("<info>$prefix$key:</info>");
                if (isset($value[0])) {
                    // Array of items
                    foreach ($value as $i => $item) {
                        if (is_array($item)) {
                            $output->writeln("  <comment>[$i]</comment>");
                            self::renderKeyValue($output, $item, '    ');
                        } else {
                            $output->writeln("  - $item");
                        }
                    }
                } else {
                    self::renderKeyValue($output, $value, '  ');
                }
            } else {
                $output->writeln("<info>$prefix$key:</info> $value");
            }
        }
    }
}

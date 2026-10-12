<?php

declare(strict_types=1);

namespace Prosper202\Integration;

/**
 * A Slack slash command, checked with the Slack app's signing secret
 * (docs.slack.dev, "Verifying requests from Slack"), and the answers this
 * install gives to one.
 */
final class SlackCommand
{
    /** How far a request's timestamp may be from now, as Slack advises. */
    public const MAX_SKEW_SECONDS = 300;

    /** What `stats` takes => the report period it reads. */
    public const PERIODS = [
        'today' => 'today',
        'yesterday' => 'yesterday',
        '7d' => 'last7',
        '30d' => 'last30',
    ];

    /**
     * X-Slack-Signature is "v0=" and the hex HMAC-SHA256, keyed with the
     * signing secret, of "v0:<X-Slack-Request-Timestamp>:<raw body>".
     */
    public static function verify(string $body, string $timestamp, string $signature, string $secret, int $now): bool
    {
        if ($secret === '' || preg_match('/^\d{1,12}$/D', $timestamp) !== 1) {
            return false;
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals('v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, $secret), $signature);
    }

    /**
     * What the command's text asks for: help, or stats over a period.
     *
     * @return array{0: 'help'|'stats', 1: string|null} the action and, for stats, the report period
     * @throws \InvalidArgumentException naming what was not understood
     */
    public static function parse(string $text): array
    {
        $words = preg_split('/\s+/', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === [] || $words === ['help']) {
            return ['help', null];
        }
        if ($words[0] !== 'stats' || count($words) > 2) {
            throw new \InvalidArgumentException('Not a command: "' . trim($text) . '".');
        }
        $period = $words[1] ?? 'today';
        if (!isset(self::PERIODS[$period])) {
            throw new \InvalidArgumentException(
                'Not a period: "' . $period . '". Use one of: ' . implode(', ', array_keys(self::PERIODS)) . '.'
            );
        }

        return ['stats', self::PERIODS[$period]];
    }

    public static function help(string $command): string
    {
        $command = $command !== '' ? $command : '/p202';

        return "*Prosper202*\n"
            . "`$command stats` today's clicks, conversions, revenue, cost and profit\n"
            . "`$command stats yesterday`, `$command stats 7d`, `$command stats 30d` the same over another period\n"
            . "`$command help` this list";
    }

    /**
     * The stats answer, from a report summary's metrics.
     *
     * @param array<string, int|float|null> $metrics ReportsController::summary()'s data
     */
    public static function stats(string $period, array $metrics, string $currency): string
    {
        $label = array_search($period, self::PERIODS, true);
        $money = static fn (string $key): string => number_format((float) ($metrics[$key] ?? 0), 2) . ' ' . $currency;

        $when = $label === 'today' || $label === 'yesterday'
            ? $label
            : 'last ' . substr((string) $label, 0, -1) . ' days';

        return '*Prosper202, ' . $when . "*\n"
            . 'Clicks: ' . number_format((int) ($metrics['total_clicks'] ?? 0))
            . ' · Conversions: ' . number_format((int) ($metrics['total_leads'] ?? 0))
            . ' · Revenue: ' . $money('total_income')
            . ' · Cost: ' . $money('total_cost')
            . ' · Profit: ' . $money('total_net')
            . ' · ROI: ' . number_format((float) ($metrics['roi'] ?? 0), 1) . '%';
    }
}

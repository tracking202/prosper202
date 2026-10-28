<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;

/**
 * Where the LTV webhook dispatch runs. Before 1.9.79 only
 * 202-cronjobs/ltv_webhooks.php sent LTV webhooks and no default deployment
 * ran it, so every delivery waited for good (EveryCronJobIsScheduledByDefaultTest
 * holds that index.php reaches the dispatcher at all). What this holds:
 *
 * - the call sits in the minutely tier (RunSecondsCronjob()), not the hourly
 *   or daily one, with the dispatcher's own budget as its deadline;
 * - it is the tier's last job, so a receiver that times out ends the run
 *   after the others have had their minute;
 * - the Coolify worker service, which runs the standalone jobs, runs it too.
 */
final class LtvWebhooksAreSentEveryMinuteTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const CALL = '\Prosper202\Ltv\WebhookDispatcher::runExclusive(';
    private const ARGS = 'new\Prosper202\Database\Connection($db),'
        . 'time()+\Prosper202\Ltv\WebhookDispatcher::RUN_SECONDS';

    /** @return list<array{0: int, 1: string}> significant tokens */
    private static function tokens(string $src): array
    {
        $out = [];
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $out[] = [$t[0], $t[1]];
                }
            } else {
                $out[] = [0, $t];
            }
        }

        return $out;
    }

    /**
     * Each function's body as one string of its tokens, by name.
     *
     * @return array<string, string>
     */
    private static function functionBodies(string $src): array
    {
        $t = self::tokens($src);
        $bodies = [];
        foreach ($t as $i => [$id]) {
            if ($id !== T_FUNCTION || ($t[$i + 1][0] ?? null) !== T_STRING) {
                continue;
            }
            $j = $i;
            while ($t[$j][1] !== '{') {
                $j++;
            }
            $level = 0;
            $body = '';
            for ($k = $j; $k < count($t); $k++) {
                $text = $t[$k][1];
                if ($text === '{' || $t[$k][0] === T_CURLY_OPEN || $t[$k][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $level++;
                } elseif ($text === '}') {
                    $level--;
                }
                $body .= $text;
                if ($level === 0) {
                    break;
                }
            }
            $bodies[$t[$i + 1][1]] = $body;
        }

        return $bodies;
    }

    /** The argument text of the first call that starts with $call in $code, or null. */
    private static function argumentsOf(string $code, string $call): ?string
    {
        $at = strpos($code, $call);
        if ($at === false) {
            return null;
        }
        $level = 1;
        $start = $at + strlen($call);
        for ($i = $start; $i < strlen($code); $i++) {
            if ($code[$i] === '(') {
                $level++;
            } elseif ($code[$i] === ')' && --$level === 0) {
                return substr($code, $start, $i - $start);
            }
        }

        return null;
    }

    public function testTheMinutelyTierSendsLtvWebhooksLastWithTheirBudget(): void
    {
        $bodies = self::functionBodies((string) file_get_contents(self::ROOT . '202-cronjobs/index.php'));
        foreach (['RunSecondsCronjob', 'RunHourlyCronJob', 'RunDailyCronjob'] as $tier) {
            self::assertArrayHasKey($tier, $bodies, "202-cronjobs/index.php defines $tier()");
        }
        foreach (['RunHourlyCronJob', 'RunDailyCronjob'] as $tier) {
            self::assertStringNotContainsString(self::CALL, $bodies[$tier], "webhooks go every minute, not $tier()");
        }
        $minute = $bodies['RunSecondsCronjob'];
        self::assertSame(1, substr_count($minute, self::CALL), 'RunSecondsCronjob() runs the dispatcher once');
        self::assertSame(self::ARGS, self::argumentsOf($minute, self::CALL), 'the cron\'s connection, its own budget');

        $dispatch = (int) strpos($minute, self::CALL);
        $jobs = [
            '->processDirtyHours(',
            '->processClickUpgrade(',
            'ReClickBackfill',
            'AttributionWorker::runExclusive(',
            'ExportRunner',
            'AndroidIntakeJob::runExclusive(',
            'NotificationOutbox',
        ];
        foreach ($jobs as $job) {
            $at = strpos($minute, $job);
            self::assertIsInt($at, "the minutely tier still runs $job; update this list if it moved");
            self::assertLessThan($dispatch, $at, "$job runs before the webhooks, whose receiver may take its timeout");
        }
    }

    public function testCoolifysWorkerServiceRunsTheStandaloneDispatcher(): void
    {
        $compose = (string) file_get_contents(self::ROOT . 'docker-compose.coolify.yaml');
        // The service: its key and every line indented under it.
        $found = preg_match('/^  attribution-cron:\n((?:(?: {4}.*)?\n)+)/m', $compose, $m);
        self::assertSame(1, $found, 'the attribution-cron service');
        $loop = strstr($m[1], 'while true; do');
        self::assertIsString($loop, 'its command is the worker loop');
        self::assertMatchesRegularExpression(
            '/^ +php 202-cronjobs\/ltv_webhooks\.php \|\| true$/m',
            (string) strstr($loop, 'sleep 60', true),
            'the worker loop runs 202-cronjobs/ltv_webhooks.php every minute'
        );
    }
}

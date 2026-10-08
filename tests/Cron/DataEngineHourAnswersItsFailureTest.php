<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;

/**
 * dej.php rolls up one hour for process_dataengine_job.php, which fetches it
 * over HTTP and marks the hour processed when every call answers 200. It
 * caught a failed rollup and answered 200 with "Error: …" in the body, so the
 * hour was marked processed with nothing rolled up and never tried again
 * (measured live with the 202_dataengine write refused by a trigger).
 *
 * This holds the one thing the job reads: every exit through the catch sets
 * a 500 before it writes anything. The live measurement is the proof; this
 * is the floor that stops the status line going back out.
 */
final class DataEngineHourAnswersItsFailureTest extends TestCase
{
    public function testAFailedRollupAnswers500(): void
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents(__DIR__ . '/../../202-cronjobs/dej.php')),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $text = static fn ($t): string => is_array($t) ? $t[1] : $t;

        $catches = [];
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_CATCH) {
                $catches[] = $i;
            }
        }
        self::assertCount(1, $catches, 'dej.php has one catch; teach this test a second before adding one');

        $i = $catches[0];
        $type = '';
        for ($j = $i + 2; $text($tokens[$j]) !== ')'; $j++) {
            $type .= $text($tokens[$j]);
        }
        self::assertMatchesRegularExpression('/^\\\\?Throwable\$\w+$/', $type, 'it catches every failure, an Error included');

        // The first statement of the catch body sets the status.
        while ($text($tokens[$j]) !== '{') {
            $j++;
        }
        $first = '';
        for ($k = $j + 1; $text($tokens[$k]) !== ';'; $k++) {
            $first .= $text($tokens[$k]);
        }
        self::assertSame('http_response_code(500)', $first, 'the catch answers 500 before it writes anything');
    }
}

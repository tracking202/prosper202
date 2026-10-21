<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\AutoCronAnswer;
use Tests\Support\SourceScan;

/**
 * What Account › Settings says about the AutoCron service's answer.
 *
 * The service answers {"status":"fail","data":{"code":"402"}} when it refuses
 * an install's address (localhost, a private or IP address, among others), and
 * the page said "The AutoCron service could not be reached ... Try again in a
 * few minutes": it had been reached, and no retry changes the address.
 */
final class AutoCronAnswerTest extends TestCase
{
    public function testAcceptedIsNoRefusal(): void
    {
        $accepted = ['status' => 'success', 'data' => ['code' => '200', 'message' => 'Success']];
        self::assertNull(AutoCronAnswer::refusal($accepted));
    }

    public function testARefusedAddressSaysAutoCronNeedsAPublicAddress(): void
    {
        $answer = '{"status":"fail","data":{"code":"402","message":"URL Not Responding"}}';
        $said = AutoCronAnswer::refusal(json_decode($answer, true));
        self::assertSame(AutoCronAnswer::REFUSED_ADDRESS, $said);
        self::assertStringContainsString('works only for installs at a public web address', (string) $said);
        self::assertStringContainsString('cron job', (string) $said, 'and says what to do instead');
        self::assertStringNotContainsString('could not be reached', (string) $said);
        $asInt = ['status' => 'fail', 'data' => ['code' => 402]];
        self::assertSame(AutoCronAnswer::REFUSED_ADDRESS, AutoCronAnswer::refusal($asInt));
    }

    public function testAnotherRefusalIsSaidAsTurnedAway(): void
    {
        $answers = [
            ['status' => 'fail', 'data' => ['code' => '429', 'message' => 'Too Many Requests']],
            ['status' => 'fail'],
            ['status' => 'fail', 'data' => '402'],
            ['status' => 'gone', 'data' => ['code' => '410']],
            [],
        ];
        foreach ($answers as $answer) {
            self::assertSame(AutoCronAnswer::TURNED_AWAY, AutoCronAnswer::refusal($answer), json_encode($answer));
        }
    }

    public function testNoAnswerIsUnreachable(): void
    {
        foreach ([null, false, '', 'Service Unavailable', 402] as $answer) {
            self::assertSame(AutoCronAnswer::UNREACHABLE, AutoCronAnswer::refusal($answer), var_export($answer, true));
        }
    }

    /** The page says what refusal() says, and has no sentence of its own for a failed call. */
    public function testSettingsSaysTheAnswersSentence(): void
    {
        $page = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/administration.php');
        $start = strpos($page, "if (isset(\$_POST['autocron'])) {");
        self::assertIsInt($start);
        $handler = substr($page, $start, (int) strpos($page, "if (isset(\$_POST['maxmind']))") - $start);
        self::assertSame(1, preg_match_all('/\\\\Prosper202\\\\Cron\\\\AutoCronAnswer::refusal\(\$cron\)/', $handler));
        self::assertSame(1, preg_match_all("/p202_account_flash\('bad', \\\$refusal\)/", $handler));
        self::assertStringNotContainsString('could not be reached', $handler);
    }
}

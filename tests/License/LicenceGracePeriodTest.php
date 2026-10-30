<?php

declare(strict_types=1);

namespace Tests\License;

use Api\V3\Controllers\CapabilitiesController;
use Prosper202\License\ClickServerKeyValidator;
use Prosper202\License\CliAccessCache;
use Prosper202\License\LicenceAnswerStore;
use Prosper202\License\ShellAccessCache;
use Tests\TestCase;

/**
 * When ClickServer gives no answer, a key is licensed only if its last answer
 * was a yes given within the grace period (30 days): never checked, last
 * answered no, or answered longer ago is not licensed. The web sign-in gate,
 * the shell verdict and the Go CLI gate all ask the one helper,
 * ShellAccessCache::validWithinGrace(), which reads the durable record
 * (LicenceAnswerStore; the database in production), not the temp-file caches.
 */
final class LicenceGracePeriodTest extends TestCase
{
    private const int DAY = 86400;

    private string $key;

    private MemoryLicenceAnswerStore $answers;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'grace-test-' . bin2hex(random_bytes(8));
        $this->answers = new MemoryLicenceAnswerStore();
        $this->session = $_SESSION ?? [];
        $_SESSION = [];
        require_once dirname(__DIR__, 2) . '/202-config/functions-auth.php';
    }

    protected function tearDown(): void
    {
        ShellAccessCache::invalidate($this->key);
        CliAccessCache::invalidate($this->key);
        $_SESSION = $this->session;
        unset($GLOBALS['db']);
        parent::tearDown();
    }

    public function testTheGracePeriodIsThirtyDaysAndNothingOlderIsKept(): void
    {
        self::assertSame(30 * self::DAY, ShellAccessCache::GRACE_SECONDS);
        self::assertSame(ShellAccessCache::GRACE_SECONDS, ShellAccessCache::STALE_TTL_SECONDS);
    }

    /**
     * @dataProvider lastAnswers
     * @param class-string<ShellAccessCache> $cache
     */
    public function testTheHelperLicensesOnlyARecentYes(string $cache, ?bool $last, int $age, bool $licensed): void
    {
        $this->record($cache, $last, $age);
        self::assertSame($licensed, $cache::validWithinGrace($this->answers, $this->key));
    }

    /** @return iterable<string, array{class-string<ShellAccessCache>, ?bool, int, bool}> */
    public static function lastAnswers(): iterable
    {
        foreach (['shell' => ShellAccessCache::class, 'cli' => CliAccessCache::class] as $name => $cache) {
            yield "$name: never checked" => [$cache, null, 0, false];
            yield "$name: a yes just now" => [$cache, true, 0, true];
            yield "$name: a yes 29 days ago" => [$cache, true, 29 * self::DAY, true];
            yield "$name: a yes just inside 30 days" => [$cache, true, 30 * self::DAY - 60, true];
            yield "$name: a yes just over 30 days ago" => [$cache, true, 30 * self::DAY + 60, false];
            yield "$name: a yes 31 days ago" => [$cache, true, 31 * self::DAY, false];
            yield "$name: last answered no" => [$cache, false, 0, false];
            yield "$name: last answered no, a day ago" => [$cache, false, self::DAY, false];
        }
    }

    public function testAYesForTheShellIsNotAYesForTheCli(): void
    {
        ShellAccessCache::recordAnswer($this->answers, $this->key, true);
        self::assertTrue(ShellAccessCache::validWithinGrace($this->answers, $this->key));
        self::assertFalse(
            CliAccessCache::validWithinGrace($this->answers, $this->key),
            'a valid key is not a paid licence'
        );
    }

    public function testTheBoundaryIsThirtyDaysToTheSecond(): void
    {
        $at = 1_800_000_000;
        $this->answers->saveAnswer(hash('sha256', $this->key), ShellAccessCache::QUESTION_VALID, true, $at);
        $grace = ShellAccessCache::GRACE_SECONDS;
        self::assertTrue(
            ShellAccessCache::validWithinGrace($this->answers, $this->key, $at + $grace),
            'exactly 30 days'
        );
        self::assertFalse(
            ShellAccessCache::validWithinGrace($this->answers, $this->key, $at + $grace + 1),
            'a second over'
        );
        self::assertFalse(
            ShellAccessCache::validWithinGrace($this->answers, $this->key, $at - 1),
            'an answer from the future'
        );
    }

    public function testTheDurableRecordDecidesWhenTheTempCacheIsGone(): void
    {
        foreach ([ShellAccessCache::class, CliAccessCache::class] as $cache) {
            $cache::recordAnswer($this->answers, $this->key, true);
            $cache::invalidate($this->key);
            self::assertNull($cache::readStale($this->key), 'the temp cache holds nothing');
        }
        $this->age(ShellAccessCache::QUESTION_VALID, 29 * self::DAY);
        $this->age(ShellAccessCache::QUESTION_PAID, 29 * self::DAY);
        self::assertTrue($this->auth(null)::is_valid_api_key($this->key), 'sign-in: a valid answer 29 days ago');
        self::assertTrue($this->shellAccess($this->controller(null)), 'shell: a valid answer 29 days ago');
        self::assertTrue($this->controller(null)->cliAccess(), 'CLI: a paid answer 29 days ago');
    }

    public function testATempCacheYesWithoutTheDurableRecordIsNotEnough(): void
    {
        ShellAccessCache::write($this->key, true);
        CliAccessCache::write($this->key, true);
        $this->forget(false);
        self::assertFalse($this->auth(null)::is_valid_api_key($this->key));
        self::assertFalse($this->shellAccess($this->controller(null)));
        self::assertFalse($this->controller(null)->cliAccess());
    }

    public function testAWholeAnswerIsRecordedForBothQuestions(): void
    {
        $hash = hash('sha256', $this->key);
        ShellAccessCache::recordLicence($this->answers, $this->key, null);
        self::assertSame([], $this->answers->answers, 'no answer records nothing');
        ShellAccessCache::recordLicence($this->answers, $this->key, ['valid' => true, 'paid' => null]);
        self::assertSame([ShellAccessCache::QUESTION_VALID], array_keys($this->answers->answers[$hash]));
        ShellAccessCache::recordLicence($this->answers, $this->key, ['valid' => true, 'paid' => true]);
        self::assertTrue(ShellAccessCache::validWithinGrace($this->answers, $this->key));
        self::assertTrue(CliAccessCache::validWithinGrace($this->answers, $this->key), 'the paid answer too');
        ShellAccessCache::recordLicence($this->answers, $this->key, ['valid' => false, 'paid' => false]);
        self::assertFalse(ShellAccessCache::validWithinGrace($this->answers, $this->key), 'a refusal ends it');
        self::assertFalse(CliAccessCache::validWithinGrace($this->answers, $this->key));
    }

    public function testARecordThatCannotBeReadIsNotLicensed(): void
    {
        ShellAccessCache::recordAnswer($this->answers, $this->key, true);
        $this->answers->unreadable = true;
        self::assertFalse(ShellAccessCache::validWithinGrace($this->answers, $this->key));
        self::assertFalse(ShellAccessCache::validWithinGrace(null, $this->key), 'and no record at all');
    }

    public function testTheRecordNamesTheKeyByItsHashOnly(): void
    {
        ShellAccessCache::recordAnswer($this->answers, $this->key, true);
        CliAccessCache::recordAnswer($this->answers, $this->key, false);
        self::assertSame([hash('sha256', $this->key)], array_keys($this->answers->answers));
        self::assertStringNotContainsString($this->key, serialize($this->answers->answers));
    }

    // ── The web sign-in gate (AUTH::is_valid_api_key) ────────────────────

    /** @dataProvider noAnswerCases */
    public function testTheSignInGateWithNoAnswer(?bool $last, int $age, bool $licensed): void
    {
        $this->record(ShellAccessCache::class, $last, $age);
        self::assertSame($licensed, $this->auth(null)::is_valid_api_key($this->key));
        self::assertSame(!$licensed, $this->unconfirmed(), 'a refusal for want of an answer is said as one');
        self::assertArrayNotHasKey('valid_key', $_SESSION, 'no answer is never remembered for the session');
    }

    /** @return iterable<string, array{?bool, int, bool}> */
    public static function noAnswerCases(): iterable
    {
        yield 'a valid answer two days ago' => [true, 2 * self::DAY, true];
        yield 'a valid answer 29 days ago' => [true, 29 * self::DAY, true];
        yield 'never checked' => [null, 0, false];
        yield 'last answer invalid' => [false, self::DAY, false];
        yield 'a valid answer 31 days ago' => [true, 31 * self::DAY, false];
    }

    public function testTheSignInGateStillTakesAFreshValidAnswer(): void
    {
        $GLOBALS['db'] = $this->recordingDb($queries);
        $_SESSION['user_id'] = 3;
        self::assertTrue($this->auth(['valid' => true, 'paid' => true])::is_valid_api_key($this->key));
        self::assertTrue($_SESSION['valid_key'] ?? null);
        self::assertTrue(
            ShellAccessCache::validWithinGrace($this->answers, $this->key),
            'the answer starts the grace period'
        );
        self::assertTrue(CliAccessCache::validWithinGrace($this->answers, $this->key), 'and the paid answer too');
        self::assertStringContainsString('UPDATE', $queries[0] ?? '');
        self::assertFalse($this->unconfirmed());
    }

    /**
     * The service's or its edge's "no answer" -- a 503, or a 200 whose msg
     * is not "Key valid" -- renews no record at any gate: a key confirmed
     * earlier keeps that answer's time, and a key never confirmed gets
     * nothing.
     *
     * @dataProvider noAnswerShapesAtEachGate
     */
    public function testANoAnswerReplyRenewsNothing(string $gate, int $status, string $body): void
    {
        $answer = ClickServerKeyValidator::interpret($status, $body);
        self::assertNull($answer, "$status $body is no answer");
        $hash = hash('sha256', $this->key);

        self::assertFalse($this->askGate($gate, $answer), 'never confirmed: not licensed');
        self::assertSame([], $this->answers->answers, 'and nothing recorded');

        $earlier = time() - 10 * self::DAY;
        $this->answers->saveAnswer($hash, ShellAccessCache::QUESTION_VALID, true, $earlier);
        $this->answers->saveAnswer($hash, ShellAccessCache::QUESTION_PAID, true, $earlier);
        $this->forget();
        self::assertTrue($this->askGate($gate, $answer), 'confirmed ten days ago: within the grace period');
        self::assertSame(
            [ShellAccessCache::QUESTION_VALID => [true, $earlier], ShellAccessCache::QUESTION_PAID => [true, $earlier]],
            $this->answers->answers[$hash],
            'the earlier answers keep their time'
        );
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function noAnswerShapesAtEachGate(): iterable
    {
        $shapes = [
            '503' => [503, '{"code":503,"msg":"Key not checked"}'],
            '200 not Key valid' => [200, '{"code":200,"msg":"Key not checked","checked":false}'],
        ];
        foreach (['web sign-in' => 'web', 'shell' => 'shell', 'Go CLI' => 'cli'] as $name => $gate) {
            foreach ($shapes as $shape => [$status, $body]) {
                yield "$name, $shape" => [$gate, $status, $body];
            }
        }
    }

    /** @param array{valid: bool, paid: ?bool}|null $answer what the licence service answered */
    private function askGate(string $gate, ?array $answer): bool
    {
        $_SESSION = [];
        return match ($gate) {
            'web' => $this->auth($answer)::is_valid_api_key($this->key),
            'shell' => $this->shellAccess($this->controller($answer)),
            'cli' => $this->controller($answer)->cliAccess(),
        };
    }

    public function testTheSignInGateStillRefusesAnInvalidAnswer(): void
    {
        ShellAccessCache::recordAnswer($this->answers, $this->key, true);
        self::assertFalse($this->auth(['valid' => false, 'paid' => false])::is_valid_api_key($this->key));
        self::assertFalse($this->unconfirmed(), 'a refusal is not "could not be checked"');
        self::assertFalse(
            ShellAccessCache::validWithinGrace($this->answers, $this->key),
            'and it ends the grace period'
        );
    }

    // ── /capabilities: the shell verdict and the Go CLI gate ─────────────

    /** @dataProvider noAnswerCases */
    public function testTheShellVerdictWithNoAnswer(?bool $last, int $age, bool $licensed): void
    {
        $this->record(ShellAccessCache::class, $last, $age);
        self::assertSame($licensed, $this->shellAccess($this->controller(null)));
        self::assertTrue(ShellAccessCache::recentlyUnknown($this->key), 'the outage holds off the next check');
        self::assertSame($licensed, $this->shellAccess($this->controller(null)), 'and the hold-off asks the same');
    }

    /** @dataProvider noAnswerCases */
    public function testTheCliGateWithNoAnswer(?bool $last, int $age, bool $licensed): void
    {
        $this->record(CliAccessCache::class, $last, $age);
        $controller = $this->controller(null);
        self::assertSame($licensed, $controller->cliAccess());
        self::assertSame(
            $licensed ? 'not licensed' : CapabilitiesController::LICENCE_UNCONFIRMED_MESSAGE,
            $controller->cliRefusal('not licensed')
        );
        self::assertTrue(CliAccessCache::recentlyUnknown($this->key), 'the outage holds off the next check');
        self::assertSame($licensed, $this->controller(null)->cliAccess(), 'and the hold-off asks the same');
    }

    /** @dataProvider noAnswerCases */
    public function testTheCliGateWhenTheAnswerHasNoLicenceInIt(?bool $last, int $age, bool $licensed): void
    {
        $this->record(CliAccessCache::class, $last, $age);
        self::assertSame($licensed, $this->controller(['valid' => true, 'paid' => null])->cliAccess());
    }

    public function testFreshAnswersStillDecide(): void
    {
        $paid = $this->controller(['valid' => true, 'paid' => true]);
        self::assertTrue($paid->cliAccess());
        self::assertTrue($this->shellAccess($paid));
        $this->forget();

        CliAccessCache::recordAnswer($this->answers, $this->key, true);
        ShellAccessCache::recordAnswer($this->answers, $this->key, true);
        $this->forget(false);
        $refused = $this->controller(['valid' => false, 'paid' => false]);
        self::assertFalse($refused->cliAccess(), 'an explicit refusal still refuses, grace or not');
        self::assertSame('not licensed', $refused->cliRefusal('not licensed'));
        self::assertFalse($this->shellAccess($refused));
    }

    public function testTheUnconfirmedMessageSaysWhatToCheckAndWhoHelps(): void
    {
        $message = CapabilitiesController::LICENCE_UNCONFIRMED_MESSAGE;
        self::assertStringContainsString('could not be checked with Tracking202', $message);
        self::assertStringContainsString('reach my.tracking202.com over HTTPS', $message);
        self::assertStringContainsString('support@tracking202.com or the support chat', $message);
    }

    public function testTheSignInPageSaysTheSame(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/api-key-required.php');
        self::assertStringContainsString("=== 'unconfirmed'", $page, 'the page reads the gate\'s reason');
        self::assertStringContainsString('could not be checked with Tracking202', $page);
        self::assertStringContainsString('reach my.tracking202.com over HTTPS', $page);
        self::assertStringContainsString('so Prosper202 stays locked until it', $page, 'it says the app is locked');
        self::assertStringContainsString('mailto:support@tracking202.com', $page);
        self::assertStringContainsString(' or the support chat', $page);
        $auth = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-auth.php');
        $redirect = "'api-key-required.php' . (\$unconfirmed ? '?licence=unconfirmed' : '')";
        self::assertStringContainsString($redirect, $auth, 'the gate sends its reason');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Leaves $cache's last answer for the key as $last, given $age seconds
     * ago (null: never checked), in the durable record and the temp cache.
     *
     * @param class-string<ShellAccessCache> $cache
     */
    private function record(string $cache, ?bool $last, int $age): void
    {
        if ($last === null) {
            return;
        }
        $cache::recordAnswer($this->answers, $this->key, $last);
        $question = $cache === CliAccessCache::class
            ? ShellAccessCache::QUESTION_PAID
            : ShellAccessCache::QUESTION_VALID;
        $this->age($question, $age);
        $path = (new \ReflectionMethod($cache, 'path'))->invoke(null, $this->key);
        self::assertIsString($path);
        self::assertTrue(touch($path, time() - $age), 'the cached answer was aged');
        clearstatcache();
    }

    /** Moves the durable answer to $question back by $age seconds from now. */
    private function age(string $question, int $age): void
    {
        $hash = hash('sha256', $this->key);
        self::assertArrayHasKey($question, $this->answers->answers[$hash] ?? [], 'an answer is recorded');
        $this->answers->answers[$hash][$question][1] = time() - $age;
    }

    /** Drops what the caches hold for the key; with $verdicts false, only the hold-offs. */
    private function forget(bool $verdicts = true): void
    {
        foreach ([ShellAccessCache::class, CliAccessCache::class] as $cache) {
            $path = (new \ReflectionMethod($cache, 'path'))->invoke(null, $this->key);
            @unlink($path . '.unknown');
            if ($verdicts) {
                $cache::invalidate($this->key);
            } else {
                // Expired verdicts, so the next request asks.
                touch($path, time() - ShellAccessCache::TTL_SECONDS - 60);
            }
        }
        clearstatcache();
    }

    /** @param array{valid: bool, paid: ?bool}|null $answer what the licence service answers */
    private function controller(?array $answer): CapabilitiesController
    {
        $db = $this->createMysqliMock([
            'SELECT p202_customer_api_key FROM 202_users' => [['p202_customer_api_key' => $this->key]],
        ]);
        return new class ($db, 7, $answer, $this->answers) extends CapabilitiesController {
            /** @param array{valid: bool, paid: ?bool}|null $answer */
            public function __construct(
                \mysqli $db,
                int $userId,
                private readonly ?array $answer,
                private readonly LicenceAnswerStore $answers,
            ) {
                parent::__construct($db, $userId);
            }

            protected function askLicenceService(string $customerKey): ?array
            {
                return $this->answer;
            }

            protected function licenceAnswers(): LicenceAnswerStore
            {
                return $this->answers;
            }
        };
    }

    private function shellAccess(CapabilitiesController $controller): bool
    {
        return (new \ReflectionMethod(CapabilitiesController::class, 'shellAccess'))->invoke($controller);
    }

    /**
     * AUTH with the licence service answering $answer.
     *
     * @param array{valid: bool, paid: ?bool}|null $answer
     */
    private function auth(?array $answer): \AUTH
    {
        $auth = new class () extends \AUTH {
            /** @var array{valid: bool, paid: ?bool}|null */
            public static ?array $answer = null;

            public static ?LicenceAnswerStore $answers = null;

            protected static function askLicenceService(string $key): ?array
            {
                return self::$answer;
            }

            protected static function licenceAnswers(): ?LicenceAnswerStore
            {
                return self::$answers;
            }
        };
        $auth::$answer = $answer;
        $auth::$answers = $this->answers;
        return $auth;
    }

    private function unconfirmed(): bool
    {
        return (new \ReflectionProperty(\AUTH::class, 'licenceUnconfirmed'))->getValue();
    }

    /** @param list<string>|null $queries filled with every statement the connection runs */
    private function recordingDb(?array &$queries): \mysqli
    {
        require_once dirname(__DIR__, 2) . '/202-config/mysql-error-args.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-shared.php';
        $queries = [];
        $db = $this->getMockBuilder(\mysqli::class)->disableOriginalConstructor()->getMock();
        $db->method('real_escape_string')->willReturnCallback(static fn (string $s): string => addslashes($s));
        $db->method('query')->willReturnCallback(static function (string $sql) use (&$queries): bool {
            $queries[] = $sql;
            return true;
        });
        return $db;
    }
}

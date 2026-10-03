<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * The Account pages check what their writes return (CLAUDE.md #1).
 *
 * The review of #165 found the same shape in six places: a `$db->query()`
 * whose result was dropped or never looked at, followed by a session update,
 * a flash saying "saved" or a redirect — account.php's API-key and
 * Stats202-key changes, api-integrations.php's DNI category row,
 * vip-perks.php, ajax/dni.php and ajax/survey.php. The first test is the
 * floor for the whole family: no query call under 202-account/ is a
 * statement on its own, its answer thrown away. It cannot see a result that
 * is assigned and then ignored; the two key handlers, which were that shape,
 * are pinned by the second, and the currency re-pricing (a loop of them) is
 * executed through the real Connection by the rest.
 */
final class AccountQueryResultsAreCheckedTest extends TestCase
{
    private const QUERY_CALLS = ['query', 'multi_query', 'real_query', '_mysqli_query'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testNoAccountFileDiscardsWhatAQueryAnswered(): void
    {
        $root = self::root();
        $found = [];
        $calls = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/202-account', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file->getPathname())), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
            foreach ($tokens as $i => $token) {
                if (!$token->is(T_STRING) || !in_array($token->text, self::QUERY_CALLS, true) || ($tokens[$i + 1]->text ?? '') !== '(') {
                    continue;
                }
                // The start of the call expression: `$x->query(` or `query(`.
                $start = $i;
                if (in_array($tokens[$i - 1]->text ?? '', ['->', '?->'], true) && ($tokens[$i - 2] ?? null)?->is(T_VARIABLE)) {
                    $start = $i - 2;
                } elseif ($token->text !== '_mysqli_query') {
                    continue; // a method on something else, or a declaration
                }
                if (($tokens[$i - 1] ?? null)?->is(T_FUNCTION)) {
                    continue;
                }
                $calls++;
                $before = $tokens[$start - 1] ?? null;
                if ($before !== null && (in_array($before->text, [';', '{', '}'], true) || $before->is([T_ELSE, T_OPEN_TAG]))) {
                    $found[] = "$relative:{$token->line}";
                }
            }
        }
        self::assertGreaterThan(30, $calls, 'the scan found the family\'s queries');
        self::assertSame([], $found, 'a query whose answer is thrown away: check it, and say so when it failed');
    }

    /**
     * The two key handlers set the session and flash "updated" only inside
     * the branch that the write's result decides.
     */
    public function testTheKeyChangesTellTheSessionOnlyWhatWasSaved(): void
    {
        $page = (string) file_get_contents(self::root() . '/202-account/account.php');
        $code = (string) preg_replace('/\s+/', '', implode('', array_map(static fn (\PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $t->text, \PhpToken::tokenize($page))));
        foreach ([
            "\$user_result=\$db->query(\$user_sql);if(\$user_result){\$_SESSION['user_api_key']=\$_POST['user_api_key'];\$_SESSION['user_cirrus_link']=\$_POST['user_api_key'];p202_account_flash('ok','YouhaveupdatedyourTracking202APIKey.');p202_account_redirect('202-account/account.php');}",
            "\$user_result=\$db->query(\$user_sql);if(\$user_result){\$_SESSION['user_stats202_app_key']=\$_POST['user_stats202_app_key'];p202_account_flash('ok','YouhaveupdatedyourStats202AppKey.');p202_account_redirect('202-account/account.php');}",
        ] as $shape) {
            self::assertStringContainsString($shape, $code);
        }
        self::assertSame(1, substr_count($code, "\$_SESSION['user_api_key']=\$_POST"), 'the session key is set in one place');
        self::assertSame(1, substr_count($code, "\$_SESSION['user_stats202_app_key']=\$_POST"), 'and the app key in one');
        self::assertSame(1, preg_match_all('/\bp202_account_save_currency\(/', $page), 'the currency form saves through the one transaction');
        self::assertDoesNotMatchRegularExpression('/UPDATE\s+`?202_aff_campaigns`?/i', $page, 'no campaign re-priced beside it');
    }

    private function campaigns(FakeMysqliConnection $db): void
    {
        $db->whenQueryContainsReturnRows('FROM `202_aff_campaigns`', [
            ['aff_campaign_id' => 1, 'aff_campaign_payout' => '10.00', 'aff_campaign_currency' => 'EUR', 'aff_campaign_foreign_payout' => '0.00'],
            ['aff_campaign_id' => 2, 'aff_campaign_payout' => '0.00', 'aff_campaign_currency' => 'EUR', 'aff_campaign_foreign_payout' => '0.00'],
            ['aff_campaign_id' => 3, 'aff_campaign_payout' => '5.00', 'aff_campaign_currency' => 'GBP', 'aff_campaign_foreign_payout' => '4.00'],
        ]);
    }

    public function testACurrencyChangeRepricesEveryCampaignInOneTransaction(): void
    {
        require_once self::root() . '/202-config/functions-account-ui.php';
        $db = new FakeMysqliConnection();
        $this->campaigns($db);
        $asked = [];
        p202_account_save_currency(new Connection($db), 7, 'USD', 'EUR', static function (string $currency, string $payout) use (&$asked): array {
            $asked[] = "$currency $payout";
            return ['exchange_payout' => (float) $payout * 1.1];
        });
        self::assertSame(['EUR 10.00', 'GBP 4.00'], $asked, 'a payout of 0 is not sent to the rate service');
        self::assertTrue($db->commitCalled);
        self::assertFalse($db->rollbackCalled);
        $updates = $db->statementsContaining('UPDATE `202_aff_campaigns`');
        self::assertCount(3, $updates);
        self::assertSame(['10.00', '11', 1, 7], $updates[0]->boundValues);
        self::assertSame(['0.00', '0', 2, 7], $updates[1]->boundValues);
        self::assertSame(['4.4', 3, 7], $updates[2]->boundValues);
        self::assertSame(['USD', 7], $db->statementsContaining('UPDATE `202_users_pref`')[0]->boundValues);
    }

    public function testARateTheServiceDidNotGiveWritesNothing(): void
    {
        require_once self::root() . '/202-config/functions-account-ui.php';
        // What getForeignPayout() answers for an unreachable service: the
        // missing value divided, so a payout of 0.
        foreach ([['exchange_payout' => 0], null, ['exchange_payout' => 'n/a'], []] as $answer) {
            $db = new FakeMysqliConnection();
            $this->campaigns($db);
            try {
                p202_account_save_currency(new Connection($db), 7, 'USD', 'EUR', static fn (): mixed => $answer);
                self::fail('a missing rate re-priced the campaigns: ' . json_encode($answer));
            } catch (\RuntimeException $refused) {
                self::assertStringContainsString('did not answer with a payout', $refused->getMessage());
            }
            self::assertSame([], $db->statementsContaining('UPDATE'), 'nothing written, the currency included');
            self::assertFalse($db->beginTransactionCalled, 'and no transaction was opened for it');
        }
    }

    public function testAFailedCampaignWriteRollsTheCurrencyBack(): void
    {
        require_once self::root() . '/202-config/functions-account-ui.php';
        $db = new FakeMysqliConnection();
        $this->campaigns($db);
        $db->whenQueryContainsExecuteReturns('UPDATE `202_aff_campaigns` SET `aff_campaign_payout` = ? WHERE', false);
        try {
            p202_account_save_currency(new Connection($db), 7, 'USD', 'EUR', static fn (string $c, string $p): array => ['exchange_payout' => 2]);
            self::fail('a failed campaign write was reported as saved');
        } catch (\Throwable $expected) {
        }
        self::assertSame(1, $db->statementsContaining('UPDATE `202_users_pref`')[0]->executeCount, 'the currency was written first');
        self::assertTrue($db->rollbackCalled, 'and rolled back with the campaigns');
        self::assertFalse($db->commitCalled);
    }
}

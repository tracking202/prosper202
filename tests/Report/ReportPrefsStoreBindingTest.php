<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;
use Tracking202\Report\ReportFilterInput;
use Tracking202\Report\ReportPrefsStore;

/**
 * ReportPrefsStore::save() binds each value as the type of the column it is
 * stored in (CLAUDE.md #7, #163): it bound every value, the user id and the
 * custom window's Unix times included, as 's', which worked only through
 * MySQL's coercion.
 */
final class ReportPrefsStoreBindingTest extends TestCase
{
    public function testEveryIntegerColumnAndTheUserIdAreBoundAsIntegers(): void
    {
        $fake = new FakeMysqliConnection();
        $stored = [
            'user_pref_aff_campaign_id' => '12', 'user_pref_keyword' => 'shoes', 'user_pref_limit' => '100',
            'user_pref_show' => 'real', 'user_pref_time_predefined' => '',
            'user_pref_time_from' => (string) mktime(0, 0, 0, 8, 1, 2026), 'user_pref_time_to' => (string) mktime(23, 59, 59, 8, 31, 2026),
        ];
        $fake->whenQueryContainsReturnRows('SELECT * FROM 202_users_pref', [$stored]);
        $store = new ReportPrefsStore($fake);

        $errors = $store->save(42, ['aff_campaign_id' => '12', 'keyword' => 'shoes', 'user_pref_limit' => '100', 'user_pref_show' => 'real', 'country_id' => ''],
            ['range' => ReportFilterInput::RANGE_CUSTOM, 'from' => [2026, 8, 1], 'to' => [2026, 8, 31]]);
        self::assertSame([], $errors, 'what was written came back');

        $updates = $fake->statementsContaining('UPDATE 202_users_pref');
        self::assertCount(1, $updates);
        $update = $updates[0];
        preg_match_all('/`(\w+)` = \?/', $update->sql, $m);
        $columns = $m[1];
        $columns[] = 'user_id';
        self::assertSame(strlen($update->boundTypes), count($columns), 'one type per placeholder');
        $byColumn = [];
        foreach ($columns as $i => $column) {
            $byColumn[$column] = [$update->boundTypes[$i], $update->boundValues[$i]];
        }
        self::assertSame(['i', 12], $byColumn['user_pref_aff_campaign_id']);
        self::assertSame(['i', null], $byColumn['user_pref_country_id'], 'not filtering is NULL');
        self::assertSame(['i', 100], $byColumn['user_pref_limit']);
        self::assertSame(['s', 'shoes'], $byColumn['user_pref_keyword']);
        self::assertSame(['s', 'real'], $byColumn['user_pref_show']);
        self::assertSame(['s', ''], $byColumn['user_pref_time_predefined']);
        self::assertSame(['i', mktime(0, 0, 0, 8, 1, 2026)], $byColumn['user_pref_time_from'], 'a Unix time is an integer');
        self::assertSame(['i', mktime(23, 59, 59, 8, 31, 2026)], $byColumn['user_pref_time_to']);
        self::assertSame(['i', 42], $byColumn['user_id']);
        self::assertTrue($fake->commitCalled);
    }

    public function testANonNumberForAnIntegerColumnIsAProgrammingErrorNotACoercion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'user_pref_aff_campaign_id'");
        ReportPrefsStore::bindingsFor(['user_pref_aff_campaign_id' => '12abc']);
    }
}

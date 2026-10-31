<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ClickFilterController;
use Api\V3\Controllers\ClicksController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\NothingToUpdateException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * /users/{id}/click-filter's handlers against a real server, and the reason
 * GET /clicks and GET /clicks/{id} serve a filtered click with: what each
 * answers, what each refuses by name, and that a GET body goes back whole.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST, ...); it
 * writes users 5821-5822's click filter rows and clicks 99582101-99582106.
 *
 * @group integration
 */
final class ClickFilterApiIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 5821;
    private const OTHER = 5822;
    private const CLICK = 99582101;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        $clicks = [
            // click_id offset => [filtered, bot, reason row or null, entry]
            0 => [0, 0, null, 0],
            1 => [1, 1, null, 0],
            2 => [1, 0, 'repeat_visit', 0],
            3 => [1, 0, 'filter_list', 77],
            4 => [1, 0, null, 0],
            5 => [1, 0, 'sign_in_address', 0],
        ];
        foreach ($clicks as $offset => [$filtered, $bot, $reason, $entry]) {
            $id = self::CLICK + $offset;
            self::q('INSERT INTO 202_clicks (click_id, user_id, ppc_account_id, click_cpc, click_filtered, click_bot, click_time)'
                . " VALUES ($id, " . self::USER . ", 0, 0, $filtered, $bot, " . (1767225600 + $offset) . ')');
            if ($reason !== null) {
                self::q("INSERT INTO 202_clicks_filter_reason (click_id, filter_reason, entry_id) VALUES ($id, '$reason', $entry)");
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        foreach (['202_click_filter_entries', '202_click_filter_settings'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
    }

    private static function cleanUp(): void
    {
        foreach (['202_click_filter_entries', '202_click_filter_settings'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
        foreach (['202_clicks', '202_clicks_filter_reason'] as $table) {
            self::q("DELETE FROM $table WHERE click_id BETWEEN " . self::CLICK . ' AND ' . (self::CLICK + 9));
        }
    }

    private static function api(): ClickFilterController
    {
        return new ClickFilterController(self::$db);
    }

    /** @param callable(): mixed $call @return array<string, string> the field errors */
    private static function refused(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('accepted');
    }

    public function testAnAccountThatNeverChangedItAnswersTheDefaults(): void
    {
        $data = self::api()->get(self::USER)['data'];

        self::assertSame(
            ['sign_in_addresses' => true, 'crawler_ranges' => true, 'repeat_visits' => true, 'repeat_window' => '24h', 'entries' => []],
            array_diff_key($data, ['limits' => true])
        );
        self::assertSame(500, $data['limits']['max_entries']);
        self::assertSame(['1h', '6h', '24h', '7d'], $data['limits']['repeat_windows']);
    }

    public function testAnUpdateChangesWhatItNamesAndAGetBodyGoesBackWhole(): void
    {
        $data = self::api()->update(self::USER, ['repeat_window' => '6h', 'sign_in_addresses' => 'false'])['data'];
        self::assertSame('6h', $data['repeat_window']);
        self::assertFalse($data['sign_in_addresses'], '"false" is false, never (bool) "false"');
        self::assertTrue($data['crawler_ranges']);

        self::api()->addEntry(self::USER, ['address' => '203.0.113.0/24', 'action' => 'filter']);
        $body = self::api()->get(self::USER)['data'];
        $body['repeat_visits'] = false;
        self::assertFalse(self::api()->update(self::USER, $body)['data']['repeat_visits']);

        self::assertTrue(self::api()->get(self::OTHER)['data']['sign_in_addresses'], 'another account is untouched');
    }

    public function testAnUpdateRefusesByNameWhatItCannotTake(): void
    {
        self::assertArrayHasKey('repeat_windw', self::refused(fn () => self::api()->update(self::USER, ['repeat_windw' => '6h'])));
        self::assertArrayHasKey('repeat_window', self::refused(fn () => self::api()->update(self::USER, ['repeat_window' => '2h'])));
        self::assertArrayHasKey('crawler_ranges', self::refused(fn () => self::api()->update(self::USER, ['crawler_ranges' => 'yes'])));
        $errors = self::refused(fn () => self::api()->update(self::USER, [
            'entries' => [['address' => '198.51.100.7', 'action' => 'count']],
        ]));
        self::assertStringContainsString('one entry at a time', $errors['entries']);
        self::assertTrue(self::api()->get(self::USER)['data']['crawler_ranges'], 'nothing refused was written');

        $this->expectException(NothingToUpdateException::class);
        self::api()->update(self::USER, ['entries' => []]);
    }

    public function testAnEntryIsAddedChangedAndRemovedOnItsAccountOnly(): void
    {
        $added = self::api()->addEntry(self::USER, ['address' => '2001:db8::/32', 'action' => 'count', 'note' => 'QA']);
        self::assertSame(201, $added['_status']);
        $id = $added['data']['entry_id'];
        self::assertSame(
            ['address' => '2001:db8::/32', 'action' => 'count', 'note' => 'QA'],
            array_intersect_key($added['data'], ['address' => 1, 'action' => 1, 'note' => 1])
        );

        try {
            self::api()->addEntry(self::USER, ['address' => '2001:db8::/32', 'action' => 'filter']);
            self::fail('the same block twice');
        } catch (ConflictException $e) {
            self::assertSame(['entry_id' => $id], $e->getDetails());
        }

        $changed = self::api()->updateEntry(self::USER, $id, [...$added['data'], 'action' => 'filter', 'note' => ''])['data'];
        self::assertSame(['filter', ''], [$changed['action'], $changed['note']]);
        self::assertArrayHasKey('entry_id', self::refused(fn () => self::api()->updateEntry(self::USER, $id, ['entry_id' => $id + 1])));
        self::assertArrayHasKey('address', self::refused(fn () => self::api()->updateEntry(self::USER, $id, ['address' => '2001:db8::1/32'])));

        $preview = self::api()->deleteEntryPreview(self::USER, $id)['data'];
        self::assertTrue($preview['dry_run']);
        self::assertSame($id, $preview['record']['entry_id']);

        foreach (
            [
            fn () => self::api()->updateEntry(self::OTHER, $id, ['note' => 'x']),
            fn () => self::api()->deleteEntry(self::OTHER, $id),
            fn () => self::api()->deleteEntryPreview(self::OTHER, $id),
            ] as $call
        ) {
            try {
                $call();
                self::fail('another account\'s entry was reached');
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
        self::api()->deleteEntry(self::USER, $id);
        self::assertSame([], self::api()->get(self::USER)['data']['entries']);
    }

    public function testAnEntryRefusesByNameWhatItCannotTake(): void
    {
        self::assertSame(['action', 'address'], array_keys(self::refused(fn () => self::api()->addEntry(self::USER, []))));
        self::assertArrayHasKey('adress', self::refused(fn () => self::api()->addEntry(self::USER, ['adress' => '203.0.113.7', 'action' => 'count'])));
        self::assertArrayHasKey('address', self::refused(fn () => self::api()->addEntry(self::USER, ['address' => '10.0.0.0/7', 'action' => 'count'])));
        self::assertArrayHasKey('action', self::refused(fn () => self::api()->addEntry(self::USER, ['address' => '203.0.113.7', 'action' => 'block'])));
        self::assertSame([], self::api()->get(self::USER)['data']['entries']);
    }

    public function testATestAnswersTheRuleAndRefusesWhatIsNoAddress(): void
    {
        self::api()->addEntry(self::USER, ['address' => '198.51.100.0/24', 'action' => 'filter', 'note' => 'farm']);
        $data = self::api()->test(self::USER, ['ip' => '198.51.100.20'])['data'];
        self::assertTrue($data['filtered']);
        self::assertSame('filter_list', $data['reason']);
        self::assertStringContainsString('"farm"', $data['explanation']);

        self::assertArrayHasKey('ip', self::refused(fn () => self::api()->test(self::USER, [])));
        self::assertArrayHasKey('ip', self::refused(fn () => self::api()->test(self::USER, ['ip' => '198.51.100.0/24'])));
        self::assertArrayHasKey('address', self::refused(fn () => self::api()->test(self::USER, ['address' => '198.51.100.20'])));
    }

    public function testAClickSaysWhyItIsFiltered(): void
    {
        $clicks = new ClicksController(self::$db, self::USER);
        $reason = static fn (int $offset): array => array_intersect_key(
            $clicks->get(self::CLICK + $offset)['data'],
            ['filter_reason' => 1, 'filter_entry_id' => 1]
        );

        self::assertSame(['filter_reason' => null, 'filter_entry_id' => null], $reason(0), 'counted');
        self::assertSame(['filter_reason' => 'bot', 'filter_entry_id' => null], $reason(1));
        self::assertSame(['filter_reason' => 'repeat_visit', 'filter_entry_id' => null], $reason(2));
        self::assertSame(['filter_reason' => 'filter_list', 'filter_entry_id' => 77], $reason(3));
        self::assertSame(['filter_reason' => null, 'filter_entry_id' => null], $reason(4), 'filtered before reasons were recorded');
    }

    public function testTheListFiltersByWhy(): void
    {
        $clicks = new ClicksController(self::$db, self::USER);
        $ids = static fn (string $why): array => array_map(
            static fn (array $row): int => (int) $row['click_id'],
            $clicks->list(['filter_reason' => $why, 'limit' => '50'])['data']
        );

        self::assertSame([self::CLICK + 1], $ids('bot'));
        self::assertSame([self::CLICK + 2], $ids('repeat_visit'));
        self::assertSame([self::CLICK + 3], $ids('filter_list'));
        self::assertSame([self::CLICK + 5], $ids('sign_in_address'));
        self::assertSame([self::CLICK + 4], $ids('not_recorded'));
        self::assertSame([], $ids('crawler_range'));
        self::assertSame(1, $clicks->list(['filter_reason' => 'repeat_visit'])['pagination']['total'], 'the count filters too');
        self::assertArrayHasKey('filter_reason', self::refused(fn () => $clicks->list(['filter_reason' => 'farm'])));
    }
}

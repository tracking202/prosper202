<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The Update endpoints over HTTP, against a running instance: P202_BASE with
 * the Super user's REST key in P202_API_KEY (the key the installer mints, as
 * the Agent Evals job has it).
 *
 * The suite builds what it needs through the API — two categories, three
 * campaigns, a traffic source, two direct-link trackers — and records real
 * clicks through tracking202/redirect/dl.php, the path a visitor takes. Every
 * write is then read back through the API the way a caller would check it:
 * the click's cost (GET /clicks/{id}), its conversions and whether they count
 * (GET /clicks/{id}/conversions), the ledger rows by source
 * (GET /conversions?source=…). Every refusal is asserted positively, by status
 * and by the sentence the guard writes ("not succeeded" is not "refused").
 *
 * Two more users are created and removed: a Campaign viewer (role 5, no
 * access_to_update_section) and a Campaign manager (role 3, which has it but
 * not delete_individual_subids), each with a key, for the role checks the
 * pages make.
 *
 * @group integration
 * @group instance
 */
final class UpdateEndpointsInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';

    /** @var array<string, int> */
    private static array $ids = [];
    /** @var array<string, string> tracker name => public id */
    private static array $trackers = [];
    /** @var array<string, string> role name => key */
    private static array $roleKeys = [];
    /** @var list<int> */
    private static array $users = [];
    private static string $timezone = 'UTC';

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        [$status] = self::call('', 'GET', '/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }

        self::$run = 'upd' . bin2hex(random_bytes(4));
        $run = self::$run;
        try {
            self::$ids['net_a'] = self::create('aff-networks', ['aff_network_name' => "$run cat A"], 'aff_network_id');
            self::$ids['net_b'] = self::create('aff-networks', ['aff_network_name' => "$run cat B"], 'aff_network_id');
            foreach (['camp_a1' => ['net_a', '2.50'], 'camp_a2' => ['net_a', '4.00'], 'camp_b1' => ['net_b', '1.00']] as $name => [$net, $payout]) {
                self::$ids[$name] = self::create('campaigns', [
                    'aff_campaign_name' => "$run $name", 'aff_campaign_url' => 'https://example.com/' . $name,
                    'aff_campaign_payout' => $payout, 'aff_network_id' => self::$ids[$net],
                ], 'aff_campaign_id');
            }
            self::$ids['ppc_net'] = self::create('ppc-networks', ['ppc_network_name' => "$run source"], 'ppc_network_id');
            self::$ids['ppc_acct'] = self::create('ppc-accounts', ['ppc_account_name' => "$run account", 'ppc_network_id' => self::$ids['ppc_net']], 'ppc_account_id');
            foreach (['camp_a1', 'camp_a2'] as $campaign) {
                [$status, $body] = self::call(self::$key, 'POST', '/trackers', ['aff_campaign_id' => self::$ids[$campaign], 'ppc_account_id' => self::$ids['ppc_acct']]);
                $public = (string) ($body['data']['tracker_id_public'] ?? '');
                if ($status !== 201 || $public === '') {
                    throw new \RuntimeException("POST /trackers answered $status: " . json_encode($body));
                }
                self::$trackers[$campaign] = $public;
            }

            // The account's time zone decides which day a click is on.
            [$status, $body] = self::call(self::$key, 'POST', '/clicks/cpc?dry_run=1', ['from' => '2026-01-01', 'to' => '2026-01-01', 'cpc' => '0.01']);
            if ($status !== 200 || !is_string($body['data']['timezone'] ?? null)) {
                throw new \RuntimeException("The CPC dry run answered $status: " . json_encode($body));
            }
            self::$timezone = $body['data']['timezone'];

            foreach ([5 => 'viewer', 3 => 'manager'] as $role => $name) {
                [$status, $body] = self::call(self::$key, 'POST', '/users', [
                    'user_name' => "$run$name", 'user_email' => "$run$name@example.com", 'user_pass' => 'pass-' . $run . '-1',
                ]);
                $userId = (int) ($body['data']['user_id'] ?? 0);
                if ($status !== 201 || $userId <= 1) {
                    throw new \RuntimeException("Creating the $name user answered $status: " . json_encode($body));
                }
                self::$users[] = $userId;
                [$status, $body] = self::call(self::$key, 'POST', '/users/' . $userId . '/roles', ['role_id' => $role]);
                if ($status !== 200) {
                    throw new \RuntimeException("Granting role $role answered $status: " . json_encode($body));
                }
                [$status, $body] = self::call(self::$key, 'POST', '/users/' . $userId . '/api-keys', []);
                self::$roleKeys[$name] = (string) ($body['data']['api_key'] ?? '');
                if ($status !== 201 || self::$roleKeys[$name] === '') {
                    throw new \RuntimeException("Minting the $name key answered $status: " . json_encode($body));
                }
            }
        } catch (\RuntimeException $e) {
            self::$setupError = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$users as $userId) {
            self::call(self::$key, 'DELETE', '/users/' . $userId);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    // ─── Update CPC ─────────────────────────────────────────────────────

    public function testTheCpcCheckCountsAndTheConfirmChangesExactlyThoseClicks(): void
    {
        $a1 = $this->clicks('camp_a1', 3);
        $a2 = $this->clicks('camp_a2', 2);
        $before = array_map(fn (int $id): float|int => $this->click($id)['click_cpc'], $a2);

        $selection = $this->today() + ['cpc' => '0.33333', 'aff_campaign_id' => self::$ids['camp_a1']];
        [$status, $preview] = $this->post('/clicks/cpc?dry_run=1', $selection);
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertTrue($preview['data']['dry_run']);
        $this->assertSame(count($this->campaignClicks('camp_a1')), $preview['data']['matching'], 'the check counts the campaign\'s clicks of the day');
        $this->assertSame(max($this->campaignClicks('camp_a1')), $preview['data']['through_click_id']);
        $this->assertSame('0.33333', $preview['data']['cpc']);
        $this->assertSame(self::$run . ' camp_a1', $preview['data']['filters']['aff_campaign_id']['name']);
        foreach ($a1 as $id) {
            $this->assertNotSame(0.33333, $this->click($id)['click_cpc'], 'a dry run writes nothing');
        }

        [$status, $applied] = $this->post('/clicks/cpc', $selection + [
            'expect_clicks' => $preview['data']['matching'], 'through_click_id' => $preview['data']['through_click_id'],
        ]);
        $this->assertSame(200, $status, json_encode($applied));
        $this->assertFalse($applied['data']['dry_run']);
        $this->assertSame($preview['data']['matching'], $applied['data']['matching']);
        foreach ($this->campaignClicks('camp_a1') as $id) {
            $this->assertSame(0.33333, $this->click($id)['click_cpc'], "click $id of the campaign costs the new CPC");
        }
        foreach ($a2 as $i => $id) {
            $this->assertSame($before[$i], $this->click($id)['click_cpc'], "click $id of the other campaign is untouched");
        }

        // Direct-link clicks only: the landing-page slice is empty.
        [$status, $lp] = $this->post('/clicks/cpc?dry_run=1', $selection + ['method_of_promotion' => 'landingpage']);
        $this->assertSame(200, $status, json_encode($lp));
        $this->assertSame(0, $lp['data']['matching']);
    }

    public function testAConfirmWhoseCountMovedWritesNothingAndTheBoundaryHoldsBackLaterClicks(): void
    {
        $this->clicks('camp_a2', 2);
        $selection = $this->today() + ['cpc' => '0.77', 'aff_campaign_id' => self::$ids['camp_a2']];
        [, $preview] = $this->post('/clicks/cpc?dry_run=1', $selection);
        $matching = $preview['data']['matching'];
        $through = $preview['data']['through_click_id'];
        $this->assertGreaterThanOrEqual(2, $matching);
        $costs = fn (): array => array_map(fn (int $id): float|int => $this->click($id)['click_cpc'], $this->campaignClicks('camp_a2'));
        $before = $costs();

        // A count that no longer matches, as a click edited into or out of
        // the selection leaves it: refused, nothing written, the new count said.
        [$status, $stale] = $this->post('/clicks/cpc', $selection + ['expect_clicks' => $matching + 1, 'through_click_id' => $through]);
        $this->assertSame(409, $status, json_encode($stale));
        $this->assertStringContainsString('Nothing was changed', $stale['message']);
        $this->assertSame($matching + 1, $stale['details']['expect_clicks']);
        $this->assertSame($matching, $stale['details']['matching']);
        $this->assertSame($before, $costs(), 'a refused confirm writes nothing');

        // A click recorded after the check, above its boundary, is not one
        // the person counted: the confirm still applies, and leaves it alone.
        $late = $this->clicks('camp_a2', 1)[0];
        $this->assertGreaterThan($through, $late);
        [$status, $applied] = $this->post('/clicks/cpc', $selection + ['expect_clicks' => $matching, 'through_click_id' => $through]);
        $this->assertSame(200, $status, json_encode($applied));
        $this->assertSame(0.77, $this->click($through)['click_cpc']);
        $this->assertNotSame(0.77, $this->click($late)['click_cpc'], 'the click recorded after the check keeps its cost');

        // Without what it confirms, a write is refused by name.
        [$status, $bare] = $this->post('/clicks/cpc', $selection);
        $this->assertSame(422, $status, json_encode($bare));
        $this->assertStringContainsString('dry_run=1', $bare['field_errors']['expect_clicks'] ?? '');
        $this->assertArrayHasKey('through_click_id', $bare['field_errors']);
    }

    public function testACpcRequestThatCannotBeReadIsRefusedByName(): void
    {
        $day = $this->today();
        $cases = [
            'a misspelled filter' => [$day + ['cpc' => '0.1', 'aff_campaing_id' => 3], 'aff_campaing_id', 'is not a field of a CPC update'],
            'dry_run in the body' => [$day + ['cpc' => '0.1', 'dry_run' => true], 'dry_run', 'query string'],
            'another account\'s campaign' => [$day + ['cpc' => '0.1', 'aff_campaign_id' => 999999999], 'aff_campaign_id', 'is not one of this account\'s'],
            'a fractional id' => [$day + ['cpc' => '0.1', 'aff_campaign_id' => 1.5], 'aff_campaign_id', 'GET /campaigns'],
            'a null id' => [$day + ['cpc' => '0.1', 'ppc_account_id' => null], 'ppc_account_id', 'GET /ppc-accounts'],
            'six decimals as a number' => [$day + ['cpc' => 0.123456], 'cpc', 'five decimals'],
            'over the column' => [$day + ['cpc' => '100'], 'cpc', 'at most $99.99999'],
            'a word' => [$day + ['cpc' => 'free'], 'cpc', 'is not a CPC'],
            'not a day' => [['from' => '2026-02-30', 'to' => '2026-03-01', 'cpc' => '0.1'], 'from', 'YYYY-MM-DD'],
            'backwards' => [['from' => '2026-03-02', 'to' => '2026-03-01', 'cpc' => '0.1'], 'to', 'is before from'],
            'an unknown method' => [$day + ['cpc' => '0.1', 'method_of_promotion' => 'both'], 'method_of_promotion', 'directlink'],
        ];
        foreach ($cases as $name => [$body, $field, $sentence]) {
            [$status, $answer] = $this->post('/clicks/cpc?dry_run=1', $body);
            $this->assertSame(422, $status, "$name: " . json_encode($answer));
            $this->assertStringContainsString($sentence, (string) ($answer['field_errors'][$field] ?? ''), "$name: " . json_encode($answer));
        }

        // A JSON number of five decimals is read exactly.
        [$status, $answer] = $this->post('/clicks/cpc?dry_run=1', $day + ['cpc' => 0.125]);
        $this->assertSame(200, $status, json_encode($answer));
        $this->assertSame('0.12500', $answer['data']['cpc']);

        [$status, $answer] = $this->post('/clicks/cpc?dry_run=tru', $day + ['cpc' => '0.1']);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('dry_run=1', (string) ($answer['field_errors']['dry_run'] ?? ''));

        foreach (['/clicks/cpc', '/conversions/subids', '/conversions/subids/delete', '/conversions/subids/reset', '/conversions/uploads'] as $path) {
            [$status, $answer] = $this->post($path . '?staged=1', ['subids' => ['1']]);
            $this->assertSame(422, $status, "$path?staged=1: " . json_encode($answer));
            $this->assertStringContainsString('staged is not supported', (string) $answer['message'], $path);
        }
    }

    // ─── Subids ─────────────────────────────────────────────────────────

    public function testMarkingSubidsRecordsSubidUploadConversionsOncePerClick(): void
    {
        [$one, $two] = $this->clicks('camp_a1', 2);
        $list = [(string) $one, ' ' . $two . ' ', 'abc', '999999999999', (string) $one, ''];

        [$status, $preview] = $this->post('/conversions/subids?dry_run=1', ['subids' => $list]);
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertSame(['would_mark' => 2, 'already_converted' => 0, 'not_found' => 1, 'not_a_subid' => 1, 'duplicate_in_list' => 1],
            array_intersect_key($preview['data'], array_flip(['would_mark', 'already_converted', 'not_found', 'not_a_subid', 'duplicate_in_list'])));
        $this->assertCount(5, $preview['data']['lines'], 'every non-blank line is reported, the blank one is not');
        $this->assertSame(1, $preview['data']['lines'][4]['first_line']);
        $this->assertSame(0, (int) $this->click($one)['click_lead'], 'a dry run writes nothing');

        [$status, $marked] = $this->post('/conversions/subids', ['subids' => $list]);
        $this->assertSame(200, $status, json_encode($marked));
        $this->assertSame(2, $marked['data']['marked']);
        $this->assertSame(['marked', 'marked', 'not_a_subid', 'not_found', 'duplicate_in_list'], array_column($marked['data']['lines'], 'status'));
        foreach ([$one, $two] as $id) {
            $this->assertSame(1, (int) $this->click($id)['click_lead'], "click $id is a lead");
            $rows = $this->conversionsOf($id);
            $this->assertSame(['subid_upload'], array_column($rows, 'source'), "click $id has one subid_upload conversion");
            $this->assertTrue($rows[0]['counted']);
        }
        [, $listed] = self::call(self::$key, 'GET', '/conversions?source=subid_upload&click_id=' . $one);
        $this->assertSame(1, $listed['pagination']['total']);

        [$status, $again] = $this->post('/conversions/subids', ['subids' => [(string) $one, $two]]);
        $this->assertSame(200, $status, json_encode($again));
        $this->assertSame(0, $again['data']['marked']);
        $this->assertSame(2, $again['data']['already_converted'], 'marking a converted subid again adds nothing');
        $this->assertCount(1, $this->conversionsOf($one));

        foreach ([['subids' => []], ['subids' => ['', ' ']], ['subids' => [true]], ['subids' => '940001'], []] as $bad) {
            [$status, $answer] = $this->post('/conversions/subids', $bad);
            $this->assertSame(422, $status, json_encode($bad) . ': ' . json_encode($answer));
            $this->assertNotEmpty($answer['field_errors'] ?? []);
        }
        // An entry is named by its position, as every list in a body is
        // (items.0.unit_price): not subids[1].
        [$status, $answer] = $this->post('/conversions/subids', ['subids' => ['940001', true]]);
        $this->assertSame(422, $status, json_encode($answer));
        $this->assertSame(['subids.1'], array_keys($answer['field_errors'] ?? []));
    }

    public function testDeletingSubidsClearsTheirConversionsThroughTheLedger(): void
    {
        [$one, $two] = $this->clicks('camp_a1', 2);
        $this->post('/conversions/subids', ['subids' => [(string) $one, (string) $two]]);
        $this->assertSame(1, (int) $this->click($one)['click_lead']);

        [$status, $preview] = $this->post('/conversions/subids/delete?dry_run=1', ['subids' => [(string) $one, (string) $two, '999999999999', 'x']]);
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertSame(2, $preview['data']['would_clear']);
        $this->assertSame(2, $preview['data']['conversions'], 'the live conversions the delete would clear');
        $this->assertSame(1, $preview['data']['not_found']);
        $this->assertSame(1, $preview['data']['not_a_subid']);
        $this->assertSame(1, (int) $this->click($one)['click_lead'], 'a dry run writes nothing');

        [$status, $cleared] = $this->post('/conversions/subids/delete', ['subids' => [(string) $one, (string) $two, '999999999999', 'x']]);
        $this->assertSame(200, $status, json_encode($cleared));
        $this->assertSame(2, $cleared['data']['cleared']);
        foreach ([$one, $two] as $id) {
            $this->assertSame(0, (int) $this->click($id)['click_lead'], "click $id is no longer a lead");
            $rows = $this->conversionsOf($id);
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertFalse($row['counted'], "click $id: conversion {$row['conv_id']} no longer counts");
                $this->assertSame('deleted', $row['not_counted_reason']);
            }
        }
    }

    public function testResettingACampaignClearsItsConvertedClicksAndNoOthers(): void
    {
        $a2 = $this->clicks('camp_a2', 2);
        $a1 = $this->clicks('camp_a1', 1);
        $this->post('/conversions/subids', ['subids' => array_map('strval', [...$a2, ...$a1])]);
        $converted = count(array_filter($this->campaignClicks('camp_a2'), fn (int $id): bool => (int) $this->click($id)['click_lead'] === 1));
        $this->assertGreaterThanOrEqual(2, $converted);

        $scope = ['aff_network_id' => self::$ids['net_a'], 'aff_campaign_id' => self::$ids['camp_a2']];
        [$status, $preview] = $this->post('/conversions/subids/reset?dry_run=1', $scope);
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertSame($converted, $preview['data']['matching']);
        $this->assertSame(self::$run . ' camp_a2', $preview['data']['aff_campaign']['name']);

        [$status, $reset] = $this->post('/conversions/subids/reset', $scope);
        $this->assertSame(200, $status, json_encode($reset));
        $this->assertSame($converted, $reset['data']['cleared']);
        foreach ($a2 as $id) {
            $this->assertSame(0, (int) $this->click($id)['click_lead'], "click $id of the reset campaign is no longer a lead");
        }
        $this->assertSame(1, (int) $this->click($a1[0])['click_lead'], 'a click of another campaign in the category keeps its conversion');

        $refusals = [
            [[], 'aff_network_id', 'is required'],
            [['aff_network_id' => 999999999], 'aff_network_id', 'is not one of this account\'s'],
            [['aff_network_id' => self::$ids['net_a'], 'aff_campaign_id' => self::$ids['camp_b1']], 'aff_campaign_id', 'is in category ' . self::$ids['net_b']],
            [['aff_network_id' => '07'], 'aff_network_id', 'GET /aff-networks'],
        ];
        foreach ($refusals as [$body, $field, $sentence]) {
            [$status, $answer] = $this->post('/conversions/subids/reset', $body);
            $this->assertSame(422, $status, json_encode($body) . ': ' . json_encode($answer));
            $this->assertStringContainsString($sentence, (string) ($answer['field_errors'][$field] ?? ''), json_encode($answer));
        }
    }

    // ─── Revenue upload ─────────────────────────────────────────────────

    public function testARevenueReportRecordsEachLineAndTheNewestReportReplacesTheLast(): void
    {
        [$one, $two] = $this->clicks('camp_a1', 2);
        $csv = "Sub ID,Order,Commission\n$one,o1,\$1.50\n$one,o2,2.25\n$two,o3,abc\nxyz,o4,1\n999999999999,o5,3\n";

        [$status, $preview] = $this->post('/conversions/uploads?dry_run=1', ['csv' => $csv, 'file_name' => self::$run . '.csv']);
        $this->assertSame(200, $status, json_encode($preview));
        $this->assertSame(['index' => 0, 'header' => 'Sub ID'], $preview['data']['columns']['subid']);
        $this->assertSame(['index' => 2, 'header' => 'Commission'], $preview['data']['columns']['amount']);
        $this->assertSame(['subid_column', 'amount_column'], $preview['data']['columns']['guessed']);
        $this->assertSame(2, $preview['data']['would_record']);
        $this->assertSame(3, $preview['data']['skipped']);
        $this->assertSame([['click_id' => $one, 'total' => '3.75000']], $preview['data']['totals'], 'a subid on several lines earns their sum, at the ledger\'s five decimals');
        // The lines not recorded are listed, as the page lists them; the
        // recorded ones are counted and summed per click.
        $this->assertSame([1, 4, 5, 6], array_column($preview['data']['lines'], 'line'));
        $this->assertSame(['header', 'skipped', 'skipped', 'skipped'], array_column($preview['data']['lines'], 'status'));
        $this->assertSame(0, $preview['data']['lines_unlisted']);
        $this->assertSame(1, $preview['data']['clicks']);
        $this->assertSame('3.75000', $preview['data']['total']);
        $this->assertSame(0, $preview['data']['totals_unlisted']);
        $this->assertSame([
            ['reason' => 'the commission is not a number', 'lines' => 1],
            ['reason' => 'not a subid (a click id is a whole number)', 'lines' => 1],
            ['reason' => 'no click with this subid in your account', 'lines' => 1],
        ], $preview['data']['skipped_reasons']);
        $this->assertNull($preview['data']['batch_id']);
        $this->assertSame(0, (int) $this->click($one)['click_lead'], 'a dry run writes nothing');

        [$status, $upload] = $this->post('/conversions/uploads', ['csv' => $csv, 'file_name' => self::$run . '.csv']);
        $this->assertSame(200, $status, json_encode($upload));
        $this->assertGreaterThan(0, $upload['data']['batch_id']);
        $this->assertSame(2, $upload['data']['recorded']);
        $this->assertSame(['the commission is not a number', 'not a subid (a click id is a whole number)', 'no click with this subid in your account'],
            array_column(array_values(array_filter($upload['data']['lines'], static fn (array $l): bool => $l['status'] === 'skipped')), 'reason'));
        $this->assertSame(1, (int) $this->click($one)['click_lead']);
        $this->assertSame('3.75', number_format((float) $this->click($one)['click_payout'], 2, '.', ''), 'a subid on several lines earns their sum');
        $this->assertSame(['revenue_upload', 'revenue_upload'], array_column($this->conversionsOf($one), 'source'));

        // The next report replaces what the last one set for the click.
        [$status, $next] = $this->post('/conversions/uploads', ['csv' => "subid,payout\n$one,5\n", 'subid_column' => 'SUBID', 'amount_column' => 1]);
        $this->assertSame(200, $status, json_encode($next));
        $this->assertSame([], $next['data']['columns']['guessed']);
        $this->assertSame('5.00', number_format((float) $this->click($one)['click_payout'], 2, '.', ''), 'the newest report replaces the earlier one');

        // A report the wrong subid column makes all skipped: the first 1,000
        // lines not recorded are listed and the rest counted, by reason, so
        // the answer stays small enough for the CLI to read.
        $junk = "subid,payout\n";
        for ($i = 0; $i < 1500; $i++) {
            $junk .= 'order-' . $i . ",1\n";
        }
        [$status, $wrong] = $this->post('/conversions/uploads?dry_run=1', ['csv' => $junk]);
        $this->assertSame(200, $status, json_encode($wrong));
        $this->assertSame(1500, $wrong['data']['skipped']);
        $this->assertCount(1000, $wrong['data']['lines']);
        $this->assertSame(501, $wrong['data']['lines_unlisted'], 'the header and 1,500 skipped lines, 1,000 listed');
        $this->assertSame([['reason' => 'not a subid (a click id is a whole number)', 'lines' => 1500]], $wrong['data']['skipped_reasons']);
        $this->assertSame([0, '0.00000', []], [$wrong['data']['clicks'], $wrong['data']['total'], $wrong['data']['totals']]);

        $refusals = [
            [['csv' => ''], 'csv', 'is required'],
            [['csv' => "a,b\n1,2\n"], 'subid_column', 'no header names the subid column'],
            [['csv' => "subid,payout\n1,2\n", 'subid_column' => 'Click'], 'subid_column', 'no header is named "Click"'],
            [['csv' => "subid,payout\n1,2\n", 'amount_column' => 9], 'amount_column', 'index 9 is not a column'],
            [['csv' => "subid,payout\n1,2\n", 'subid_column' => 0, 'amount_column' => 0], 'amount_column', 'is the subid column too'],
            [['csv' => "subid,payout\n1,2\n", 'columns' => [0, 1]], 'columns', 'is not a field of a revenue upload'],
        ];
        foreach ($refusals as [$body, $field, $sentence]) {
            [$status, $answer] = $this->post('/conversions/uploads?dry_run=1', $body);
            $this->assertSame(422, $status, json_encode($body) . ': ' . json_encode($answer));
            $this->assertStringContainsString($sentence, (string) ($answer['field_errors'][$field] ?? ''), json_encode($answer));
        }
    }

    // ─── Who may ────────────────────────────────────────────────────────

    public function testARoleWithoutTheUpdateSectionIsRefusedEveryWriteAndEveryPreview(): void
    {
        $bodies = [
            '/clicks/cpc' => $this->today() + ['cpc' => '0.1'],
            '/conversions/subids' => ['subids' => ['1']],
            '/conversions/subids/delete' => ['subids' => ['1']],
            '/conversions/subids/reset' => ['aff_network_id' => 1],
            '/conversions/uploads' => ['csv' => "subid,payout\n1,2\n"],
        ];
        foreach ($bodies as $path => $body) {
            foreach (['?dry_run=1', ''] as $query) {
                [$status, $answer] = self::call(self::$roleKeys['viewer'], 'POST', $path . $query, $body);
                $this->assertSame(403, $status, "Campaign viewer: POST $path$query " . json_encode($answer));
                $this->assertStringContainsString("'access_to_update_section' permission", (string) ($answer['message'] ?? ''), "POST $path$query");
            }
        }

        // A Campaign manager has the Update section but not deleting subids
        // one by one, as the Update menu shows it.
        foreach (['?dry_run=1', ''] as $query) {
            [$status, $answer] = self::call(self::$roleKeys['manager'], 'POST', '/conversions/subids/delete' . $query, ['subids' => ['1']]);
            $this->assertSame(403, $status, 'Campaign manager: delete subids' . $query . ' ' . json_encode($answer));
            $this->assertStringContainsString("'delete_individual_subids' permission", (string) ($answer['message'] ?? ''));
        }
        [$status, $answer] = self::call(self::$roleKeys['manager'], 'POST', '/conversions/subids?dry_run=1', ['subids' => ['1']]);
        $this->assertSame(200, $status, 'Campaign manager may mark subids: ' . json_encode($answer));
        $this->assertSame(1, $answer['data']['not_found'], 'in its own account, where click 1 is not');
    }

    public function testAReadScopedKeyCannotWrite(): void
    {
        [$status, $body] = self::call(self::$key, 'GET', '/users');
        $owner = (int) ($body['data'][0]['user_id'] ?? 0);
        $this->assertGreaterThan(0, $owner, json_encode($body));
        [$status, $body] = self::call(self::$key, 'POST', '/users/' . $owner . '/api-keys', ['scope' => 'read']);
        $this->assertSame(201, $status, json_encode($body));
        $readKey = (string) $body['data']['api_key'];
        try {
            [$status, $answer] = self::call($readKey, 'POST', '/conversions/subids?dry_run=1', ['subids' => ['1']]);
            $this->assertSame(403, $status, json_encode($answer));
            $this->assertStringContainsString("requires 'conversions:write'", (string) $answer['message']);
            [$status, $answer] = self::call($readKey, 'POST', '/clicks/cpc?dry_run=1', $this->today() + ['cpc' => '0.1']);
            $this->assertSame(403, $status, json_encode($answer));
            $this->assertStringContainsString("requires 'clicks:write'", (string) $answer['message']);
        } finally {
            self::call(self::$key, 'DELETE', '/users/' . $owner . '/api-keys/' . $readKey);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /** @return array{from: string, to: string} today, in the account's time zone */
    private function today(): array
    {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone(self::$timezone)))->format('Y-m-d');
        return ['from' => $day, 'to' => $day];
    }

    /**
     * Record $count clicks on the campaign's tracker through dl.php, and
     * return their ids.
     *
     * @return list<int>
     */
    private function clicks(string $campaign, int $count): array
    {
        $before = $this->campaignClicks($campaign);
        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init(self::$base . '/tracking202/redirect/dl.php?t202id=' . rawurlencode(self::$trackers[$campaign]) . '&t202kw=' . self::$run . $i);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36']);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->assertSame(302, $status, 'dl.php redirects the click');
        }
        $new = array_values(array_diff($this->campaignClicks($campaign), $before));
        sort($new);
        $this->assertCount($count, $new, 'each click was recorded');

        return $new;
    }

    /** @return list<int> the campaign's click ids */
    private function campaignClicks(string $campaign): array
    {
        [$status, $body] = self::call(self::$key, 'GET', '/clicks?limit=500&aff_campaign_id=' . self::$ids[$campaign]);
        $this->assertSame(200, $status, json_encode($body));
        $ids = array_map('intval', array_column($body['data'], 'click_id'));
        sort($ids);
        return $ids;
    }

    /** @return array<string, mixed> */
    private function click(int $id): array
    {
        [$status, $body] = self::call(self::$key, 'GET', '/clicks/' . $id);
        $this->assertSame(200, $status, json_encode($body));
        return $body['data'];
    }

    /** @return list<array<string, mixed>> */
    private function conversionsOf(int $clickId): array
    {
        [$status, $body] = self::call(self::$key, 'GET', '/clicks/' . $clickId . '/conversions');
        $this->assertSame(200, $status, json_encode($body));
        return $body['data'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $path, array $body): array
    {
        return self::call(self::$key, 'POST', $path, $body);
    }

    /** @param array<string, mixed> $body */
    private static function create(string $resource, array $body, string $idField): int
    {
        [$status, $answer] = self::call(self::$key, 'POST', '/' . $resource, $body);
        $id = (int) ($answer['data'][$idField] ?? 0);
        if ($status !== 201 || $id <= 0) {
            throw new \RuntimeException("POST /$resource answered $status: " . json_encode($answer));
        }
        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function call(string $key, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, []];
        }
        $decoded = $response === '' ? [] : json_decode($response, true);

        return [$status, is_array($decoded) ? $decoded : ['raw' => $response]];
    }
}

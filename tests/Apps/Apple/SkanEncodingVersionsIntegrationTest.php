<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Apps\Apple\SkanEncodingTimeline;
use Api\V3\Controllers\AppPostbacksController;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Controllers\GoalsController;
use PHPUnit\Framework\TestCase;

/**
 * Encoding versions against a real database (plan §5.5): every edit and
 * every delete of an encoding keeps the meaning it replaced, through each
 * path that can make one (the encodings API and deleting a registration),
 * and the report decodes a postback under every meaning inside the 35-day
 * horizon — the SQL grouping by INTERVAL() included, which no mock runs.
 *
 * @group integration
 */
final class SkanEncodingVersionsIntegrationTest extends TestCase
{
    use \Tests\Goals\GoalDatabase;

    private const H = SkanEncodingTimeline::HORIZON_SECONDS;

    private function registration(int $id): void
    {
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=$id, user_id=1, platform='ios', app_key='99$id',
            app_name='App $id', app_token='" . str_repeat((string) $id, 64) . "', created_at=1, updated_at=1");
    }

    private function goal(string $event, string $amount): int
    {
        return (int) (new GoalsController(self::$db, 1))->create([
            'scope' => 'registration', 'scope_id' => 3,
            'definition' => ['name' => $event, 'trigger' => ['event' => $event], 'value' => ['type' => 'fixed', 'amount' => $amount]],
        ])['data']['goal_id'];
    }

    private function postback(int $n, int $receivedAt, int $fine): void
    {
        self::fixture("INSERT INTO 202_app_postbacks
            (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, conversion_value,
             postback_sequence_index, conversion_type, redownload, did_win, attribution_signature, signature_state, trusted,
             dedupe_hash, raw_payload, remote_ip, created_at)
            VALUES (1, 3, $receivedAt, 'skadnetwork', '4.0', 'it.skadnetwork', 'tx-$n', 993, $fine,
             0, 'download', 0, 1, 'sig', 'valid', 1, SHA1('it-$n'), '{}', '198.51.100.1', $receivedAt)");
    }

    /** @return list<array<string, mixed>> */
    private static function history(): array
    {
        return self::$db->query('SELECT encoding_id, goal_id, fine_value, revenue_override, effective_at, retired_at FROM 202_app_skan_encoding_history ORDER BY history_id')
            ->fetch_all(MYSQLI_ASSOC);
    }

    public function testEveryEditAndDeleteKeepsTheMeaningItReplaced(): void
    {
        $this->registration(3);
        $trial = $this->goal('trial', '1.00');
        $purchase = $this->goal('purchase', '5.00');
        $encodings = new AppSkanEncodingsController(self::$db, 1);

        $before = time();
        $created = $encodings->create(['registration_id' => 3, 'fine_value' => 10, 'goal_id' => $trial])['data'];
        self::assertGreaterThanOrEqual($before, (int) $created['effective_at']);
        self::assertSame([], self::history(), 'a create replaces nothing');

        $id = (int) $created['encoding_id'];
        $updated = $encodings->update($id, ['goal_id' => $purchase, 'revenue_override' => '7.5'])['data'];
        $history = self::history();
        self::assertCount(1, $history);
        self::assertSame([$id, $trial, 10, null], [(int) $history[0]['encoding_id'], (int) $history[0]['goal_id'], (int) $history[0]['fine_value'], $history[0]['revenue_override']]);
        self::assertSame((int) $created['effective_at'], (int) $history[0]['effective_at']);
        self::assertSame((int) $updated['effective_at'], (int) $history[0]['retired_at'], 'the old meaning ends where the new one begins');

        // effective_at is the server's to set.
        $errors = [];
        try {
            $encodings->update($id, ['effective_at' => 1]);
        } catch (\Api\V3\Exception\ValidationException $e) {
            $errors = $e->getFieldErrors();
        }
        self::assertArrayHasKey('effective_at', $errors);

        $encodings->delete($id);
        self::assertCount(2, self::history(), 'a delete keeps the last meaning');
        self::assertSame($purchase, (int) self::history()[1]['goal_id']);
        self::assertSame('7.50000', self::history()[1]['revenue_override']);

        // Deleting the registration removes its encodings — and keeps what
        // they meant.
        $encodings->create(['registration_id' => 3, 'fine_value' => 11, 'goal_id' => $trial]);
        (new AppRegistrationsController(self::$db, 1))->delete(3);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_app_skan_encodings')->fetch_assoc()['n']);
        self::assertSame([11], array_map('intval', array_column(array_slice(self::history(), 2), 'fine_value')));
    }

    public function testTheReportIsAmbiguousInsideTheHorizonAfterAnEditAndExactAfterIt(): void
    {
        $this->registration(3);
        $trial = $this->goal('trial', '1.00');
        $purchase = $this->goal('purchase', '5.00');
        $encodings = new AppSkanEncodingsController(self::$db, 1);
        $id = (int) $encodings->create(['registration_id' => 3, 'fine_value' => 10, 'goal_id' => $trial])['data']['encoding_id'];
        // The encoding has meant goal 'trial' for a long time: move its
        // start back (the controller stamps now), so the timeline has a
        // before, a during and an after to decode.
        $edit = time();
        self::fixture('UPDATE 202_app_skan_encodings SET effective_at = ' . ($edit - 400 * 86400));
        $encodings->update($id, ['goal_id' => $purchase]);
        self::fixture("UPDATE 202_app_skan_encoding_history SET retired_at = $edit");
        self::fixture("UPDATE 202_app_skan_encodings SET effective_at = $edit");

        $this->postback(1, $edit - 3 * 86400, 10);            // before: trial
        $this->postback(2, $edit + 2 * 86400, 10);            // inside the horizon: ambiguous
        $this->postback(3, $edit + self::H - 1, 10);          // still inside
        $this->postback(4, $edit + self::H, 10);              // after: purchase
        $this->postback(5, $edit + self::H + 86400, 10);      // after: purchase
        $this->postback(6, $edit + 2 * 86400, 11);            // never meant anything

        $report = (new AppPostbacksController(self::$db, 1))->report(['group_by' => 'registration', 'time_from' => 0, 'time_to' => $edit + 400 * 86400]);
        $group = $report['data']['groups'][0];
        self::assertSame(6, $group['measurable']);
        self::assertSame(3, $group['decoded']);
        self::assertSame(2, $group['ambiguous_encoding']);
        self::assertSame(1, $group['undecoded']);
        $events = (array) $group['events'];
        self::assertSame(['count' => 1, 'revenue' => 1.0], $events['trial']);
        self::assertSame(['count' => 2, 'revenue' => 10.0], $events['purchase']);
        self::assertStringContainsString('ambiguous_encoding', $report['meta']['notes']);
    }
}

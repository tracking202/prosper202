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
 * and the report decodes a postback under every meaning inside the postback
 * horizon — the SQL grouping by INTERVAL() included, which no mock runs —
 * by the app the postback names, so a deleted registration's meanings keep
 * reaching its postbacks, before and after the app is registered again.
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

    private function goal(string $event, string $amount, string $scope = 'registration', int $scopeId = 3): int
    {
        return (int) (new GoalsController(self::$db, 1))->create([
            'scope' => $scope, 'scope_id' => $scopeId,
            'definition' => ['name' => $event, 'trigger' => ['event' => $event], 'value' => ['type' => 'fixed', 'amount' => $amount]],
        ])['data']['goal_id'];
    }

    private function postback(int $n, int $receivedAt, int $fine, int $userId = 1, string $registrationId = '3'): void
    {
        self::fixture("INSERT INTO 202_app_postbacks
            (user_id, registration_id, received_at, protocol, version, ad_network_id, transaction_id, app_id, conversion_value,
             postback_sequence_index, conversion_type, redownload, did_win, attribution_signature, signature_state, trusted,
             dedupe_hash, raw_payload, remote_ip, created_at)
            VALUES ($userId, $registrationId, $receivedAt, 'skadnetwork', '4.0', 'it.skadnetwork', 'tx-$n', 993, $fine,
             0, 'download', 0, 1, 'sig', 'valid', 1, SHA1('it-$n'), '{}', '198.51.100.1', $receivedAt)");
    }

    /** @return list<array<string, mixed>> */
    private static function history(): array
    {
        return self::$db->query('SELECT encoding_id, registration_id, app_id, goal_id, fine_value, revenue_override, effective_at, retired_at FROM 202_app_skan_encoding_history ORDER BY history_id')
            ->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * The report's decode columns, summed over its groups, with the events.
     *
     * @return array{decoded: int, ambiguous_encoding: int, undecoded: int, events: array<string, int>}
     */
    private static function decodes(int $to): array
    {
        $report = (new AppPostbacksController(self::$db, 1))->report(['group_by' => 'registration', 'time_from' => 0, 'time_to' => $to]);
        $sum = ['decoded' => 0, 'ambiguous_encoding' => 0, 'undecoded' => 0, 'events' => []];
        foreach ($report['data']['groups'] as $group) {
            foreach (['decoded', 'ambiguous_encoding', 'undecoded'] as $k) {
                $sum[$k] += $group[$k];
            }
            foreach ((array) $group['events'] as $name => $event) {
                $sum['events'][$name] = ($sum['events'][$name] ?? 0) + $event['count'];
            }
        }
        ksort($sum['events']);

        return $sum;
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

        // Every row keeps the app its registration was for.
        self::assertSame(['993', '993', '993'], array_column(self::history(), 'app_id'));
    }

    public function testAnAccountWideMeaningIsKeptAsAppZero(): void
    {
        $trial = $this->goal('trial', '1.00', 'account', 0);
        $encodings = new AppSkanEncodingsController(self::$db, 1);
        $id = (int) $encodings->create(['registration_id' => 0, 'fine_value' => 10, 'goal_id' => $trial])['data']['encoding_id'];
        $encodings->delete($id);
        self::assertSame([['0', '0']], array_map(static fn (array $r): array => [$r['registration_id'], $r['app_id']], self::history()));
    }

    public function testAMeaningWhoseRegistrationIsNotAnIosAppKeepsNoApp(): void
    {
        // Damage (an encoding whose registration is gone, or is not an iOS
        // app): the copy records no app rather than 0, which would read as
        // account-wide.
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=8, user_id=1, platform='android', app_key='com.example.app',
            app_name='Droid', app_token='" . str_repeat('8', 64) . "', created_at=1, updated_at=1");
        self::fixture("INSERT INTO 202_app_skan_encodings SET encoding_id=70, user_id=1, registration_id=8, fine_value=10, goal_id=1, effective_at=1");
        self::fixture("INSERT INTO 202_app_skan_encodings SET encoding_id=71, user_id=1, registration_id=77, fine_value=10, goal_id=1, effective_at=1");
        $history = new \Api\V3\Apps\Apple\SkanEncodingHistory(self::$db);
        $history->retireEncoding(1, 70, 5);
        $history->retireRegistration(1, 77, 5);
        self::assertSame([['8', null], ['77', null]], array_map(static fn (array $r): array => [$r['registration_id'], $r['app_id']], self::history()));
    }

    /**
     * Codex P2 on PR 8: deleting a registration unlinks its postbacks
     * (registration_id NULL) and keeps its encodings' meanings under its
     * now-gone registration id; registering the app again claims the
     * postbacks under a NEW id. Decoded by registration id, neither could
     * reach the kept meanings, and both fell through to the account-wide
     * set — here, silently crediting a trial as a purchase.
     */
    public function testADeletedRegistrationsMeaningsStillDecodeItsPostbacksAfterTheAppIsRegisteredAgain(): void
    {
        $this->registration(3); // app 993
        $trial = $this->goal('trial', '1.00');
        $purchase = $this->goal('purchase', '5.00', 'account', 0);
        $encodings = new AppSkanEncodingsController(self::$db, 1);
        $encodings->create(['registration_id' => 3, 'fine_value' => 10, 'goal_id' => $trial]);
        $encodings->create(['registration_id' => 0, 'fine_value' => 10, 'goal_id' => $purchase]);
        $now = time();
        self::fixture('UPDATE 202_app_skan_encodings SET effective_at = ' . ($now - 100 * 86400));

        $this->postback(1, $now - 10 * 86400, 10);   // claimed by registration 3
        $this->postback(2, $now - 5 * 86400, 10);
        self::assertSame(['decoded' => 2, 'ambiguous_encoding' => 0, 'undecoded' => 0, 'events' => ['trial' => 2]], self::decodes($now + 400 * 86400));

        (new AppRegistrationsController(self::$db, 1))->delete(3);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_app_postbacks WHERE registration_id IS NOT NULL')->fetch_assoc()['n'], 'the delete unlinked them');
        self::assertSame([['3', '993']], array_map(static fn (array $r): array => [$r['registration_id'], $r['app_id']], self::history()));
        // While the app is unregistered: its own postbacks still decode
        // under what its encoding meant.
        self::assertSame(['decoded' => 2, 'ambiguous_encoding' => 0, 'undecoded' => 0, 'events' => ['trial' => 2]], self::decodes($now + 400 * 86400));

        // A device holding the old document sends one more after the delete
        // (stored unclaimed), and one arrives far later.
        $this->postback(3, $now + 10 * 86400, 10, 0, 'NULL');
        $this->postback(4, $now + 100 * 86400, 10, 0, 'NULL');

        $again = (int) (new AppRegistrationsController(self::$db, 1))->create(['app_key' => '993', 'app_name' => 'App 3 again'])['data']['registration_id'];
        self::assertNotSame(3, $again);
        self::assertSame('4', self::$db->query("SELECT COUNT(*) AS n FROM 202_app_postbacks WHERE registration_id = $again")->fetch_assoc()['n'], 'the new registration claimed all four');

        // The two from before the delete: still trial. The one 10 days after
        // it: the old document's trial or the account-wide purchase — the
        // report cannot know, so ambiguous. The one 100 days after: only
        // the account-wide meaning is left.
        self::assertSame(
            ['decoded' => 3, 'ambiguous_encoding' => 1, 'undecoded' => 0, 'events' => ['purchase' => 1, 'trial' => 2]],
            self::decodes($now + 400 * 86400)
        );
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

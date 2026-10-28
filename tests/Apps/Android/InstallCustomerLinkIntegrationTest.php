<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallEventsIntake;
use Api\V3\Apps\Android\PendingClickSettler;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Identity\CustomerId;
use Prosper202\Identity\IdentityKeys;

/**
 * The signed customer id an Android build reports (plan §4.3; the SDK's
 * setCustomerId), against a real server: it links the install's *proved*
 * click to the customer's identity after the install or events commit —
 * and nothing else. A wrong signature, an install with no proved click and
 * a campaign with identity capture off link nothing, and each is named in
 * the answer; a replay links again without a second row.
 *
 * @group integration
 */
final class InstallCustomerLinkIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private androidSetUp;
    }

    private const U1 = '00000000-0000-4000-8000-0000000000c1';
    private const U2 = '00000000-0000-4000-8000-0000000000c2';

    protected function setUp(): void
    {
        $this->androidSetUp();
        $identity = ['202_identity_keys', '202_identity_visitors', '202_identity_signals', '202_identity_observations',
            '202_identity_merges', '202_clicks_visitor'];
        foreach ($identity as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
    }

    /** @return array{id: string, type: string, signature: string} */
    private function customer(string $id = 'u-829', string $type = 'custom', ?string $signature = null): array
    {
        $key = (new IdentityKeys(new Connection(self::$db)))->forUser(1)['link'];

        return ['id' => $id, 'type' => $type, 'signature' => $signature ?? CustomerId::sign($key, $type . ':' . $id)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function eventsBody(string $uuid, array $body): array
    {
        $this->clock += 5;

        $intake = new InstallEventsIntake(self::$db, fn (): int => $this->clock);

        return $intake->receive(self::TOKEN, $uuid, (string) json_encode($body));
    }

    private static function visitorOf(int $clickId): ?int
    {
        $row = self::$db->query("SELECT visitor_key FROM 202_clicks_visitor WHERE click_id = $clickId")->fetch_assoc();

        return $row === null ? null : (int) $row['visitor_key'];
    }

    public function testAnAttributedInstallLinksItsClickToTheSignedCustomer(): void
    {
        $this->click(100);
        $body = self::body(self::U1, 'p202=' . self::tokenFor(100), ['customer' => $this->customer()]);
        $r = $this->install($body);
        self::assertSame(200, $r['status'], (string) json_encode($r));
        self::assertSame('attributed', $r['body']['data']['match']);
        self::assertSame('linked', $r['body']['data']['customer']);
        $visitor = self::visitorOf(100);
        self::assertNotNull($visitor);
        self::assertSame(1, self::rows('202_identity_signals', "signal_type = 'cust'"));

        // The same person on the web, on another device: a click linked
        // by the operator's own API joins the install's click.
        $this->click(101);
        $conn = new Connection(self::$db);
        $web = ClickIdentity::trustedCustomer('u-829', 'custom');
        self::assertSame($visitor, $web->attach($conn, 1, 101, self::CLICK_TIME + 10));

        // A replay links again, harmlessly.
        $replay = $this->install($body);
        self::assertTrue($replay['body']['data']['duplicate']);
        self::assertSame('linked', $replay['body']['data']['customer']);
        self::assertSame(1, self::rows('202_clicks_visitor', 'click_id = 100'));
        self::assertSame($visitor, self::visitorOf(100));
    }

    public function testAnUnsignedClaimNoClickAndIdentityOffLinkNothing(): void
    {
        $this->click(100);
        $forged = $this->customer(signature: str_repeat('ab', 32));
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['customer' => $forged]));
        self::assertSame(['attributed', 'unverified'], [$r['body']['data']['match'], $r['body']['data']['customer']]);
        self::assertNull(self::visitorOf(100));
        self::assertSame(0, self::rows('202_identity_signals'));
        $raw = (string) self::installRow(self::U1)['raw_payload'];
        self::assertStringContainsString('"customer":', $raw, 'the claim is kept with the body');

        $organic = $this->install(self::body(self::U2, '', ['customer' => $this->customer()]));
        $data = $organic['body']['data'];
        self::assertSame(['organic', 'no_click'], [$data['match'], $data['customer']]);

        $this->campaign(31, 5);
        self::$db->query('UPDATE 202_aff_campaigns SET identity_signals = 0 WHERE aff_campaign_id = 31');
        $this->click(102, 31);
        $claim = ['customer' => $this->customer()];
        $body = self::body('00000000-0000-4000-8000-0000000000c3', 'p202=' . self::tokenFor(102), $claim);
        $data = $this->install($body)['body']['data'];
        self::assertSame(['attributed', 'not_linked'], [$data['match'], $data['customer']]);
        self::assertNull(self::visitorOf(102));

        // A test install the registration does not accept is attributed to
        // its click but unvouched: it proved nothing a customer may join.
        $this->click(104);
        $body = self::body('00000000-0000-4000-8000-0000000000c5', 'p202=' . self::tokenFor(104), [
            'test' => true,
            'customer' => $this->customer(),
        ]);
        $data = $this->install($body)['body']['data'];
        self::assertSame(['attributed', null, 'no_click'], [$data['match'], $data['trusted'], $data['customer']]);
        self::assertNull(self::visitorOf(104));

        $none = $this->install(self::body('00000000-0000-4000-8000-0000000000c4', 'p202=' . self::tokenFor(103)));
        self::assertArrayNotHasKey('customer', $none['body']['data'], 'no claim, no answer about one');
    }

    public function testTheEventsRouteCarriesTheCustomerWithEventsOrAlone(): void
    {
        $this->click(100);
        $installed = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame('attributed', $installed['body']['data']['match']);
        self::assertNull(self::visitorOf(100));

        $alone = $this->eventsBody(self::U1, ['customer' => $this->customer()]);
        self::assertSame(200, $alone['status'], (string) json_encode($alone));
        $want = ['install_uuid' => self::U1, 'accepted' => [], 'duplicates' => [], 'customer' => 'linked'];
        self::assertSame($want, $alone['body']['data']);
        self::assertNotNull(self::visitorOf(100));
        self::assertSame(0, self::rows('202_goal_events', "event_id <> '@install'"), 'a customer alone is no event');

        $with = $this->eventsBody(self::U1, [
            'events' => [['event_id' => 'e-1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]],
            'customer' => $this->customer(),
        ]);
        self::assertSame(['e-1'], $with['body']['data']['accepted']);
        self::assertSame('linked', $with['body']['data']['customer']);
        self::assertSame(1, self::rows('202_clicks_visitor', 'click_id = 100'));

        $bad = $this->eventsBody(self::U1, ['customer' => [
            'id' => 'u-829',
            'type' => 'custom',
            'signature' => 'nope',
        ]]);
        self::assertSame(400, $bad['status']);
        self::assertSame(['customer.signature'], array_keys($bad['body']['field_errors']));
    }

    public function testAPendingInstallsCustomerLinksWhenItSettles(): void
    {
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500), ['customer' => $this->customer()]));
        self::assertSame(['pending_click', 'no_click'], [$r['body']['data']['match'], $r['body']['data']['customer']]);
        $this->click(500);
        $settled = (new PendingClickSettler(self::$db, fn (): int => $this->clock))->run();
        self::assertSame(['attributed' => 1], $settled['settled']);
        self::assertNotNull(self::visitorOf(500), 'linked once the click was proved');
    }
}

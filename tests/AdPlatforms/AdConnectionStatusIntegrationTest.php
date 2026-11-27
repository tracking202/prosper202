<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionStatus;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\Database\Connection;
use Prosper202\Spend\OpenAi\AdConnectionSpendConnections;
use Tests\Apps\Android\AndroidDatabase;

/**
 * A connection's stored status is an AdConnectionStatus, moved only by
 * AdConnectionStore: a check the platform answered sets it, one it did not
 * answer leaves it, and a call it answered outside a check undoes a refusal
 * and nothing else.
 *
 * @group integration
 */
final class AdConnectionStatusIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const ACCOUNT = ['ref' => 'adacct_123', 'name' => 'Northwind', 'currency' => 'USD', 'timezone' => 'America/New_York'];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        self::$db->query('TRUNCATE TABLE 202_ad_connections');
        self::fixture("DELETE FROM 202_deployment_secrets WHERE secret_name = 'ad_connection_credential'");
    }

    private function store(): AdConnectionStore
    {
        return new AdConnectionStore(new Connection(self::$db));
    }

    private function connection(): int
    {
        return (int) $this->store()->create(1, 'openai_ads', null, self::ACCOUNT, ['advertiser_api_key' => 'sk-ads-live-Z9q7Xc2Vb8Nm4Lk1'], 100)['connection_id'];
    }

    /** @return array{0: string, 1: string|null, 2: int} status, last_error, checked_at as stored */
    private static function stored(int $id): array
    {
        $row = self::$db->query('SELECT status, last_error, checked_at FROM 202_ad_connections WHERE connection_id = ' . $id)->fetch_assoc();

        return [(string) $row['status'], $row['last_error'], (int) $row['checked_at']];
    }

    public function testAConnectionIsMadeConnectedAndACheckMovesItBothWays(): void
    {
        $id = $this->connection();
        self::assertSame(['connected', null, 100], self::stored($id));

        self::assertTrue($this->store()->recordCheck(1, $id, null, AdConnectionStatus::KeyRefused, 'refused', 200));
        self::assertSame(['key_refused', 'refused', 200], self::stored($id));
        self::assertTrue($this->store()->recordCheck(1, $id, null, AdConnectionStatus::KeyRefused, 'refused again', 300), 'found as the last check found it');
        self::assertSame(['key_refused', 'refused again', 300], self::stored($id));

        self::assertTrue($this->store()->recordCheck(1, $id, ['name' => 'Northwind EU'] + self::ACCOUNT, AdConnectionStatus::Connected, null, 400));
        self::assertSame(['connected', null, 400], self::stored($id));
        self::assertSame('Northwind EU', $this->store()->get(1, $id)['account']['name']);
        self::assertFalse($this->store()->recordCheck(2, $id, null, AdConnectionStatus::KeyRefused, 'not yours', 500));
        self::assertSame(['connected', null, 400], self::stored($id));
    }

    public function testAStatusThatIsNotOneIsNotWritten(): void
    {
        $id = $this->connection();

        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line the refusal under test */
        $this->store()->recordCheck(1, $id, null, 'paused', null, 200);
    }

    public function testACheckThePlatformDidNotAnswerLeavesTheStatus(): void
    {
        $id = $this->connection();
        $this->store()->recordCheck(1, $id, null, AdConnectionStatus::KeyRefused, 'refused', 200);

        self::assertTrue($this->store()->recordUnansweredCheck(1, $id, 'OpenAI answered 503', 300));

        self::assertSame(['key_refused', 'OpenAI answered 503', 300], self::stored($id));
        self::assertFalse($this->store()->recordUnansweredCheck(2, $id, 'not yours', 400));
    }

    public function testAnAnsweredCallUndoesARefusalAndNothingElse(): void
    {
        $id = $this->connection();
        $runs = new AdConnectionSpendConnections($this->store());
        $this->store()->recordUnansweredCheck(1, $id, 'OpenAI answered 503', 150);

        $runs->keyAccepted(1, $id, 200);
        self::assertSame(['connected', 'OpenAI answered 503', 150], self::stored($id), 'a connected connection keeps its last check');

        $runs->keyRefused(1, $id, 'refused in a run', 300);
        self::assertSame(['key_refused', 'refused in a run', 300], self::stored($id));
        $runs->keyAccepted(2, $id, 350);
        self::assertSame('key_refused', self::stored($id)[0], 'not another account\'s');
        $runs->keyAccepted(1, $id, 400);
        self::assertSame(['connected', null, 400], self::stored($id));
    }

    public function testARowWhoseStatusIsNotOneIsShownAsStoredAndNotMoved(): void
    {
        $id = $this->connection();
        self::fixture("UPDATE 202_ad_connections SET status = 'paused' WHERE connection_id = $id");
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-adconn-log');
        $before = ini_set('error_log', $log);
        try {
            self::assertTrue($this->store()->recordCheck(1, $id, null, AdConnectionStatus::Connected, null, 200), 'the connection is there');
            self::assertFalse($this->store()->keyAnswered(1, $id, 300));
            $shown = $this->store()->get(1, $id)['status'];
        } finally {
            ini_set('error_log', (string) $before);
        }

        self::assertSame(['paused', null, 100], self::stored($id), 'a state no case names is never written over as if it were one');
        self::assertSame('paused', $shown);
        self::assertStringContainsString('connection ' . $id . ' holds a status that is not one (paused), so its check was not recorded', (string) file_get_contents($log));
        unlink($log);
    }

    public function testCredentialsThatDoNotOpenAreAReadingNotAStoredState(): void
    {
        $id = $this->connection();
        self::fixture("UPDATE 202_ad_connections SET credentials = 'no-longer-opens' WHERE connection_id = $id");

        self::assertSame(AdConnectionStatus::UNREADABLE, $this->store()->get(1, $id)['status']);
        self::assertSame('connected', self::stored($id)[0]);
        self::assertNull(AdConnectionStatus::tryFrom(AdConnectionStatus::UNREADABLE));
        self::assertGreaterThan(16, strlen(AdConnectionStatus::UNREADABLE), 'wider than the column: it could not be stored');
    }
}

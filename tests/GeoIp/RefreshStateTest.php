<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\CityDatabase;
use Prosper202\GeoIp\DatabaseStatus;
use Prosper202\GeoIp\RefreshState;

/** When the cron job refreshes, and what the API and Settings are told about the credentials. */
final class RefreshStateTest extends TestCase
{
    private const NOW = 1792800000;
    private const BUILT = 1792500000;
    private const KEY = 'AbCdEf_0123456789abcdefghijklmnopqrstu_mmk';

    public function testNoCredentialsIsNeverDue(): void
    {
        self::assertNull((new RefreshState())->dueAt($this->installed(self::BUILT)));
        self::assertNull((new RefreshState('123456', ''))->dueAt($this->installed(self::BUILT)));
        self::assertNull((new RefreshState('', self::KEY))->dueAt($this->installed(self::BUILT)));
    }

    public function testNeverCheckedIsDueAtOnce(): void
    {
        self::assertSame(0, $this->state()->dueAt($this->installed(self::BUILT)));
    }

    public function testAfterACheckItIsDueAWeekLaterLessAnHour(): void
    {
        $state = $this->state(
            lastCheckedAt: self::NOW - 2 * 86400,
            lastOutcome: RefreshState::CURRENT,
            installedBuild: self::BUILT,
        );

        self::assertSame(self::NOW - 2 * 86400 + 7 * 86400 - 3600, $state->dueAt($this->installed(self::BUILT)));
    }

    public function testAFileThatIsNotTheOneItInstalledIsDueAtOnce(): void
    {
        $state = $this->state(
            lastCheckedAt: self::NOW - 3600,
            lastOutcome: RefreshState::REFRESHED,
            installedBuild: self::BUILT,
        );

        self::assertSame(0, $state->dueAt($this->installed(1530653216)), 'a redeployed image put the shipped one back');
        $unreadable = CityDatabase::fromMetadata('/x.mmdb', 'GeoLite2-City', null, self::NOW);
        self::assertSame(0, $state->dueAt($unreadable));
    }

    public function testAFailureWaitsADayWhateverElseIsTrue(): void
    {
        $failedAt = self::NOW - 3600;
        $never = $this->state(lastAttemptAt: $failedAt, lastOutcome: RefreshState::FAILED);
        self::assertSame($failedAt + 23 * 3600, $never->dueAt($this->installed(self::BUILT)));

        $afterOld = $this->state(
            lastAttemptAt: $failedAt,
            lastOutcome: RefreshState::FAILED,
            lastCheckedAt: self::NOW - 30 * 86400,
            installedBuild: self::BUILT,
        );
        self::assertSame($failedAt + 23 * 3600, $afterOld->dueAt($this->installed(self::BUILT)));
    }

    public function testTheArrayCarriesStoredFlagsAndNeverTheCredentials(): void
    {
        $array = $this->state(
            lastAttemptAt: self::NOW - 60,
            lastOutcome: RefreshState::FAILED,
            lastError: 'MaxMind answered 503',
        )->toArray($this->installed(self::BUILT), self::NOW);

        self::assertTrue($array['configured']);
        self::assertTrue($array['account_id_stored']);
        self::assertTrue($array['license_key_stored']);
        self::assertSame('failed', $array['last_outcome']);
        self::assertSame('MaxMind answered 503', $array['last_error']);
        self::assertSame(self::NOW - 60 + 23 * 3600, $array['next_due_at']);
        $json = (string) json_encode($array);
        self::assertStringNotContainsString(self::KEY, $json);
        self::assertStringNotContainsString('123456', $json);
    }

    public function testDueAlreadyReadsAsNow(): void
    {
        self::assertSame(self::NOW, $this->state()->toArray($this->installed(self::BUILT), self::NOW)['next_due_at']);
        $off = (new RefreshState())->toArray($this->installed(self::BUILT), self::NOW);
        self::assertFalse($off['configured']);
        self::assertNull($off['next_due_at']);
    }

    private function state(
        ?int $lastAttemptAt = null,
        ?string $lastOutcome = null,
        ?string $lastError = null,
        ?int $lastCheckedAt = null,
        ?int $installedBuild = null,
    ): RefreshState {
        return new RefreshState(
            '123456',
            self::KEY,
            $lastAttemptAt,
            $lastOutcome,
            $lastError,
            $lastCheckedAt,
            null,
            null,
            $installedBuild,
        );
    }

    private function installed(int $builtAt): DatabaseStatus
    {
        return CityDatabase::fromMetadata('/x.mmdb', 'GeoLite2-City', $builtAt, self::NOW);
    }
}

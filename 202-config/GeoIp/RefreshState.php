<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * The refresh's row in 202_geoip_refresh: the credentials, which leave this
 * object only as credentials() for the download, and how the last run ended.
 */
final class RefreshState
{
    public const REFRESHED = 'refreshed';
    public const CURRENT = 'current';
    public const FAILED = 'failed';

    /**
     * MaxMind builds GeoLite City twice a week; the refresh asks once a week,
     * an hour early so the hourly tier's drift cannot skip one.
     */
    public const INTERVAL_SECONDS = 7 * 86400 - 3600;

    /**
     * After a failure, one more try a day: GeoLite allows 30 downloads a day,
     * and each run asks for up to two files.
     */
    public const RETRY_SECONDS = 23 * 3600;

    public function __construct(
        private readonly string $accountId = '',
        private readonly string $licenseKey = '',
        public readonly ?int $lastAttemptAt = null,
        public readonly ?string $lastOutcome = null,
        public readonly ?string $lastError = null,
        public readonly ?int $lastCheckedAt = null,
        public readonly ?int $lastRefreshedAt = null,
        public readonly ?string $archiveSha256 = null,
        public readonly ?int $installedBuildEpoch = null,
    ) {
    }

    public function accountIdStored(): bool
    {
        return $this->accountId !== '';
    }

    public function licenseKeyStored(): bool
    {
        return $this->licenseKey !== '';
    }

    public function credentials(): ?Credentials
    {
        return $this->accountIdStored() && $this->licenseKeyStored()
            ? new Credentials($this->accountId, $this->licenseKey)
            : null;
    }

    /**
     * When the cron job next refreshes, or null when no credentials are
     * stored. Due at once when nothing has been checked yet, or when the file
     * is not the one the last refresh installed (unreadable, or put back by a
     * redeploy of a container image); otherwise a week after the last check
     * that got an answer. A failure waits a day before the next try.
     */
    public function dueAt(DatabaseStatus $installed): ?int
    {
        if ($this->credentials() === null) {
            return null;
        }
        $replaced = !$installed->readable()
            || ($this->installedBuildEpoch !== null && $installed->builtAt !== $this->installedBuildEpoch);
        $due = $this->lastCheckedAt === null || $replaced ? 0 : $this->lastCheckedAt + self::INTERVAL_SECONDS;
        if ($this->lastOutcome === self::FAILED && $this->lastAttemptAt !== null) {
            $due = max($due, $this->lastAttemptAt + self::RETRY_SECONDS);
        }

        return $due;
    }

    /**
     * For GET /system/info and Settings: whether each credential is stored,
     * never what it is. next_due_at is when the cron job's hourly tier next
     * refreshes: $now when it is due already.
     *
     * @return array<string, mixed>
     */
    public function toArray(DatabaseStatus $installed, int $now): array
    {
        $due = $this->dueAt($installed);

        return [
            'configured' => $this->credentials() !== null,
            'account_id_stored' => $this->accountIdStored(),
            'license_key_stored' => $this->licenseKeyStored(),
            'schedule' => 'weekly',
            'last_attempt_at' => $this->lastAttemptAt,
            'last_outcome' => $this->lastOutcome,
            'last_error' => $this->lastError,
            'last_checked_at' => $this->lastCheckedAt,
            'last_refreshed_at' => $this->lastRefreshedAt,
            'next_due_at' => $due === null ? null : max($due, $now),
        ];
    }
}

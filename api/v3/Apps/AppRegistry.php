<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Support\MysqliStatements;

/**
 * Reads the registry the way the signal paths need it: by the app a signal
 * names (Apple's postbacks name an App Store id) and by the app token a
 * build presents. Writes go through AppRegistrationsController, so the
 * API, the Setup page and the CLI share one set of rules.
 *
 * Every failure throws: "no such registration" is null, and a query that
 * could not answer is never read as that (CLAUDE.md #11). A caller on a
 * public endpoint turns the throw into a retryable 5xx.
 */
final class AppRegistry
{
    use MysqliStatements;

    private const COLUMNS = 'registration_id, user_id, platform, app_key, accept_test_signals';

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function byIdentity(AppIdentity $identity): ?AppRegistration
    {
        $stmt = $this->prepare(
            'SELECT ' . self::COLUMNS . ' FROM 202_app_registrations WHERE platform = ? AND app_key = ? LIMIT 1'
        );
        $this->bind($stmt, 'ss', $identity->platform, $identity->appKey);
        $this->execute($stmt, 'App registry lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * The registration an app token names, or null for a token nobody holds.
     *
     * An indexed equality lookup rather than a constant-time compare: the
     * token is 256 random bits, so guessing is infeasible regardless of
     * timing, and the comparison happens inside the index walk.
     */
    public function byToken(string $token): ?AppRegistration
    {
        $stmt = $this->prepare(
            'SELECT ' . self::COLUMNS . ' FROM 202_app_registrations WHERE app_token = ? LIMIT 1'
        );
        $this->bind($stmt, 's', $token);
        $this->execute($stmt, 'App token lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): AppRegistration
    {
        return new AppRegistration(
            (int)$row['registration_id'],
            (int)$row['user_id'],
            // A stored key the rules refuse is a corrupt row; the throw
            // surfaces it instead of serving a different app's data.
            AppIdentity::fromKey((string)$row['platform'], (string)$row['app_key']),
            AppPolicy::fromRow($row),
        );
    }
}

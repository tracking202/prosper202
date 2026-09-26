<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Every account has exactly one default model, a real `last_touch` row,
 * from the moment it exists.
 *
 * Credits need a model_id, so an account with no model would have no
 * credits and empty attribution reports until someone created one. Before
 * the rewrite upgraded installs got a default row and fresh installs got
 * none (CLAUDE.md error pattern #5). So one statement seeds it, and every
 * path runs that statement: the fresh installer and user creation through
 * ensureFor(), the 1.9.56 upgrade rung through SEED_ALL_SQL. Both are
 * idempotent — an account that has a default gets nothing — and UNIQUE
 * (user_id, is_default) makes a second default impossible even under a race.
 */
final class DefaultModel
{
    public const NAME = 'Last touch';
    public const SLUG = 'last-touch';

    /** Seeds every account that has no default. What the upgrade rung runs. */
    public const SEED_ALL_SQL = self::SEED_SELECT;

    /**
     * Live accounts still without a default after seeding: must be 0. A
     * deleted account has none by design (UserDataPurge removed its models,
     * and the worker refuses its conversions), so neither query counts it.
     */
    public const MISSING_SQL = "SELECT COUNT(*) AS missing FROM 202_users u
        WHERE u.user_deleted = 0 AND NOT EXISTS (SELECT 1 FROM 202_attribution_models m WHERE m.user_id = u.user_id AND m.is_default = 1)";

    private const SEED_SELECT = "INSERT INTO 202_attribution_models
            (user_id, model_name, model_slug, model_type, weighting_config, lookback_days,
             status, status_reason, is_default, recompute_requested_at, recompute_cursor, created_at, updated_at)
        SELECT u.user_id, '" . self::NAME . "', '" . self::SLUG . "', 'last_touch', '{}', 30,
             'active', NULL, 1, NULL, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
        FROM 202_users u
        WHERE u.user_deleted = 0
          AND NOT EXISTS (SELECT 1 FROM 202_attribution_models d WHERE d.user_id = u.user_id AND d.is_default = 1)
          AND NOT EXISTS (SELECT 1 FROM 202_attribution_models s WHERE s.user_id = u.user_id AND s.model_slug = '" . self::SLUG . "')";

    private function __construct()
    {
    }

    /**
     * Make sure one account has its default model; return its id.
     *
     * Runs inside the caller's transaction when there is one (the installer
     * and user creation run it beside the user row, so an account never
     * commits without its model).
     *
     * @throws \RuntimeException when the account still has no default — the
     *         only way is a non-default model already holding the default's
     *         slug, which the API never leaves behind.
     */
    public static function ensureFor(Connection $conn, int $userId): int
    {
        $existing = self::defaultId($conn, $userId);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $conn->prepareWrite(self::SEED_SELECT . ' AND u.user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        try {
            $conn->executeUpdate($stmt);
        } catch (QueryException $e) {
            // A concurrent seed for the same account won the UNIQUE
            // (user_id, is_default) slot; the re-read below finds its row.
            if (!Connection::isMysqlError($e, 1062, 'Duplicate entry')) {
                throw $e;
            }
        }

        $id = self::defaultId($conn, $userId);
        if ($id === null) {
            throw new \RuntimeException(
                'account ' . $userId . ' has no default attribution model and one could not be created: '
                . 'a model with the slug "' . self::SLUG . '" exists and is not the default. '
                . 'Make that model (or another) the default.'
            );
        }

        return $id;
    }

    private static function defaultId(Connection $conn, int $userId): ?int
    {
        $stmt = $conn->prepareWrite(
            'SELECT model_id FROM 202_attribution_models WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return $row !== null ? (int) $row['model_id'] : null;
    }
}

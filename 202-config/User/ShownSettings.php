<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Database\Connection;
use RuntimeException;
use WeakMap;

/**
 * The version of what a settings form showed, carried by the form and
 * compared with what is stored when it is saved.
 *
 * A settings form posts whole values and rewrites them, so two sessions of
 * one account that each opened the page and then saved would each write what
 * they read, and the later save would drop the other's change. The form
 * carries the version of the values it showed (field()); the handler holds
 * the account's settings lock (hold()), reads the values again and saves only
 * when they are the ones the page showed (isCurrent()).
 *
 * The version is an HMAC under a secret kept in the session, never in the
 * page: a page that leaks gives nothing to test a guessed stored secret
 * against. A new session (a new sign-in) makes every page opened before it
 * stale, which is refused and reloaded like any other.
 */
final class ShownSettings
{
    public const string FIELD = 'p202_shown';

    /** GET_LOCK names are server-wide and at most 64 characters. */
    private const string LOCK_PREFIX = 'p202_settings_';

    /** Seconds a save waits for another save of the same account. */
    public const int LOCK_WAIT = 10;

    /** @var WeakMap<\mysqli, array<string, true>>|null */
    private static ?WeakMap $held = null;

    private function __construct()
    {
    }

    /**
     * The version of $values as form $form shows them. serialize() is used
     * because it is injective over every value a row holds (binary strings,
     * null, ints) and cannot fail as json_encode() can on bytes.
     *
     * @param array<array-key, mixed> $values
     */
    public static function version(string $form, array $values): string
    {
        return hash_hmac('sha256', serialize([$form, $values]), self::secret());
    }

    /**
     * The hidden input carrying the version, named for its form so that two
     * forms on one page never answer for each other.
     *
     * @param array<array-key, mixed> $values
     */
    public static function field(string $form, array $values): string
    {
        return '<input type="hidden" name="' . self::FIELD . '[' . htmlspecialchars($form, ENT_QUOTES, 'UTF-8')
            . ']" value="' . self::version($form, $values) . '">';
    }

    /**
     * True only when the request carries form $form's version and it is the
     * version of $current. A missing, malformed or other version is false:
     * nothing says what the page showed, so the save is not made over it.
     *
     * @param array<array-key, mixed> $current
     * @param array<array-key, mixed> $post
     */
    public static function isCurrent(string $form, array $current, array $post): bool
    {
        $posted = $post[self::FIELD] ?? null;
        if (!is_array($posted) || !is_string($posted[$form] ?? null)) {
            return false;
        }

        return hash_equals(self::version($form, $current), $posted[$form]);
    }

    /**
     * Hold the account's settings lock for the rest of the request, so that
     * the read the save is compared with and the save itself are not split
     * by another save of the same account. Taken once per connection and
     * account; released when the request ends (and by the server when the
     * connection closes).
     *
     * @throws SettingsSaveBusy when another save holds it past LOCK_WAIT
     * @throws RuntimeException when the lock cannot be asked for
     */
    public static function hold(\mysqli $db, int $userId): void
    {
        self::$held ??= new WeakMap();
        $name = self::lockName($db, $userId);
        if (isset(self::$held[$db][$name])) {
            return;
        }
        $conn = new Connection($db);
        $stmt = $conn->prepareWrite('SELECT GET_LOCK(?, ?) AS got');
        $conn->bind($stmt, 'si', [$name, self::LOCK_WAIT]);
        $got = $conn->fetchOne($stmt);
        if ($got === null || $got['got'] === null) {
            throw new RuntimeException('GET_LOCK failed for ' . $name);
        }
        if ((int) $got['got'] !== 1) {
            throw new SettingsSaveBusy('Another save of account ' . $userId . ' held its settings lock for '
                . self::LOCK_WAIT . ' seconds');
        }
        $names = self::$held[$db] ?? [];
        $names[$name] = true;
        self::$held[$db] = $names;
        register_shutdown_function(static function () use ($conn, $name): void {
            try {
                $stmt = $conn->prepareWrite('SELECT RELEASE_LOCK(?) AS released');
                $conn->bind($stmt, 's', [$name]);
                $conn->fetchOne($stmt);
            } catch (\Throwable) {
                // The connection is gone, and the server released the lock with it.
            }
        });
    }

    /**
     * Per database and account: the database's name and the user id are
     * hashed together, so two installs on one server never share a lock.
     */
    public static function lockName(\mysqli $db, int $userId): string
    {
        $conn = new Connection($db);
        $row = $conn->fetchOne($conn->prepareWrite('SELECT DATABASE() AS db'));
        $database = $row['db'] ?? null;
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('A settings save needs a selected database to name its lock.');
        }

        return self::LOCK_PREFIX . sha1(strlen($database) . ':' . $database . ':' . $userId);
    }

    /** The session's secret, made on first use. */
    private static function secret(): string
    {
        $secret = $_SESSION['p202_shown_secret'] ?? null;
        if (is_string($secret) && strlen($secret) === 64) {
            return $secret;
        }
        $secret = bin2hex(random_bytes(32));
        $store = static function () use ($secret): void {
            $_SESSION['p202_shown_secret'] = $secret;
        };
        if (function_exists('withWritableSession')) {
            withWritableSession($store);
        } else {
            $store();
        }

        return $secret;
    }
}

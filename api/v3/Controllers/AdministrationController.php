<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Database\Connection;

/**
 * Account › Settings (202-account/administration.php) and the endpoint URLs
 * of Account › API integrations (202-account/api-integrations.php), for this
 * install, as REST:
 *
 * - GET  /system/info                     what the page's tiles and System and
 *                                         PHP limits panels show
 * - GET  /system/login-log                the sign-in attempts it lists
 * - GET  /system/retention                automatic click-data deletion, and a
 *   PUT  /system/retention                one-off deletion already scheduled
 * - POST /system/retention/delete-before  schedule the one-off deletion
 *                                         (`?dry_run=1` previews it)
 * - GET  /system/isp-lookup               the MaxMind ISP and carrier lookup
 *   PUT  /system/isp-lookup               switch
 * - GET  /system/integrations             the IPN / postback URLs to paste
 *                                         into ClickBank, JVZoo, Zaxaa, Slack
 *                                         and PayKickstart
 *
 * The role permissions are the pages' (access_to_settings, and
 * access_to_api_integrations for the integrations), checked by the routes on
 * top of the /system group's Admin requirement; api/v3/index.php holds that
 * and AdministrationRoutesPermissionTest pins it (CLAUDE.md #5).
 *
 * Which preferences row each setting lives on is the reader's, not the
 * page's. The cron job reads automatic deletion and the one-off marker from
 * user 1's row only (202-cronjobs/index.php, AutoOptimizeDatabase() and
 * ClearOldClicks()), so they are read and written there: a value on any
 * other row is one nothing acts on. ISP lookup is read by the redirects from
 * the row of the tracker's owner, so it is the caller's own, as on the page.
 *
 * Left out, because they call a remote service: AutoCron (the page registers
 * the install with Prosper202's hosted cron service before it stores the
 * switch) and "update available" (the page learns that from a feed on
 * my.tracking202.com). No route here makes a network request.
 */
final class AdministrationController
{
    /**
     * The tables the cron job's deletions remove a click's rows from, by
     * click_id: the list Prosper202\Click\ClickRetention deletes from, which
     * ClearOldClicks() and AutoOptimizeDatabase() in 202-cronjobs/index.php
     * both run, so the preview counts exactly what goes.
     */
    public const CLICK_DATA_TABLES = \Prosper202\Click\ClickRetention::TABLES;

    /** The most days the page's number input accepts (100 years). */
    public const MAX_AUTO_DELETE_DAYS = 36500;

    /** The sign-in attempts the page lists, and the most one request returns. */
    public const LOGIN_LOG_DEFAULT = 50;
    public const LOGIN_LOG_MAX = 500;

    /** The account the installer creates: the cron job reads its row. */
    private const INSTALL_OWNER = 1;

    /** The ISP databases getisp() reads, in the order it tries them. */
    private const ISP_DATABASES = ['GeoIP2-ISP.mmdb', 'GeoIPISP.dat'];

    /**
     * The integrations that receive a notification at a URL of this
     * install, as the Integrations page lists them: key => [name, what the
     * page calls the URL, the script, the preference holding its secret
     * (null: it needs none)].
     */
    private const INTEGRATIONS = [
        'clickbank' => ['ClickBank', 'INS URL', 'tracking202/static/cb202.php', 'cb_key'],
        'jvzoo' => ['JVZoo', 'IPN URL', 'tracking202/static/jvzoo.php', 'jvzoo_ipn_secret_key'],
        'zaxaa' => ['Zaxaa', 'ZPN URL', 'tracking202/static/zpn.php', 'zaxaa_api_signature'],
        'slack' => ['Slack', 'Prosper202 webhook', 'tracking202/static/slack.php', 'user_slack_incoming_webhook'],
        'paykickstart' => ['PayKickstart', 'IPN URL', 'tracking202/static/paykickstart.php', null],
    ];

    private readonly Connection $conn;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($this->db);
    }

    // ─── GET /system/info ───────────────────────────────────────────────

    /**
     * The page's tiles (clicks recorded, database size, cron last ran,
     * DataEngine conversion) and its System and PHP limits panels.
     *
     * @return array{data: array<string, mixed>}
     */
    public function info(?string $memcacheHost = null): array
    {
        if (!defined('PROSPER202_VERSION')) {
            require_once dirname(__DIR__, 3) . '/202-config/version.php';
        }
        $code = (string) PROSPER202_VERSION;

        return $this->guard(function () use ($code, $memcacheHost): array {
            $schemaRow = $this->one('SELECT version FROM 202_version LIMIT 1');
            $schema = $schemaRow === null ? null : (string) $schemaRow['version'];

            $counter = $this->one(
                "SELECT `AUTO_INCREMENT` - 1 AS clicks FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_clicks_counter'"
            );
            $size = $this->one(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS size
                 FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            );
            $cron = $this->one('SELECT last_execution_time FROM 202_cronjob_logs WHERE id = 1 LIMIT 1');
            $engine = $this->one('SELECT COUNT(*) AS total, COALESCE(SUM(processed), 0) AS done FROM 202_dataengine_job');
            $keyword = $this->one('SELECT user_keyword_searched_or_bidded FROM 202_users_pref WHERE user_id = ? LIMIT 1', 'i', [$this->userId]);

            $total = (int) ($engine['total'] ?? 0);
            $done = (int) ($engine['done'] ?? 0);
            $lastRun = $cron === null ? null : (int) $cron['last_execution_time'];

            return ['data' => [
                'version' => $code,
                'schema_version' => $schema,
                // upgrade_needed()'s question: the database is at another
                // version than the code (202-config/upgrade.php runs then).
                'database_upgrade_needed' => $schema !== $code,
                'php_version' => PHP_VERSION,
                'mysql_version' => (string) $this->db->server_info,
                'php_safe_mode' => (bool) ini_get('safe_mode'),
                'memcache' => self::memcache($memcacheHost),
                'php_limits' => [
                    'post_max_size' => (string) ini_get('post_max_size'),
                    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                    'max_input_time' => (string) ini_get('max_input_time'),
                    'max_execution_time' => (string) ini_get('max_execution_time'),
                ],
                'clicks_recorded' => $counter === null || $counter['clicks'] === null ? null : max(0, (int) $counter['clicks']),
                'database_size_bytes' => (int) ($size['size'] ?? 0),
                'cron_last_ran_at' => $lastRun,
                'cron_last_ran_seconds_ago' => $lastRun === null ? null : max(0, time() - $lastRun),
                // One task a minute: what the page's "time left" counts.
                'dataengine' => [
                    'tasks_total' => $total,
                    'tasks_done' => $done,
                    'percent_done' => $total === 0 ? 0.0 : round($done / $total * 100, 2),
                    'minutes_left' => max(0, $total - $done),
                ],
                'keyword_preference' => $keyword === null ? null : (string) $keyword['user_keyword_searched_or_bidded'],
            ]];
        });
    }

    /**
     * Whether a memcache extension is loaded and its server answers, as
     * connect.php decides it for every page (the server is $mchost from
     * 202-config.php, port 11211). For the memcached extension, adding a
     * server contacts nothing, so it is asked for its version instead.
     *
     * @return array{installed: bool, running: bool}
     */
    private static function memcache(?string $host): array
    {
        $host = $host === null || $host === '' ? '127.0.0.1' : $host;
        if (extension_loaded('memcache') && class_exists('Memcache')) {
            $client = new \Memcache();
            $running = @$client->connect($host, 11211, 1);
            if ($running) {
                @$client->close();
            }
            return ['installed' => true, 'running' => (bool) $running];
        }
        if (extension_loaded('memcached') && class_exists('Memcached')) {
            $client = new \Memcached();
            // getVersion() answers 255.255.255 for a server it could not reach.
            $versions = @$client->addServer($host, 11211) ? @$client->getVersion() : false;
            $running = is_array($versions) && array_filter(
                $versions,
                static fn (mixed $v): bool => is_string($v) && $v !== '' && $v !== '255.255.255'
            ) !== [];
            return ['installed' => true, 'running' => $running];
        }
        return ['installed' => false, 'running' => false];
    }

    // ─── GET /system/login-log ──────────────────────────────────────────

    /**
     * Sign-in attempts, newest first: who, when, from which address, and
     * whether it passed. Only those columns: the table also holds the
     * attempt's serialized request and, on old installs, the password typed
     * (202-login.php stores "[filtered]" now; rows from before it did not).
     *
     * @param array<string, mixed> $params limit: 1-500, default 50 (what the page shows)
     * @return array<string, mixed>
     */
    public function loginLog(array $params): array
    {
        $unknown = array_diff(array_map('strval', array_keys($params)), ['limit']);
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter(s): ' . implode(', ', $unknown), array_fill_keys($unknown, 'is not accepted here (accepted: limit)'));
        }
        $limit = self::LOGIN_LOG_DEFAULT;
        if (array_key_exists('limit', $params)) {
            $raw = $params['limit'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > self::LOGIN_LOG_MAX) {
                throw new ValidationException('Invalid limit', ['limit' => 'must be a whole number from 1 to ' . self::LOGIN_LOG_MAX]);
            }
            $limit = (int) $raw;
        }

        $rows = $this->guard(fn (): array => $this->all(
            'SELECT login_id, user_name, ip_address, login_time, login_success
             FROM 202_users_log ORDER BY login_id DESC LIMIT ?',
            'i',
            [$limit]
        ));

        return [
            'data' => array_map(static fn (array $row): array => [
                'login_id' => (int) $row['login_id'],
                'user_name' => (string) $row['user_name'],
                'ip_address' => (string) $row['ip_address'],
                'login_time' => (int) $row['login_time'],
                'login_success' => (int) $row['login_success'] !== 0,
            ], $rows),
            'meta' => ['limit' => $limit],
        ];
    }

    // ─── GET|PUT /system/retention ──────────────────────────────────────

    /**
     * Automatic deletion (click data older than auto_delete_days goes, from
     * the first cron run after midnight; 0 keeps it all) and the one-off
     * deletion scheduled through delete-before, if any: the cron job
     * deletes, in batches, every click whose id is below through_click_id.
     *
     * @return array{data: array<string, mixed>}
     */
    public function retention(): array
    {
        return ['data' => $this->guard(fn (): array => $this->retentionState($this->ownerPrefs()))];
    }

    /**
     * Set automatic deletion, as the page's Save does: a whole number of
     * days from 0 (keep everything) to 36500.
     *
     * @param array<string, mixed> $payload {auto_delete_days}
     * @return array{data: array<string, mixed>}
     */
    public function setRetention(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['auto_delete_days'], 'the retention setting', self::QUERY_NOT_BODY);
        if (!array_key_exists('auto_delete_days', $payload)) {
            throw new ValidationException('auto_delete_days is required', [
                'auto_delete_days' => 'is required: a whole number of days from 0 (keep every click) to ' . self::MAX_AUTO_DELETE_DAYS,
            ]);
        }
        $raw = $payload['auto_delete_days'];
        // Read before any cast (CLAUDE.md #18): a JSON integer, or a string
        // of digits as the form posts it. true, 1.5, "30 days" and null
        // are refused, never read as some other number of days.
        $digits = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : null);
        if ($digits === null || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $digits) !== 1 || (int) $digits > self::MAX_AUTO_DELETE_DAYS) {
            throw new ValidationException('Invalid auto_delete_days', [
                'auto_delete_days' => 'must be a whole number of days from 0 (keep every click) to ' . self::MAX_AUTO_DELETE_DAYS,
            ]);
        }
        $days = (int) $digits;

        return ['data' => $this->guard(function () use ($days): array {
            $before = $this->ownerPrefs();
            $stmt = $this->conn->prepareWrite('UPDATE 202_users_pref SET user_auto_database_optimization_days = ? WHERE user_id = ?');
            $this->conn->bind($stmt, 'ii', [$days, self::INSTALL_OWNER]);
            $this->conn->executeUpdate($stmt);

            return ['previous_auto_delete_days' => (int) ($before['user_auto_database_optimization_days'] ?? 0)]
                + $this->retentionState($this->ownerPrefs());
        })];
    }

    // ─── POST /system/retention/delete-before ───────────────────────────

    /**
     * Schedule the deletion of click data from before a day (the page's
     * Advanced › "Delete click data from before"). The page does not delete:
     * it stores a click id on user 1's preferences, and the cron job deletes
     * every row below it from CLICK_DATA_TABLES, a batch of clicks from every
     * table in one transaction (Prosper202\Click\ClickRetention), for every
     * account on the install. It cannot be undone.
     *
     * The id is the page's: the newest click recorded at or before midnight
     * that begins `before`, in the caller's time zone. A dry run answers it
     * as through_click_id with what lies below it, per table; the write
     * carries it back and stores it only if it is still the id the day
     * names, so it never schedules more than was previewed.
     *
     * @param array<string, mixed> $payload {before: YYYY-MM-DD, through_click_id: the dry run's}
     * @return array{data: array<string, mixed>}
     */
    public function scheduleDeletion(array $payload, bool $dryRun): array
    {
        PayloadKeys::refuseUnknown($payload, ['before', 'through_click_id'], 'a scheduled deletion', self::QUERY_NOT_BODY);
        $timezone = $this->accountZone();
        $before = self::day($payload, $timezone);

        $confirmed = null;
        if (!$dryRun) {
            if (!array_key_exists('through_click_id', $payload)) {
                throw new ValidationException('through_click_id is required', [
                    'through_click_id' => 'is required to schedule the deletion: send the request with ?dry_run=1 first and confirm the through_click_id it answers',
                ]);
            }
            $raw = $payload['through_click_id'];
            if ($raw !== null && !(is_int($raw) && $raw > 0)) {
                throw new ValidationException('Invalid through_click_id', [
                    'through_click_id' => 'must be the through_click_id a dry run answered: a positive whole number, or null when it answered null',
                ]);
            }
            $confirmed = $raw;
        }

        $cutoff = $before->getTimestamp();
        $answer = static fn (array $state, ?int $through, array $rows, bool $scheduled) => [
            'dry_run' => $dryRun,
            'scheduled' => $scheduled,
            'before' => $before->format('Y-m-d'),
            'timezone' => $timezone->getName(),
            'cutoff_time' => $cutoff,
            'through_click_id' => $through,
            'clicks' => $rows['202_clicks'] ?? 0,
            'rows' => (object) $rows,
            'current' => $state['scheduled_deletion'],
        ];

        if ($dryRun) {
            return ['data' => $this->guard(function () use ($cutoff, $answer): array {
                $state = $this->retentionState($this->ownerPrefs());
                $through = $this->marker($cutoff);
                return $answer($state, $through, $through === null ? [] : $this->rowsBelow($through), false);
            })];
        }

        return ['data' => $this->guard(function () use ($cutoff, $answer, $confirmed): array {
            // `current` is what was scheduled before this request, as in
            // the dry run's answer.
            $state = $this->retentionState($this->ownerPrefs());
            $outcome = $this->conn->transaction(function () use ($cutoff, $confirmed): array {
                // The row the cron reads, locked so two schedules cannot
                // interleave their check and their write.
                $stmt = $this->conn->prepareWrite('SELECT user_delete_data_clickid FROM 202_users_pref WHERE user_id = ? LIMIT 1 FOR UPDATE');
                $this->conn->bind($stmt, 'i', [self::INSTALL_OWNER]);
                if ($this->conn->fetchOne($stmt) === null) {
                    throw new DatabaseException('the Super user (user 1) has no 202_users_pref row; the cron job reads its settings there');
                }
                $through = $this->marker($cutoff);
                if ($through !== $confirmed) {
                    return ['conflict' => $through];
                }
                if ($through === null) {
                    return ['through' => null];
                }
                $write = $this->conn->prepareWrite('UPDATE 202_users_pref SET user_delete_data_clickid = ? WHERE user_id = ?');
                $this->conn->bind($write, 'ii', [$through, self::INSTALL_OWNER]);
                $this->conn->executeUpdate($write);
                return ['through' => $through];
            });
            if (array_key_exists('conflict', $outcome)) {
                $now = $outcome['conflict'];
                throw new ConflictException(
                    'through_click_id ' . ($confirmed === null ? 'null' : $confirmed) . ' is not what this day names now ('
                    . ($now === null ? 'no click' : 'click ' . $now) . '): the clicks before it changed after the dry run, or the id '
                    . 'is not the one the dry run answered. Nothing was scheduled. Run the request again with ?dry_run=1 and confirm '
                    . 'the through_click_id it answers.',
                    ['through_click_id' => $confirmed, 'current_through_click_id' => $now]
                );
            }
            $through = $outcome['through'];
            if ($through === null) {
                return $answer($state, null, [], false);
            }
            // The marker is committed: a failure counting the rows must not
            // read as "nothing was scheduled" (CLAUDE.md #13).
            try {
                $rows = $this->rowsBelow($through);
            } catch (\Throwable $e) {
                throw new WriteCommittedException('the scheduled deletion', $e,
                    'The deletion was scheduled (through_click_id ' . $through . '), but its rows could not be counted afterwards. '
                    . 'GET /system/retention shows what is scheduled; sending the same request again schedules nothing more.');
            }

            return $answer($state, $through, $rows, true);
        })];
    }

    /**
     * The newest click at or before the cutoff — the page's query. Null
     * when there is none (nothing to delete).
     */
    private function marker(int $cutoff): ?int
    {
        $row = $this->one('SELECT click_id FROM 202_clicks WHERE click_time <= ? ORDER BY click_id DESC LIMIT 1', 'i', [$cutoff]);

        return $row === null ? null : (int) $row['click_id'];
    }

    /**
     * What the cron job removes below a marker, per table.
     *
     * @return array<string, int>
     */
    private function rowsBelow(int $through): array
    {
        $rows = [];
        foreach (self::CLICK_DATA_TABLES as $table) {
            $row = $this->one('SELECT COUNT(*) AS n FROM `' . $table . '` WHERE click_id < ?', 'i', [$through]);
            $rows[$table] = (int) ($row['n'] ?? 0);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $prefs user 1's row
     * @return array<string, mixed>
     */
    private function retentionState(array $prefs): array
    {
        $marker = $prefs['user_delete_data_clickid'] ?? null;
        $scheduled = null;
        if ($marker !== null && (int) $marker > 0) {
            $through = (int) $marker;
            // p202_admin_erase_date(): the day of the click the marker names
            // (the newest at or below it, which the deletion keeps).
            $at = $this->one('SELECT click_time FROM 202_clicks WHERE click_id <= ? ORDER BY click_id DESC LIMIT 1', 'i', [$through]);
            $remaining = $this->one('SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id < ?', 'i', [$through]);
            $scheduled = [
                'through_click_id' => $through,
                'before' => $at === null ? null : (new \DateTimeImmutable('@' . (int) $at['click_time']))
                    ->setTimezone($this->accountZone())->format('Y-m-d'),
                'clicks_remaining' => (int) ($remaining['n'] ?? 0),
            ];
        }

        return [
            'auto_delete_days' => (int) ($prefs['user_auto_database_optimization_days'] ?? 0),
            'scheduled_deletion' => $scheduled,
        ];
    }

    /** @return array<string, mixed> user 1's preferences row, the one the cron job reads */
    private function ownerPrefs(): array
    {
        $row = $this->one(
            'SELECT user_auto_database_optimization_days, user_delete_data_clickid FROM 202_users_pref WHERE user_id = ? LIMIT 1',
            'i',
            [self::INSTALL_OWNER]
        );
        if ($row === null) {
            throw new DatabaseException('the Super user (user 1) has no 202_users_pref row; the cron job reads its settings there');
        }

        return $row;
    }

    /**
     * `before` as midnight of that day in $timezone: a real calendar day,
     * written YYYY-MM-DD, not after today (the page's date input stops at
     * today).
     *
     * @param array<string, mixed> $payload
     */
    private static function day(array $payload, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!array_key_exists('before', $payload)) {
            throw new ValidationException('before is required', [
                'before' => 'is required: the day, YYYY-MM-DD in the account\'s time zone; click data from before it is deleted',
            ]);
        }
        $raw = $payload['before'];
        $day = is_string($raw) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $raw) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone)
            : false;
        if ($day === false || $day->format('Y-m-d') !== $raw) {
            throw new ValidationException('Invalid before', ['before' => 'must be a calendar day written YYYY-MM-DD']);
        }
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        if ($raw > $today) {
            throw new ValidationException('Invalid before', [
                'before' => 'must not be after today (' . $today . ' in ' . $timezone->getName() . '): every click recorded so far is from before it',
            ]);
        }

        return $day;
    }

    /**
     * The caller's time zone, as the page reads the day after
     * AUTH::set_timezone(). A stored zone PHP does not know is refused,
     * never replaced by UTC: the wrong zone moves the cutoff by hours and
     * deletes clicks nobody chose (UpdateController reads the days of a CPC
     * update the same way).
     */
    private function accountZone(): \DateTimeZone
    {
        $row = $this->guard(fn (): ?array => $this->one('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1', 'i', [$this->userId]));
        if ($row === null) {
            throw new DatabaseException('the authenticated user ' . $this->userId . ' has no 202_users row');
        }
        $name = trim((string) ($row['user_timezone'] ?? ''));
        if ($name === '') {
            $name = 'UTC';
        }
        if (!in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new ConflictException(
                'The account\'s time zone "' . $name . '" is not a zone this server knows, so its days cannot be read. '
                . 'Set user_timezone to an IANA zone such as America/New_York (PUT /users/' . $this->userId . ').'
            );
        }

        return new \DateTimeZone($name);
    }

    // ─── GET|PUT /system/isp-lookup ─────────────────────────────────────

    /**
     * Whether the redirects look up each visitor's ISP and carrier
     * (maxmind_isp on the caller's preferences, which the redirects read for
     * the trackers the caller owns), and whether the database it needs is
     * there.
     *
     * @return array{data: array<string, mixed>}
     */
    public function ispLookup(): array
    {
        return ['data' => $this->guard(fn (): array => $this->ispState())];
    }

    /**
     * Turn ISP lookup on or off, as the page's switch does: on only when
     * an ISP database file is in place.
     *
     * @param array<string, mixed> $payload {enabled: true|false}
     * @return array{data: array<string, mixed>}
     */
    public function setIspLookup(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['enabled'], 'the ISP lookup setting', self::QUERY_NOT_BODY);
        if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
            throw new ValidationException('enabled is required', ['enabled' => 'must be true (look up ISPs) or false']);
        }
        $enabled = $payload['enabled'];
        $database = self::ispDatabase();
        if ($enabled && $database['file'] === null) {
            throw new ValidationException(
                'The ISP database file is not there, so lookup stays off. Upload GeoIP2-ISP.mmdb (or legacy GeoIPISP.dat) to '
                . $database['dir'] . ', then turn it on.',
                ['enabled' => 'needs GeoIP2-ISP.mmdb or GeoIPISP.dat in ' . $database['dir']]
            );
        }

        return ['data' => $this->guard(function () use ($enabled): array {
            $stmt = $this->conn->prepareWrite('UPDATE 202_users_pref SET maxmind_isp = ? WHERE user_id = ?');
            $this->conn->bind($stmt, 'ii', [$enabled ? 1 : 0, $this->userId]);
            $this->conn->executeUpdate($stmt);

            return $this->ispState();
        })];
    }

    /** @return array<string, mixed> */
    private function ispState(): array
    {
        $row = $this->one('SELECT maxmind_isp FROM 202_users_pref WHERE user_id = ? LIMIT 1', 'i', [$this->userId]);
        if ($row === null) {
            throw new DatabaseException('user ' . $this->userId . ' has no 202_users_pref row');
        }
        $database = self::ispDatabase();

        return [
            'enabled' => (int) $row['maxmind_isp'] === 1,
            'database_file' => $database['file'],
            'database_dir' => $database['dir'],
            // The redirects cache their tracker row, the switch with it.
            'takes_effect' => 'within five minutes on live traffic',
        ];
    }

    /**
     * Where getisp() (connect2.php) looks for the ISP database, and which
     * file it would use: P202_GEO_DIR on container deployments, otherwise
     * 202-config/geo.
     *
     * @return array{dir: string, file: ?string}
     */
    private static function ispDatabase(): array
    {
        $dir = getenv('P202_GEO_DIR') ?: dirname(__DIR__, 3) . '/202-config/geo';
        foreach (self::ISP_DATABASES as $file) {
            if (file_exists($dir . '/' . $file)) {
                return ['dir' => $dir, 'file' => $file];
            }
        }

        return ['dir' => $dir, 'file' => null];
    }

    // ─── GET /system/integrations ───────────────────────────────────────

    /**
     * The URLs the Integrations page tells you to paste into each network,
     * on this install's tracking base (TrackingBaseUrl: user 1's tracking
     * domain, or this server's name, and the install's path — the base the
     * API's tracker links use), and whether the caller has stored the secret
     * each one is checked with. Never the secret.
     *
     * @param array<string, mixed>|null $server the request ($_SERVER)
     * @return array{data: array<string, mixed>}
     */
    public function integrations(?array $server = null): array
    {
        return ['data' => $this->guard(function () use ($server): array {
            $domain = $this->one('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = ? LIMIT 1', 'i', [self::INSTALL_OWNER]);
            $base = TrackingBaseUrl::build((string) ($domain['user_tracking_domain'] ?? ''), $server ?? $_SERVER, dirname(__DIR__, 3));
            $prefs = $this->one(
                'SELECT cb_key, cb_verified, jvzoo_ipn_secret_key, zaxaa_api_signature, user_slack_incoming_webhook
                 FROM 202_users_pref WHERE user_id = ? LIMIT 1',
                'i',
                [$this->userId]
            ) ?? [];

            $list = [];
            foreach (self::INTEGRATIONS as $key => [$name, $label, $script, $secret]) {
                $entry = [
                    'integration' => $key,
                    'name' => $name,
                    'label' => $label,
                    'url' => $base . $script,
                    'secret_stored' => $secret === null ? null : trim((string) ($prefs[$secret] ?? '')) !== '',
                ];
                if ($key === 'clickbank') {
                    $entry['verified'] = (int) ($prefs['cb_verified'] ?? 0) === 1;
                }
                $list[] = $entry;
            }

            return ['base_url' => $base, 'integrations' => $list];
        })];
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param list<int|string> $values
     * @return array<string, mixed>|null
     */
    private function one(string $sql, string $types = '', array $values = []): ?array
    {
        $stmt = $this->conn->prepareRead($sql);
        if ($types !== '') {
            $this->conn->bind($stmt, $types, $values);
        }

        return $this->conn->fetchOne($stmt);
    }

    /**
     * @param list<int|string> $values
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, string $types, array $values): array
    {
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $values);

        return $this->conn->fetchAll($stmt);
    }

    /** A body key that belongs in the query string, said so when it is refused. */
    private const QUERY_NOT_BODY = [
        'dry_run' => 'goes in the query string, not the body: …?dry_run=1 previews; without it the request writes',
    ];

    /**
     * A database failure is a 500, never an answer: an unread setting must
     * not read as "off", nor an unread count as "nothing to delete".
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Api\V3\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DatabaseException('Administration: ' . $e->getMessage(), $e);
        }
    }
}

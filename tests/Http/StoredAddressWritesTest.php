<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\AddressWriteScan;
use Tests\Support\SourceScan;

/**
 * Every write of an address column in the served tree is a decision, made
 * once and written down here: the visitor's address goes through the
 * storage helper (masked under the privacy setting), or the write is listed
 * with the reason it does not need to — and that list only shrinks.
 *
 * Privacy mode used to govern the click path alone. The app intakes stored
 * the device's address as it arrived whatever the setting said
 * (202_app_installs.remote_ip from REMOTE_ADDR, 202_app_postbacks.remote_ip
 * from the forwarded address), measured live under 'all'; nothing compared
 * them with the click path, because nothing listed where an address is
 * written. This does: AddressWriteScan reads every INSERT, REPLACE and
 * UPDATE as PHP assembles it, against the address columns the installer's
 * own table definitions declare (a column with `ip` as a word in its name),
 * and reports a write whose columns or table it cannot read rather than
 * passing it.
 *
 * The decisions:
 *  - STORED: the value is the storage helper's — p202StoredVisitorIp() on
 *    the click path, StoredVisitorIp::forAccount() in the intakes — or the
 *    202_ips row the index made from it. Each names the files that call the
 *    helper for it, and those must call it.
 *  - INDEX: the address index itself (202_ips, 202_ips_v6), storing what its
 *    caller hands it; StoredVisitorIpSourceTest holds every caller.
 *  - DERIVED: copies an ip_id from a click already stored.
 *  - OPERATOR: the address of whoever signed in or made a signed-in
 *    request — a security record the visitor privacy setting does not
 *    govern.
 *  - NONE: writes no request's address: an empty literal, a value the
 *    operator types (a report's Visitor IP filter), or an account field from
 *    a list that names no address column (checked: the files named as the
 *    list's home do not name the table's address columns).
 *  - UNREAD: a write whose table the scan cannot read, with the tables it
 *    can reach — or the one column it sets there — (checked: no address
 *    column among them).
 *
 * What this cannot see: a write of an address through anything but an SQL
 * string in PHP (a stored procedure, a file), and a value's path from the
 * request to a STORED write — that a STORED write binds the helper's value
 * is the behaviour tests' to prove (PostbackReceiverTest,
 * InstallStoresMaskedAddressIntegrationTest, the click path's
 * StoredVisitorIpSourceTest); here the helper is only required to be
 * called where the entry says. A column holding an address under a name
 * without `ip` as a word is not seen either; the table definitions have none.
 */
final class StoredAddressWritesTest extends TestCase
{
    private const STORED = 'stored';
    private const INDEX = 'index';
    private const DERIVED = 'derived';
    private const OPERATOR = 'operator';
    private const NONE = 'none';
    private const UNREAD = 'unread';

    /** The decisions that do not go through the storage helper: the list that only shrinks. */
    private const NOT_THROUGH_THE_HELPER = [self::OPERATOR, self::NONE, self::UNREAD];

    /**
     * How many listed writes do not go through the storage helper. Lower it
     * when one goes; raising it is a decision a reviewer has to see.
     */
    private const CEILING = 20;

    private const HELPER_CALL = '/\bp202StoredVisitorIp\(\)|\bStoredVisitorIp::forAccount\(/';

    private const INDEX_INSERT = 'INDEXES::insert_ip(): the address get_ip_id() was handed';
    private const INDEX_COPY = 'the dead copy of INDEXES (class-indexes.php yields to whichever INDEXES loaded first)';
    private const DATAENGINE = 'the dataengine\'s rows, copied from the stored clicks (ip_id included: DERIVED)';

    /**
     * file => table.column => [decision, why, files that hold the evidence].
     *
     * The evidence: for STORED, the files that call the storage helper for
     * this write; for NONE on `table.*`, the files whose column list feeds
     * the write (none may name the table's address columns); for UNREAD,
     * the tables the write can reach, or `table.column` for the one column
     * it sets there.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2?: list<string>}>>
     */
    private const WRITES = [
        // ── The click path ──────────────────────────────────────────────
        'tracking202/redirect/rtr.php' => [
            '202_clicks_advance.ip_id' => [
                self::STORED,
                'the rotator\'s click: INDEXES::get_ip_id($db, p202StoredVisitorIp())',
                ['tracking202/redirect/rtr.php'],
            ],
        ],
        '202-config/Click/MysqlClickRepository.php' => [
            '202_clicks_advance.ip_id' => [
                self::STORED,
                'the ip_id its callers resolve with findOrCreateIp(p202StoredVisitorIp())',
                [
                    'tracking202/redirect/dl.php', 'tracking202/static/record_simple.php',
                    'tracking202/static/record_adv.php',
                ],
            ],
        ],
        '202-config/connect2.php' => [
            '202_clicks_advance.ip_id' => [
                self::DERIVED,
                'insertClicksAdvance() has no caller; a caller would hand it the click path\'s $mysql[\'ip_id\']',
            ],
            '202_ips.ip_address' => [self::INDEX, self::INDEX_INSERT],
            '202_ips_v6.ip_address' => [self::INDEX, self::INDEX_INSERT],
            '202_last_ips.ip_id' => [
                self::DERIVED,
                'FILTER::checkLastIps(): the click\'s stored ip_id, masked under privacy',
            ],
            '202_mysql_errors.ip_id' => [
                self::STORED,
                'the click path\'s error log: INDEXES::get_ip_id(p202StoredVisitorIp())',
                ['202-config/connect2.php'],
            ],
        ],
        '202-config/Repository/Mysql/MysqlLocationRepository.php' => [
            '202_ips.ip_address' => [self::INDEX, 'findOrCreateIp(): the address it was handed'],
            '202_ips_v6.ip_address' => [self::INDEX, 'findOrCreateIp(): the address it was handed'],
        ],
        '202-config/functions-tracking202.php' => [
            '202_ips.ip_address' => [self::INDEX, self::INDEX_INSERT . ' (connect.php\'s copy)'],
            '202_ips_v6.ip_address' => [self::INDEX, self::INDEX_INSERT . ' (connect.php\'s copy)'],
            '202_mysql_errors.ip_id' => [
                self::OPERATOR,
                'record_mysql_error() on connect.php\'s pages: the signed-in pages, the sign-in page and the cron'
                    . ' jobs, plus ClickBank\'s notification (cb202.php, ClickBank\'s server); no visitor of a'
                    . ' tracking link reaches connect.php',
            ],
        ],
        '202-config/class-indexes.php' => [
            '202_ips.ip_address' => [self::INDEX, self::INDEX_COPY],
            '202_ips_v6.ip_address' => [self::INDEX, self::INDEX_COPY],
        ],
        // ── Conversions ─────────────────────────────────────────────────
        '202-config/Conversion/MysqlConversionRepository.php' => [
            '202_conversion_logs.*' => [
                self::STORED,
                'the conversion row\'s ip is the one its callers hand it: the pixels\' and postbacks\''
                    . ' p202StoredVisitorIp() (StoredVisitorIpSourceTest; ClickBank\'s server address in cb202.php);'
                    . ' the API, the uploads and the subid batches hand none',
                [
                    'tracking202/static/pb.php', 'tracking202/static/px.php', 'tracking202/static/gpb.php',
                    'tracking202/static/gpx.php', 'tracking202/static/upx.php',
                ],
            ],
        ],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [
            '202_conversion_logs.ip' => [self::NONE, 'a ledger row it writes itself stores ip as the literal \'\''],
            '?.*' => [
                self::UNREAD,
                'the click\'s lead and payout, on the table the loop names',
                ['202_clicks', '202_clicks_spy'],
            ],
        ],
        // ── The app intakes ─────────────────────────────────────────────
        'api/v3/Apps/Android/InstallIntake.php' => [
            '202_app_installs.remote_ip' => [
                self::STORED,
                'the device\'s address: StoredVisitorIp::forAccount() under the install\'s and the owner\'s setting',
                ['api/v3/Apps/Android/InstallIntake.php'],
            ],
        ],
        'api/v3/Apps/Apple/PostbackReceiver.php' => [
            '202_app_postbacks.*' => [
                self::STORED,
                'remote_ip, the device\'s address: StoredVisitorIp::forAccount() under the install\'s and the'
                    . ' owner\'s setting; the other columns are the postback\'s',
                ['api/v3/Apps/Apple/PostbackReceiver.php'],
            ],
        ],
        // ── Sign-in and the operator's own records ──────────────────────
        '202-login.php' => [
            '202_users_log.ip_address' => [
                self::OPERATOR,
                'the login audit log: the address of whoever tried to sign in, the throttle\'s key',
            ],
            '202_users.user_last_login_ip_id' => [
                self::OPERATOR,
                'the signed-in user\'s address, as it arrived: FILTER::checkUserIP compares the click\'s address'
                    . ' before the mask with it',
            ],
        ],
        '202-config/functions-report-prefs.php' => [
            '202_users_pref.*' => [
                self::NONE,
                'report preferences, user_pref_ip among them: the Visitor IP filter the operator types,'
                    . ' never a request\'s address',
            ],
        ],
        'tracking202/Report/ReportPrefsStore.php' => [
            '202_users_pref.*' => [
                self::NONE,
                'report preferences from functions-report-prefs.php\'s list; user_pref_ip is the Visitor IP filter'
                    . ' the operator types',
            ],
        ],
        '202-account/api-integrations.php' => [
            '202_users_pref.*' => [
                self::NONE,
                'updateUserPreference(): the integration keys the page names',
                ['202-account/api-integrations.php'],
            ],
        ],
        '202-config/functions-account-ui.php' => [
            '202_users.*' => [
                self::NONE,
                'Personal settings and the user editor: account fields from the page\'s own list',
                ['202-config/functions-account-ui.php'],
            ],
            '202_users_pref.*' => [
                self::NONE,
                'Personal settings: preferences from the page\'s own list',
                ['202-config/functions-account-ui.php'],
            ],
        ],
        '202-config/User/MysqlUserRepository.php' => [
            '202_users.*' => [
                self::NONE,
                'account fields from the repository\'s own list',
                ['202-config/User/MysqlUserRepository.php'],
            ],
            '202_users_pref.*' => [
                self::NONE,
                'preferences from the repository\'s own list',
                ['202-config/User/MysqlUserRepository.php'],
            ],
        ],
        'api/v3/Controllers/UsersController.php' => [
            '202_users.*' => [
                self::NONE,
                'account fields from the controller\'s own list',
                ['api/v3/Controllers/UsersController.php'],
            ],
            '202_users_pref.*' => [
                self::NONE,
                'preferences from PreferenceRules::columns()',
                ['api/v3/Controllers/UsersController.php', '202-config/User/PreferenceRules.php'],
            ],
        ],
        // ── Writes whose table the scan cannot read ─────────────────────
        'api/v3/Controller.php' => [
            '?.*' => [
                self::UNREAD,
                'the table each CRUD controller names in tableName() (testTheUnreadWritesReachNoAddressColumn'
                    . ' reads them all)',
            ],
        ],
        '202-config/Crud/MysqlCrudRepository.php' => [
            '?.*' => [
                self::UNREAD,
                'the table its TableConfig names (testTheUnreadWritesReachNoAddressColumn reads every one)',
            ],
        ],
        '202-config/Repository/Mysql/MysqlTrackingRepository.php' => [
            '?.*' => [
                self::UNREAD,
                'a c1-c4 or UTM value into the dictionary table its kind names',
                [
                    '202_tracking_c1', '202_tracking_c2', '202_tracking_c3', '202_tracking_c4', '202_utm_source',
                    '202_utm_medium', '202_utm_campaign', '202_utm_term', '202_utm_content',
                ],
            ],
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            '?.*' => [
                self::UNREAD,
                'a customer merge repoints customer_id, the one column it writes, on the tables the loop names',
                [
                    '202_revenue_events.customer_id', '202_conversion_logs.customer_id',
                    '202_subscriptions.customer_id', '202_engagement_events.customer_id',
                    '202_personalization_tokens.customer_id', '202_clicks_tracking.customer_id',
                ],
            ],
        ],
        '202-config/DataEngine/ClickRollupSql.php' => [
            '?.*' => [self::UNREAD, self::DATAENGINE, ['202_dataengine']],
        ],
        '202-config/class-dataengine.php' => [
            '?.*' => [self::UNREAD, self::DATAENGINE, ['202_dataengine']],
        ],
    ];

    /**
     * Tables an UNREAD write reaches whose address column it can only copy
     * from a stored click: an aggregate's ip_id.
     */
    private const DERIVED_TABLES = ['202_dataengine'];

    /** @return array<string, array<string, list<int>>> file => table.column => lines */
    private static function found(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $writes = AddressWriteScan::writes($source);
            if ($writes !== []) {
                $found[$path] = $writes;
            }
        }

        return $found;
    }

    public function testTheSchemaScanFindsTheAddressColumns(): void
    {
        $columns = AddressWriteScan::addressColumns();
        $expected = [
            '202_app_installs' => 'remote_ip', '202_app_postbacks' => 'remote_ip', '202_conversion_logs' => 'ip',
            '202_clicks_advance' => 'ip_id', '202_ips' => 'ip_address', '202_ips_v6' => 'ip_address',
            '202_users_log' => 'ip_address', '202_users' => 'user_last_login_ip_id', '202_last_ips' => 'ip_id',
            '202_mysql_errors' => 'ip_id', '202_users_pref' => 'user_pref_ip',
        ];
        foreach ($expected as $table => $column) {
            self::assertContains($column, $columns[$table] ?? [], "$table.$column is an address column");
        }
        self::assertNotContains('ipqs_api_key', $columns['202_users_pref'], '`ip` must be a word of the name');
        self::assertNotContains('vip_perks_status', $columns['202_users'] ?? []);
    }

    public function testEveryAddressWriteIsDecided(): void
    {
        $found = self::found();
        self::assertArrayHasKey('api/v3/Apps/Android/InstallIntake.php', $found, 'the scan sees the Android intake');
        self::assertArrayHasKey('202-login.php', $found, 'and the sign-in');

        $undecided = [];
        foreach ($found as $file => $writes) {
            foreach ($writes as $write => $lines) {
                if (!isset(self::WRITES[$file][$write])) {
                    $undecided[] = $file . ':' . implode(',', $lines) . '  writes ' . $write;
                }
            }
        }
        self::assertSame([], $undecided, "An address column is written and nobody decided what it stores:\n  "
            . implode("\n  ", $undecided)
            . "\nA visitor's or a device's address is stored through the helper — p202StoredVisitorIp() on the"
            . ' click path, StoredVisitorIp::forAccount() elsewhere — masked under the privacy setting. Then list'
            . ' the write here as STORED; anything else is listed with its reason, under the ceiling.');

        $stale = [];
        foreach (self::WRITES as $file => $writes) {
            foreach (array_keys($writes) as $write) {
                if (!isset($found[$file][$write])) {
                    $stale[] = $file . '  ' . $write;
                }
            }
        }
        self::assertSame([], $stale, "Listed writes the scan no longer finds; drop them from the list:\n  "
            . implode("\n  ", $stale));
    }

    public function testTheWritesNotThroughTheHelperOnlyShrink(): void
    {
        $count = 0;
        foreach (self::WRITES as $writes) {
            foreach ($writes as [$decision]) {
                $count += in_array($decision, self::NOT_THROUGH_THE_HELPER, true) ? 1 : 0;
            }
        }
        self::assertLessThanOrEqual(
            self::CEILING,
            $count,
            'a write that does not go through the storage helper was added; see the class docblock'
        );
        self::assertSame(self::CEILING, $count, 'one went: lower CEILING to ' . $count . ' so it cannot return unseen');
    }

    public function testEveryStoredWriteIsFedByTheHelper(): void
    {
        $root = SourceScan::repoRoot();
        foreach (self::WRITES as $file => $writes) {
            foreach ($writes as $write => $entry) {
                if ($entry[0] !== self::STORED) {
                    continue;
                }
                self::assertNotEmpty($entry[2] ?? [], "$file $write: a STORED write names the files that feed it");
                foreach ($entry[2] as $feeder) {
                    self::assertMatchesRegularExpression(
                        self::HELPER_CALL,
                        (string) file_get_contents($root . '/' . $feeder),
                        "$file $write is listed as fed by $feeder, which calls no storage helper"
                    );
                }
            }
        }
    }

    public function testTheListsBehindNoneWritesNameNoAddressColumn(): void
    {
        $root = SourceScan::repoRoot();
        $columns = AddressWriteScan::addressColumns();
        foreach (self::WRITES as $file => $writes) {
            foreach ($writes as $write => $entry) {
                if ($entry[0] !== self::NONE || !str_ends_with($write, '.*') || !isset($entry[2])) {
                    continue;
                }
                $table = substr($write, 0, -2);
                foreach ($entry[2] as $home) {
                    $source = (string) file_get_contents($root . '/' . $home);
                    foreach ($columns[$table] as $column) {
                        self::assertDoesNotMatchRegularExpression(
                            '/\b' . preg_quote($column, '/') . '\b/',
                            $source,
                            "$file writes $table from a list in $home, which names the address column $column;"
                                . ' decide that write'
                        );
                    }
                }
            }
        }
    }

    public function testTheUnreadWritesReachNoAddressColumn(): void
    {
        $columns = AddressWriteScan::addressColumns();
        $reach = [];
        foreach (self::WRITES as $file => $writes) {
            foreach ($writes as $entry) {
                if ($entry[0] === self::UNREAD && isset($entry[2])) {
                    $reach[$file] = $entry[2];
                }
            }
        }
        $reach['api/v3/Controller.php'] = self::controllerTables();
        $reach['202-config/Crud/MysqlCrudRepository.php'] = self::tableConfigTables();
        self::assertGreaterThanOrEqual(8, count($reach['api/v3/Controller.php']), 'every CRUD controller is read');
        $configs = $reach['202-config/Crud/MysqlCrudRepository.php'];
        self::assertGreaterThanOrEqual(5, count($configs), 'every TableConfig is read');

        foreach ($reach as $file => $tables) {
            foreach ($tables as $reached) {
                // A table, or a table and the one column the write sets there.
                [$table, $column] = array_pad(explode('.', $reached, 2), 2, null);
                if (in_array($table, self::DERIVED_TABLES, true)) {
                    continue;
                }
                if ($column !== null) {
                    self::assertFalse(
                        AddressWriteScan::isAddressColumn($column),
                        "$file writes $reached, an address column; decide that write"
                    );
                    continue;
                }
                self::assertArrayNotHasKey($table, $columns, "$file can write $table, which has an address column");
            }
        }
    }

    /** @return list<string> */
    private static function controllerTables(): array
    {
        $tables = [];
        foreach (glob(SourceScan::repoRoot() . '/api/v3/Controllers/*Controller.php') ?: [] as $path) {
            $class = 'Api\\V3\\Controllers\\' . basename($path, '.php');
            if (!class_exists($class) || !is_subclass_of($class, \Api\V3\Controller::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $method = $reflection->getMethod('tableName');
            $tables[] = (string) $method->invoke($reflection->newInstanceWithoutConstructor());
        }

        return $tables;
    }

    /** @return list<string> */
    private static function tableConfigTables(): array
    {
        $tables = [];
        $class = new \ReflectionClass(\Prosper202\Crud\TableConfig::class);
        $methods = $class->getMethods(\ReflectionMethod::IS_STATIC);
        foreach ($methods as $method) {
            if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                $config = $method->invoke(null);
                if ($config instanceof \Prosper202\Crud\TableConfig) {
                    $tables[] = $config->table;
                }
            }
        }

        return $tables;
    }

    /**
     * Every spelling the scan claims to read, each in a source of its own.
     *
     * @return iterable<string, array{string, list<string>}> source, the writes it must find
     */
    public static function spellings(): iterable
    {
        yield 'a column list' => [
            '<?php $db->prepare("INSERT INTO 202_app_installs (user_id, remote_ip) VALUES (?, ?)");',
            ['202_app_installs.remote_ip'],
        ];
        yield 'a column list over lines, backticked' => [
            "<?php \$sql = 'INSERT INTO `202_app_installs`\n (`user_id`,\n `remote_ip`)\n VALUES (?, ?)';",
            ['202_app_installs.remote_ip'],
        ];
        yield 'a SET clause' => [
            "<?php \$db->query(\"INSERT INTO 202_last_ips SET user_id='\" . \$u . \"', ip_id='\" . \$i . \"'\");",
            ['202_last_ips.ip_id'],
        ];
        yield 'a column after an unread value' => [
            "<?php \$db->query('UPDATE 202_users SET user_name = ' . \$x . ', user_last_login_ip_id = 5');",
            ['202_users.user_last_login_ip_id'],
        ];
        yield 'a column after a call' => [
            "<?php \$db->query('UPDATE 202_users SET user_name = ' . \$db->real_escape_string(\$n)"
                . " . ', user_last_login_ip_id = 5');",
            ['202_users.user_last_login_ip_id'],
        ];
        yield 'an upsert\'s update' => [
            '<?php $q = "INSERT INTO 202_conversion_logs (click_id) VALUES (?)'
                . ' ON DUPLICATE KEY UPDATE ip = VALUES(ip)";',
            ['202_conversion_logs.ip'],
        ];
        yield 'REPLACE' => [
            '<?php $q = "REPLACE INTO 202_users_log (ip_address) VALUES (?)";',
            ['202_users_log.ip_address'],
        ];
        yield 'INSERT IGNORE' => [
            '<?php $q = "INSERT IGNORE INTO 202_clicks_advance SET click_id = 1, ip_id = 2";',
            ['202_clicks_advance.ip_id'],
        ];
        yield 'lower case' => ['<?php $q = "insert into 202_last_ips set ip_id = 1";', ['202_last_ips.ip_id']];
        yield 'an UPDATE with an alias and a join' => [
            '<?php $q = "UPDATE 202_users u JOIN 202_users_pref p ON p.user_id = u.user_id'
                . ' SET p.user_pref_ip = ?, u.user_name = ?";',
            ['202_users_pref.user_pref_ip'],
        ];
        yield 'a table by its constant' => [
            "<?php use Prosper202\\Database\\Schema\\TableRegistry;\n"
                . "\$q = 'INSERT INTO ' . TableRegistry::APP_INSTALLS . ' (remote_ip) VALUES (?)';",
            ['202_app_installs.remote_ip'],
        ];
        yield 'a heredoc' => [
            "<?php \$q = <<<SQL\nINSERT INTO 202_mysql_errors SET ip_id = {\$id}\nSQL;",
            ['202_mysql_errors.ip_id'],
        ];
        yield 'a nowdoc' => [
            "<?php \$q = <<<'SQL'\nUPDATE 202_app_postbacks SET remote_ip = ''\nSQL;",
            ['202_app_postbacks.remote_ip'],
        ];
        yield 'inside a call\'s arguments' => [
            "<?php \$stmt = \$this->conn->prepareWrite('INSERT INTO 202_app_installs (remote_ip) VALUES (?)');",
            ['202_app_installs.remote_ip'],
        ];
        yield 'a column list built at runtime' => [
            "<?php \$q = 'INSERT INTO 202_app_postbacks (' . implode(', ', array_keys(\$c))"
                . " . ') VALUES (' . \$v . ')';",
            ['202_app_postbacks.*'],
        ];
        yield 'a SET clause built at runtime' => [
            "<?php \$q = 'UPDATE 202_users_pref SET ' . implode(', ', \$sets) . ' WHERE user_id = ?';",
            ['202_users_pref.*'],
        ];
        yield 'no column list' => ['<?php $q = "INSERT INTO 202_ips VALUES (NULL, \'1.2.3.4\', 0)";', ['202_ips.*']];
        yield 'INSERT … SELECT' => ['<?php $q = "INSERT INTO 202_dataengine SELECT * FROM x";', ['202_dataengine.*']];
        yield 'a statement finished later' => [
            "<?php \$sql = 'INSERT INTO 202_last_ips SET '; \$sql .= 'ip_id = 1';",
            ['202_last_ips.*'],
        ];
        yield 'a table read from a variable' => [
            "<?php \$db->query('INSERT INTO ' . \$table . ' SET ip_id = 1');",
            ['?.*'],
        ];
        yield 'a table interpolated' => ['<?php $db->query("UPDATE {$t} SET a = 1");', ['?.*']];
        yield 'a table by sprintf' => ["<?php \$q = sprintf('INSERT INTO %s (ip) VALUES (?)', \$t);", ['?.*']];
        yield 'a lower-case table built at runtime' => [
            "<?php return 'insert into ' . \$table . '(a) SELECT b';",
            ['?.*'],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected
     */
    public function testTheScanReadsEverySpelling(string $source, array $expected): void
    {
        self::assertSame($expected, array_keys(AddressWriteScan::writes($source)));
    }

    /** @return iterable<string, array{string}> */
    public static function notWrites(): iterable
    {
        yield 'a read' => ['<?php $q = "SELECT ip_id FROM 202_clicks_advance WHERE ip_id = ?";'];
        yield 'an UPDATE of other columns' => [
            '<?php $q = "UPDATE 202_users SET user_name = ? WHERE user_last_login_ip_id = ?";',
        ];
        yield 'a table with no address column' => ['<?php $q = "INSERT INTO 202_aff_campaigns (ip) VALUES (?)";'];
        yield 'a delete' => ['<?php $q = "DELETE FROM 202_last_ips WHERE ip_id = 1";'];
        yield 'SELECT … FOR UPDATE' => ['<?php $q = "SELECT ip_id FROM 202_users FOR UPDATE";'];
        yield 'prose' => ["<?php \$m = 'could not update ' . \$what;"];
        yield 'prose naming a word' => ["<?php \$m = 'Failed to insert into ' . \$table . ': ' . \$e;"];
        yield 'an upsert of other columns' => [
            '<?php $q = "INSERT INTO 202_users_pref (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_pref_limit = 1";',
        ];
    }

    /** @dataProvider notWrites */
    public function testTheScanLeavesOtherStatementsAlone(string $source): void
    {
        self::assertSame([], AddressWriteScan::writes($source));
    }
}

<?php

/**
 * The functions both bootstraps use, one copy each: the admin pages
 * (connect.php, through functions.php and functions-tracking202.php) and the
 * click path (connect2.php) each defined their own, and the copies drifted —
 * the same name failed one way on a report page and another on a redirect.
 * Only function definitions: loading this runs nothing. Whoever loads it
 * loads mysql-error-args.php too (the error page's helpers).
 *
 * What still differs between the two is asked of p202OnTheClickPath(): the
 * error page's address and footer, and how long a cached read keeps its
 * answer.
 */

declare(strict_types=1);

/** Whether connect2.php, the click path's bootstrap, served this request. */
function p202OnTheClickPath(): bool
{
    return defined('P202_CLICK_PATH');
}

/**
 * Keep a value in memcache; 30 days unless told otherwise. Held while
 * p202WithHeldCacheWrites() runs its work.
 */
function setCache(string $key, mixed $value, mixed $exp = null): bool
{
    global $whatCache, $memcache;
    if (is_array($GLOBALS['p202HeldCacheWrites'] ?? null)) {
        $GLOBALS['p202HeldCacheWrites'][] = [$key, $value, $exp];
        return true;
    }
    $exp = $exp === null ? 2592000 : (int) $exp;
    switch ($whatCache) {
        case 'memcache':
            return (bool) $memcache->set($key, $value, 0, $exp);

        case 'memcached':
            return (bool) $memcache->set($key, $value, $exp);
    }

    return false;
}

/**
 * Run $work with the cache writes it makes held: written once it returns,
 * dropped when it throws (and the throw passed on).
 *
 * The lookups that run inside a click's transaction cache the id of a row
 * they insert at once (the site URL's, through CachedLocationRepository),
 * so a rollback after them left an entry naming no row, and every click to
 * that URL stored the dangling id until it expired. Run the transaction as
 * $work and an entry is written only once its row is committed.
 *
 * Work that ends the request (die() in an error page) neither returns nor
 * throws, and exit runs no finally: its writes are dropped because the work
 * never returned, and every later write is held too, shutdown work's
 * included. On the error page that costs only the dropped writes:
 * record_mysql_error() has rolled back and turned autocommit on before it
 * writes (p202RecordMysqlErrorRow()). Where an exit leaves a transaction
 * open instead, it stays open through the shutdown functions and is rolled
 * back when the process ends (HeldWritesOpenTransactionIntegrationTest), so
 * an id cached then could name a row that is never kept.
 */
function p202WithHeldCacheWrites(callable $work): void
{
    if (is_array($GLOBALS['p202HeldCacheWrites'] ?? null)) {
        throw new \LogicException('Cache writes are already held: the outer work would lose its own.');
    }
    $GLOBALS['p202HeldCacheWrites'] = [];
    try {
        $work();
    } catch (\Throwable $e) {
        $GLOBALS['p202HeldCacheWrites'] = null;
        throw $e;
    }
    $held = $GLOBALS['p202HeldCacheWrites'];
    $GLOBALS['p202HeldCacheWrites'] = null;
    // The work is done (its transaction committed): a cache write that fails
    // now costs a miss, and must not read as the work failing.
    foreach ($held as [$key, $value, $exp]) {
        try {
            setCache($key, $value, $exp);
        } catch (\Throwable $e) {
            error_log('A held cache write failed after the work was done: ' . $e->getMessage());
        }
    }
}

function getCache(string $key, mixed $default = false): mixed
{
    global $memcache;
    if ($memcache instanceof \Memcached || $memcache instanceof \Memcache) {
        return call_user_func([$memcache, 'get'], $key);
    }
    return $default;
}

/**
 * The namespace of every cache key: the host, and the install the ids and
 * rows a key names belong to. Its directory is shared by no other install on
 * the server and is known in every scope; its database also tells a
 * reinstall into a new one apart.
 */
function systemHash(): string
{
    $install = [__DIR__];
    foreach (['dbhost', 'dbname'] as $setting) {
        $value = $GLOBALS[$setting] ?? '';
        $install[] = is_string($value) ? $value : '';
    }

    return hash('ripemd160', ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['SERVER_ADDR'] ?? '')
        . "\0" . implode("\0", $install));
}

/** The install's path below the document root, with forward slashes. */
function get_absolute_url(): string
{
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $path = substr(substr(__DIR__, 0, -10), strlen($root === false ? '' : $root));

    return str_replace('\\', '/', $path);
}

/**
 * The tracking domain (`host[:port]`) of the links, snippets and postback
 * URLs a page shows the person who asked for it: the stored one, or the host
 * this request arrived at (TrackingBaseUrl::domainForResponse(), which says
 * why not the server's own name and port). Its callers are listed, each with
 * why its URL goes back to the requester, in RequestHostSourceTest; a URL
 * sent to anyone else is p202TrackingBaseUrl().
 */
function getTrackingDomain(): string
{
    return \Prosper202\Click\TrackingBaseUrl::domainForResponse(p202StoredTrackingDomain(), $_SERVER);
}

/**
 * The tracking domain an account stored (Personal settings), as stored:
 * the signed-in user's by default, the first account's where nobody is
 * signed in (the click path). A failed read throws: read as "none stored",
 * every URL would name the request's host instead.
 */
function p202StoredTrackingDomain(?int $userId = null): string
{
    $lookup_user_id = $userId !== null
        ? (string) $userId
        : ((isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) ? (string) $_SESSION['user_id'] : '1');

    $database = DB::getInstance();
    $db = $database->getConnection();
    $failed = 'Unable to read the tracking domain of account ' . $lookup_user_id;
    $tracking_domain_sql = "
		SELECT
			`user_tracking_domain`
		FROM
			`202_users_pref`
		WHERE
			`user_id`='" . $db->real_escape_string($lookup_user_id) . "'
	";
    try {
        $tracking_domain_result = _mysqli_query($tracking_domain_sql);
    } catch (\mysqli_sql_exception $e) {
        // Strict reporting throws MySQL's sentence; name whose read it was.
        throw new \RuntimeException($failed . ': ' . $e->getMessage(), 0, $e);
    }
    if (!$tracking_domain_result instanceof \mysqli_result) {
        try {
            $error = (string) $db->error;
        } catch (\Error) {
            $error = '';
        }
        throw new \RuntimeException($failed . ($error !== '' ? ': ' . $error : ''));
    }
    try {
        // A failed fetch throws too: false read as "no preferences row".
        $row = p202FetchRow($tracking_domain_result, $tracking_domain_sql);
    } catch (\Prosper202\Database\Exceptions\QueryException | \mysqli_sql_exception $e) {
        throw new \RuntimeException($failed . ': ' . $e->getMessage(), 0, $e);
    }

    return is_string($row['user_tracking_domain'] ?? null) ? $row['user_tracking_domain'] : '';
}

/**
 * The request's database connection: the global one, else the configured
 * one. Throws when there is none, rather than letting a query run on null.
 */
function p202Mysqli(): \mysqli
{
    $db = $GLOBALS['db'] ?? null;
    if ($db instanceof \mysqli) {
        return $db;
    }
    try {
        $database = \DB::getInstance();
    } catch (\mysqli_sql_exception $e) {
        throw new \Prosper202\Database\Exceptions\QueryException('No database connection: ' . $e->getMessage(), 0, $e);
    }
    $db = method_exists($database, 'getConnection') ? $database->getConnection() : null;
    if (!$db instanceof \mysqli) {
        throw new \Prosper202\Database\Exceptions\QueryException('No database connection');
    }

    return $db;
}

/**
 * An address as the $ip_address global holds it: its type, and the address
 * in standard form, masked (StoredVisitorIp::mask()) when asked. Anything
 * that is not an address is the unspecified 0.0.0.0.
 *
 * $masked has no default: the click path masks under the privacy setting and
 * the admin pages never do, and a caller that left it out would get one of
 * those without saying which.
 */
function ipAddress(mixed $ip_address, bool $masked): \stdClass
{
    $ip = new \stdClass();
    if (is_string($ip_address) && filter_var($ip_address, FILTER_VALIDATE_IP) !== false) {
        $ip->address = $ip_address;
        $ip->type = filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ipv4' : 'ipv6';
    } else {
        $ip->type = 'invalid';
        $ip->address = '0.0.0.0';
    }

    if ($masked) {
        $ip = maskIpAddress($ip);
    }

    $packed = @inet_pton((string) $ip->address);
    $formatted = $packed === false ? false : @inet_ntop($packed);
    if ($formatted !== false) {
        $ip->address = $formatted;
    }
    return $ip;
}

function maskIpAddress(\stdClass $ip): \stdClass
{
    // The one mask (StoredVisitorIp::mask(): /24, /48 on the packed bytes),
    // so the $ip_address global and every stored address agree.
    if ($ip->type === 'ipv4' || $ip->type === 'ipv6') {
        $ip->address = \Prosper202\Http\StoredVisitorIp::mask((string) $ip->address);
    }

    return $ip;
}

/**
 * Run a statement and answer what mysqli does: a result, true, or false for
 * a failure, which is logged here (p202LogFailedQuery()) and is the
 * caller's to act on. Nothing else: no error page, no die().
 *
 * _mysqli_query($sql) uses the global connection; _mysqli_query($db, $sql)
 * the one given (a mysqli, or anything with getConnection()).
 */
function _mysqli_query(mixed $db_or_sql, ?string $sql = null): \mysqli_result|bool
{
    if ($sql === null) {
        $sql = (string) $db_or_sql;
        $connection = p202Mysqli();
    } elseif ($db_or_sql instanceof \mysqli) {
        $connection = $db_or_sql;
    } elseif (is_object($db_or_sql) && method_exists($db_or_sql, 'getConnection')) {
        $connection = $db_or_sql->getConnection();
        if (!$connection instanceof \mysqli) {
            throw new \Prosper202\Database\Exceptions\QueryException('No database connection');
        }
    } else {
        $connection = p202Mysqli();
    }

    $result = $connection->query($sql);
    if ($result === false) {
        p202LogFailedQuery($connection, $sql, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? []);
    }
    return $result;
}

/**
 * Log a statement _mysqli_query() ran that failed. It answers false, which
 * most of its callers read as "no rows" or never look at, so this line is
 * where the failure shows.
 *
 * The statement and the server's message are logged with the values they
 * quote taken out (p202RedactedStatement(), p202FailedQueryMessage()).
 *
 * @param array{file?: string, line?: int} $caller
 */
function p202LogFailedQuery(\mysqli $connection, string $sql, array $caller): void
{
    error_log(sprintf(
        'MySQL query failed at %s:%d [errno %d]: %s | %s',
        $caller['file'] ?? '?',
        $caller['line'] ?? 0,
        $connection->errno,
        p202FailedQueryMessage($connection->errno, $connection->error),
        p202RedactedStatement($sql)
    ));
}

/**
 * The one row a statement selects, kept in memcache for $minutes when
 * caching is on and allowed: null when there is none (kept too). A failed
 * query throws: false read as "no such row" everywhere this is called.
 *
 * Takes ($sql), ($db, $sql), ($sql, $allowCaching, $minutes),
 * ($db, $sql, $allowCaching, $minutes) and a mysqli_result.
 *
 * @return array<string, mixed>|null
 */
function memcache_mysql_fetch_assoc(mixed ...$args): ?array
{
    if (($args[0] ?? null) instanceof \mysqli_result) {
        return p202FetchRow($args[0], '(a result handed in)');
    }
    $default = p202CachedReadMinutes(false);
    [$connection, $sql, $allowCaching, $minutes] = p202CachedReadArgs(array_values($args), $default);

    return p202CachedRead(
        $connection,
        $sql,
        $allowCaching,
        $minutes,
        static fn (\mysqli_result $result): ?array => p202FetchRow($result, $sql),
        static fn (mixed $kept): bool => $kept === null || p202IsRow($kept),
    );
}

/**
 * Every row a statement selects, as memcache_mysql_fetch_assoc() keeps one.
 *
 * @return list<array<string, mixed>>
 */
function foreach_memcache_mysql_fetch_assoc(mixed ...$args): array
{
    $default = p202CachedReadMinutes(true);
    [$connection, $sql, $allowCaching, $minutes] = p202CachedReadArgs(array_values($args), $default);

    return p202CachedRead(
        $connection,
        $sql,
        $allowCaching,
        $minutes,
        static function (\mysqli_result $result) use ($sql): array {
            $rows = [];
            while (($row = p202FetchRow($result, $sql)) !== null) {
                $rows[] = $row;
            }
            return $rows;
        },
        static fn (mixed $kept): bool => is_array($kept) && array_is_list($kept)
            && array_filter($kept, static fn (mixed $row): bool => !p202IsRow($row)) === [],
    );
}

/**
 * How long a cached read keeps its answer when its caller names no time, as
 * each bootstrap's own copy kept it: on the click path 3 minutes for a row
 * and 1 for a list (a redirector's rules and criteria, so an edit in Setup
 * reaches its clicks within the minute), on the pages 5 for both.
 */
function p202CachedReadMinutes(bool $list): int
{
    if (p202OnTheClickPath()) {
        return $list ? 1 : 3;
    }

    return 5;
}

/**
 * The connection, statement, caching switch and minutes of a cached read,
 * from either calling convention.
 *
 * @param list<mixed> $args
 * @return array{\mysqli, string, bool, int}
 */
function p202CachedReadArgs(array $args, int $defaultMinutes): array
{
    if (($args[0] ?? null) instanceof \mysqli) {
        if (!is_string($args[1] ?? null)) {
            throw new \InvalidArgumentException('A statement is required after the connection.');
        }
        [$connection, $sql, $rest] = [$args[0], $args[1], array_slice($args, 2)];
    } else {
        [$connection, $sql, $rest] = [p202Mysqli(), (string) ($args[0] ?? ''), array_slice($args, 1)];
    }
    if ($sql === '') {
        throw new \InvalidArgumentException('A statement is required.');
    }

    return [$connection, $sql, (bool) ($rest[0] ?? 1), (int) ($rest[1] ?? $defaultMinutes)];
}

/**
 * Read through memcache: a kept answer when there is one and caching is on,
 * else the statement's, kept for $minutes. A kept value that does not
 * unserialize to an answer of the right shape ($isAnswer) is a miss: the one
 * key is shared by the single-row and the every-row read of a statement.
 *
 * @template T of array<string, mixed>|list<array<string, mixed>>|null
 * @param callable(\mysqli_result): T $read
 * @param callable(mixed): bool $isAnswer
 * @return T
 */
function p202CachedRead(
    \mysqli $connection,
    string $sql,
    bool $allowCaching,
    int $minutes,
    callable $read,
    callable $isAnswer,
): mixed {
    global $memcacheWorking, $memcache;

    $useCache = $memcacheWorking == true && $allowCaching && is_object($memcache);
    $key = md5($sql . systemHash());
    if ($useCache) {
        $kept = $memcache->get($key);
        if (is_string($kept)) {
            $answer = @unserialize($kept, ['allowed_classes' => false]);
            if (($answer !== false || $kept === serialize(false)) && $isAnswer($answer)) {
                return $answer;
            }
        }
    }

    $result = $connection->query($sql);
    if (!$result instanceof \mysqli_result) {
        p202LogFailedQuery($connection, $sql, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? []);
        throw new \Prosper202\Database\Exceptions\QueryException(
            'MySQL query failed: ' . p202FailedQueryMessage($connection->errno, $connection->error)
                . ' [errno ' . $connection->errno . ']'
        );
    }
    $answer = $read($result);
    if ($useCache) {
        setCache($key, serialize($answer), 60 * $minutes);
    }

    return $answer;
}

/** A row as mysqli fetches one: column names to text, numbers or nulls. */
function p202IsRow(mixed $row): bool
{
    if (!is_array($row) || $row === [] || array_is_list($row)) {
        return false;
    }
    foreach ($row as $value) {
        if ($value !== null && !is_scalar($value)) {
            return false;
        }
    }

    return true;
}

/**
 * The next row of a result, or null at its end; a failed fetch throws
 * rather than reading as the end.
 *
 * @return array<string, mixed>|null
 */
function p202FetchRow(\mysqli_result $result, string $sql): ?array
{
    $row = $result->fetch_assoc();
    if ($row === false) {
        throw new \Prosper202\Database\Exceptions\QueryException(
            'MySQL fetch failed | ' . p202RedactedStatement($sql)
        );
    }

    return $row;
}

/**
 * Where a failure nothing caught came from, for the database error page's
 * record: the first frame outside the database layer and this file (a
 * QueryException is thrown deep in Connection or a repository), as its
 * file's name and line.
 */
function p202UncaughtAt(\Throwable $e): string
{
    $layer = '~[/\\\\]202-config[/\\\\](?:Database[/\\\\]|Repository[/\\\\]|functions-shared\.php$)~';
    [$file, $line] = [$e->getFile(), $e->getLine()];
    foreach ($e->getTrace() as $frame) {
        if (preg_match($layer, $file) !== 1) {
            break;
        }
        if (isset($frame['file'], $frame['line'])) {
            [$file, $line] = [$frame['file'], $frame['line']];
        }
    }

    return basename($file) . ':' . $line;
}

/**
 * What an uncaught throwable leaves in the log, from either bootstrap's
 * handler: each throwable of the chain by its class, its message and where it
 * was thrown, and the frames it passed through without their arguments (a
 * statement handed down the stack carries its values). A database failure's
 * message is the server's words, which repeat the values its statement
 * quoted, so it is redacted as the failed-query log is.
 */
function p202UncaughtLogLine(string $where, \Throwable $e): string
{
    $parts = [];
    for ($thrown = $e; $thrown !== null; $thrown = $thrown->getPrevious()) {
        $message = $thrown->getMessage();
        if ($thrown instanceof \mysqli_sql_exception) {
            $message = p202FailedQueryMessage((int) $thrown->getCode(), $message);
        } elseif ($thrown instanceof \Prosper202\Database\Exceptions\QueryException) {
            // Connection's tag, last in the message; none redacts everything quoted.
            $errno = preg_match('/ \[errno (\d+)\]\z/', $message, $m) === 1 ? (int) $m[1] : 0;
            $message = p202FailedQueryMessage($errno, $message);
        }
        $frames = [];
        foreach ($thrown->getTrace() as $i => $frame) {
            $frames[] = sprintf(
                '#%d %s(%s): %s%s%s()',
                $i,
                $frame['file'] ?? '[internal function]',
                $frame['line'] ?? '',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function']
            );
        }
        $parts[] = sprintf(
            "%s: %s in %s:%d\n%s",
            $thrown::class,
            $message,
            $thrown->getFile(),
            $thrown->getLine(),
            implode("\n", $frames)
        );
    }

    return $where . ': ' . implode("\nPrevious: ", $parts);
}

/**
 * The database error page: the failure logged and recorded in
 * 202_mysql_errors (both without the values the statement quotes), the
 * page told whether it was, and the request ended, under a 500 so a client
 * or a monitor does not read it as served (a page that has already sent its
 * headers keeps the status it sent).
 *
 * @param ?string $error what failed, from a caller that caught it: by then
 *        the connection's own error is cleared, if the caller rolled back
 */
function record_mysql_error(mixed $dbOrSql, mixed $sql = null, ?string $error = null): never
{
    $clickPath = p202OnTheClickPath();
    if (!headers_sent()) {
        // In place of whatever the page meant to answer, except a redirect
        // the click path has queued: dl.php sets it before it writes the
        // click, and a failure there loses the click, not the visitor too,
        // as under PHP-FPM, where the redirect has gone by then.
        // p202ClickPathUncaught() keeps one the same way.
        $status = http_response_code();
        if (!($clickPath && is_int($status) && $status >= 300 && $status < 400)) {
            header_remove('Location');
            http_response_code(500);
            header('Cache-Control: no-store');
        }
    }
    // ($db), ($sql) and ($db, $sql) are all in use; see p202MysqlErrorArgs().
    [$db, $sql] = p202MysqlErrorArgs($dbOrSql, $sql);
    if (!$db instanceof \mysqli) {
        try {
            $db = p202Mysqli();
        } catch (\Throwable) {
            $db = null;
        }
    }
    if (!$db instanceof \mysqli) {
        error_log('Database connection unavailable - SQL: ' . p202RedactedStatement($sql));
        echo 'Database error. The webmaster has been notified.';
        die();
    }

    // The server's message and the statement, without the values they
    // quote: they are logged, and listed in Account › Settings.
    [$clean['mysql_error_text'], $statement] = p202MysqlErrorRecorded($db, $sql, $error);
    error_log('MySQL error: ' . $clean['mysql_error_text'] . ' | SQL: ' . p202RedactedStatement($sql));

    if (!$clickPath) {
        // The session's zone, for the footer's clock; this page counts
        // nothing in it. AUTH::set_timezone() would read the account's zone
        // from the database that has just failed, and a failed read there
        // throws (a fatal in place of this page, with the error never
        // recorded).
        $sessionZone = is_string($_SESSION['user_timezone'] ?? null) ? $_SESSION['user_timezone'] : '';
        if (\Prosper202\Report\AccountZone::isZone($sessionZone)) {
            date_default_timezone_set($sessionZone);
        }
    }

    // Rolled back first, and never a fatal in place of the page; see
    // p202RecordMysqlErrorRow().
    $recorded = p202RecordMysqlErrorRow($db, static function () use ($db, $statement, $clean, $clickPath): void {
        // On the connection the row is written to, which has just been
        // rolled back: a lookup that fails throws back to the recorder.
        $locations = \Prosper202\Repository\LookupRepositoryFactory::location($db);
        // A visitor's address as the click path stores every one (masked
        // under privacy); an operator's as it arrived, as sign-ins keep it.
        $mysql['ip_id'] = (string) ($clickPath
            ? $locations->findOrCreateIp(p202StoredVisitorIp())
            : $locations->findOrCreateIp(\Prosper202\Http\VisitorIp::fromServer($_SERVER)));
        $mysql['site_id'] = (string) $locations->findOrCreateClickSiteUrl(
            \Prosper202\Click\TrackingBaseUrl::requestUrl($_SERVER)
        );

        // A click has no session: 0, where '' was refused by the column
        // under P202_SQL_STRICT.
        $mysql['user_id'] = isset($_SESSION['user_id'])
            ? $db->real_escape_string(strip_tags((string) $_SESSION['user_id']))
            : 0;
        $mysql['mysql_error_text'] = $db->real_escape_string($clean['mysql_error_text']);
        $mysql['mysql_error_sql'] = $db->real_escape_string($statement);
        $mysql['mysql_error_time'] = time();

        $report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='" . $mysql['mysql_error_text'] . "',
										mysql_error_sql='" . $mysql['mysql_error_sql'] . "',
										user_id='" . $mysql['user_id'] . "',
										ip_id='" . $mysql['ip_id'] . "',
										site_id='" . $mysql['site_id'] . "',
										mysql_error_time='" . $mysql['mysql_error_time'] . "'";
        // On the connection just rolled back: the global one may be
        // another, with a transaction of its own still open. The server's
        // words about the statement would repeat what it records: its errno
        // says enough.
        if ($db->query($report_sql) === false) {
            throw new \RuntimeException('the server refused the row [errno ' . $db->errno . ']');
        }
    });

    // report error to user and end page
    $p202Admin = htmlspecialchars((string) ($_SERVER['SERVER_ADMIN'] ?? ''), ENT_QUOTES, 'UTF-8');
    printf(
        "\n" . '    <div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i>'
            . '<div class="p202-flash__body"><strong>A database error has occurred, and it %s.</strong>'
            . ' If it keeps happening, email <a href="mailto:%s">%s</a>.</div></div>' . "\n\n\n",
        $recorded ? 'has been recorded' : 'could not be recorded; the server error log has it',
        $p202Admin,
        $p202Admin
    );
    if (!$clickPath) {
        // Without the footer's reads (the dash email, the cron ping's
        // tracking domain): they would ask the database that has just failed.
        template_bottom(false);
    }
    die();
}

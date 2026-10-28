<?php

use UAParser\Parser;
use GeoIp2\Database\Reader;

// Load centralized version configuration
if (!file_exists(__DIR__ . '/version.php')) {
    die('Critical: Version file missing');
}
require_once(__DIR__ . '/version.php');
// Before anything that can fail: the error page and the cached reads are
// there, and they ask p202OnTheClickPath() which bootstrap this is.
define('P202_CLICK_PATH', true);
require_once(__DIR__ . '/mysql-error-args.php');
require_once(__DIR__ . '/functions-shared.php');

/**
 * What the click path answers for a throw nothing caught: a 500, unless a
 * redirect is already queued. dl.php records its click after setting
 * Location, and where the server has no fastcgi_finish_request() nothing has
 * been sent yet: a failure there loses the click, and must not lose the
 * visitor too. The lookups and the click writers report a database failure
 * as a QueryException, which ends at record_mysql_error() as a failed query
 * does, recorded with the line outside the database layer that reached it
 * (every failure there is thrown from the same few lines of Connection).
 * Anything else is logged, with nothing about it shown.
 */
function p202ClickPathUncaught(\Throwable $e): void
{
    $status = http_response_code();
    if (!headers_sent() && !(is_int($status) && $status >= 300 && $status < 400)) {
        http_response_code(500);
    }
    if ($e instanceof \Prosper202\Database\Exceptions\QueryException) {
        record_mysql_error($GLOBALS['db'] ?? null, 'uncaught at ' . p202UncaughtAt($e), $e->getMessage());
    }
    error_log(p202UncaughtLogLine('Uncaught on the click path', $e));
}
set_exception_handler('p202ClickPathUncaught');

$_GET = array_change_key_case($_GET, CASE_LOWER);
//fix for nginx with no server name set
if ($_SERVER['SERVER_NAME'] == '_') {
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
}

DEFINE('ROOT_PATH', substr(__DIR__, 0, -10));
DEFINE('CONFIG_PATH', __DIR__);
//@ini_set('register_globals', 0);
@ini_set('display_errors', 'Off');
@ini_set('error_reporting', 6135);

mysqli_report(MYSQLI_REPORT_STRICT);
include_once(ROOT_PATH . '/202-config.php');

// A refused connection throws under MYSQLI_REPORT_STRICT. Caught, $db is null
// and the redirects fall back to the URL they keep in memcache for an outage
// (dl.php, lp.php, off.php); uncaught, every request was a 500 before any of
// them could.
try {
    $database = DB::getInstance();
    $db = $database->getConnection();
} catch (\mysqli_sql_exception $connectError) {
    error_log('The click path could not connect to the database: ' . $connectError->getMessage());
    $db = null;
}

// The click writes are written for this sql_mode. A session that refuses it
// is one they cannot use: logged, and read as no connection, as an outage is
// (the URL kept for one, or the 503 page), so nothing is written under
// another mode.
if ($db instanceof \mysqli) {
    // Strict when P202_SQL_STRICT is defined and truthy in config; permissive
    // by default, for existing installs.
    $user_sql = defined('P202_SQL_STRICT') && P202_SQL_STRICT
        ? "SET session sql_mode= 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        : "SET session sql_mode= ''";
    if ($db->query($user_sql) === false) {
        p202LogFailedQuery($db, $user_sql, ['file' => __FILE__, 'line' => __LINE__]);
        $db = null;
    }
}

/**
 * The answer to a request the database is needed for and out of reach: the
 * outage in the status, so a client or a monitor never reads it as served.
 */
function p202Unavailable(): never
{
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    die('Service temporarily unavailable');
}

/**
 * End the request with p202Unavailable() when the bootstrap could not
 * connect. A script that answers without the database (it keeps a URL for an
 * outage, or never needs one) sets $p202OutageFallback = true before loading
 * this file; every other one would only reach code that needs it. A script
 * another may load after this file ran calls it itself (offrtr.php, through
 * go.php).
 */
function p202RequireDatabase(?\mysqli $db): void
{
    if (!$db instanceof \mysqli) {
        p202Unavailable();
    }
}

// Read where the script set it: this file's scope is the including script's.
if (($p202OutageFallback ?? null) !== true) {
    p202RequireDatabase($db);
}

$whatCache = false;

// try to connect to memcache server
$memcacheInstalled = false;
global $memcacheWorking;
$memcacheWorking = false;
/** @var \Memcache|\Memcached|null $memcache */
$memcache = null; // Initialize $memcache
$mchost = $mchost ?? '127.0.0.1';

if (extension_loaded('memcache') && class_exists('Memcache')) {
    $whatCache = 'memcache';
    $memcacheInstalled = true;
    /** @var \Memcache $memcache */
    $memcache = new \Memcache();
    if (@$memcache->connect($mchost, 11211)) {
        $memcacheWorking = true;
    } else {
        $memcacheWorking = false;
    }
} elseif (extension_loaded('memcached') && class_exists('Memcached')) {
    $whatCache = 'memcached';
    $memcacheInstalled = true;
    /** @var \Memcached $memcache */
    $memcache = new \Memcached();
    // Note: Corrected method name from addserver to addServer
    if (@$memcache->addServer($mchost, 11211)) {
        $memcacheWorking = true;
    } else {
        $memcacheWorking = false;
    }
} else {
    // Neither extension is loaded or class doesn't exist
    $memcacheInstalled = false;
    $memcacheWorking = false;
}



include_once(CONFIG_PATH . '/functions-auth.php');
include_once(CONFIG_PATH . '/DeviceDetect.php');
include_once(CONFIG_PATH . '/FraudDetectionIPQS.class.php');
require ROOT_PATH . 'vendor/autoload.php';

// Initialize $tid and $db variables to prevent undefined variable errors
if (!isset($tid)) {
    // If $t202id is set (from dl.php), use that for $tid
    if (isset($t202id) && is_numeric($t202id)) {
        $tid = $t202id;
    } else {
        $tid = 1; // Default to user_id 1 if not set
    }
}


// The privacy mode: the install's setting, user 1's row, read through
// memcache_mysql_fetch_assoc(), which caches it for three minutes when
// memcache works. Every account has a setting of its own on Personal
// settings too, and the click's account is known only after this runs, in
// each endpoint: there, p202ApplyOwnerPrivacy() puts the stricter of the
// two in force, and until an endpoint has, trackingEnabled() holds back.
// The app intakes apply the same stricter of the two (PrivacySetting).
//
// With memcache working this used to read account.php's per-account key,
// user_pref_privacy_<id>, under the request's t202id, pci or lpip instead —
// ids account.php never writes a key under. The miss came back false, false
// counted as set, and the database was never asked: every visitor of a
// tracking link was tracked in full, cookies and unmasked address, whatever
// the owner had chosen (executed against the old block with a Memcached
// stand-in; PrivacyBootstrapTest). A pixel's request, which fell back to
// user 1's key, read a value the API's preference update never refreshes.
if (!isset($_SESSION['privacy'])) {

    $user_sql = "	SELECT 	user_pref_privacy
				 FROM   	`202_users_pref`
				 WHERE  	`202_users_pref`.`user_id`='1'";

    try {
        // No connection (an outage): nothing to read, and no second attempt.
        $privacy = $db instanceof \mysqli ? memcache_mysql_fetch_assoc($user_sql) : false;
    } catch (\Prosper202\Database\Exceptions\QueryException $privacyReadError) {
        $privacy = false;
    }
    if ($privacy === false) {
        // The read failed (logged where it failed): hold back rather than
        // track in full, as the app intakes do (PrivacySetting). It answered
        // 'disabled' here — the most permissive setting standing in for one
        // nobody could read (CLAUDE.md #11).
        error_log('p202 privacy: user 1\'s user_pref_privacy could not be read; holding back for this request');
        $_SESSION['privacy'] = 'all';
    } elseif (isset($privacy['user_pref_privacy'])) {
        // A stored value that is not a setting holds back rather than
        // tracking in full (CLAUDE.md #11), as the app intakes read it
        // (PrivacySetting).
        $_SESSION['privacy'] = in_array($privacy['user_pref_privacy'], \Prosper202\Http\PrivacySetting::SETTINGS, true)
            ? $privacy['user_pref_privacy']
            : 'all';
    } else {
        // No preferences row: nothing was set, the column's own default.
        $_SESSION['privacy'] = 'disabled';
    }
}


// The visitor's address: one rule for every click endpoint, read through
// VisitorIp, never from a forwarding header directly (the bootstrap used to
// rewrite $_SERVER['HTTP_X_FORWARDED_FOR'] in place for the endpoints to
// read back, each with its own fallback and none validating it). Masked
// under the install's own setting, not the request's: what reads this
// global is the data engine's by-address fallback (setDirtyHour() with no
// click id), which looks among the first account's clicks, and those are
// stored under the install's setting; and the bot ranges, which a /24 mask
// does not move across.
$ip_address = ipAddress(
    \Prosper202\Http\VisitorIp::fromServer($_SERVER),
    !\Prosper202\Http\PrivacyMode::tracksInFull($_SESSION['privacy'], p202VisitorMayBeInEu(...))
);

/**
 * The visitor's address as every click-path row stores it (StoredVisitorIp):
 * masked when trackingEnabled() is false. The pixels' "this address's last
 * click" lookups take it too, so they compare like with like.
 */
function p202StoredVisitorIp(): string
{
    return \Prosper202\Http\StoredVisitorIp::fromServer($_SERVER, !trackingEnabled());
}

/**
 * Puts in force, for the rest of this request, the privacy setting that
 * governs the visitor of $ownerId's link: the strictest of the install's
 * (the bootstrap's $_SESSION['privacy']) and the account's own
 * (PrivacySetting::forAccount(), which reads both again, uncached, and holds
 * back for a read that fails or a stored value that is not a setting).
 *
 * Every click endpoint calls it as soon as it knows whose click it is —
 * the tracker's, the landing page's, the click's or the campaign's account —
 * and before it stores the visitor's address or sets a cookie
 * (OwnerPrivacyAppliedFirstTest). It read the install's setting alone: an
 * account set to 'all' under an install set to 'disabled' had its visitors
 * stored unmasked and given every click cookie (measured live).
 *
 * A lookup among the first account's clicks (the pixels' and off.php's
 * by-address fallbacks, which only ever look there) applies user 1's, which
 * is the install's: those clicks were stored under it. A later call for
 * another owner replaces the setting in force; the install's is always part
 * of it.
 *
 * An owner that is not a positive integer — a row that names no account —
 * holds back: nothing says which setting governs (CLAUDE.md #11).
 */
function p202ApplyOwnerPrivacy(mixed $ownerId, ?\Prosper202\Database\Connection $conn = null): void
{
    $owner = is_int($ownerId) ? $ownerId : (is_string($ownerId) && ctype_digit($ownerId) ? (int) $ownerId : 0);
    if ($owner <= 0) {
        error_log('p202 privacy: the click names no account (' . var_export($ownerId, true) . '); holding back');
        $GLOBALS['p202PrivacyInForce'] = 'all';

        return;
    }
    if ($conn === null) {
        $db = $GLOBALS['db'] ?? null;
        if (!$db instanceof \mysqli) {
            error_log('p202 privacy: no database to read user ' . $owner . '\'s setting from; holding back');
            $GLOBALS['p202PrivacyInForce'] = 'all';

            return;
        }
        $conn = new \Prosper202\Database\Connection($db);
    }
    $GLOBALS['p202PrivacyInForce'] = \Prosper202\Http\PrivacySetting::strictest(
        $_SESSION['privacy'] ?? 'all',
        \Prosper202\Http\PrivacySetting::forAccount($conn, $owner)
    );
}

/**
 * The privacy setting in force for this request's visitor: what
 * p202ApplyOwnerPrivacy() put in force, or 'all' before any endpoint has
 * said whose click this is. Nothing should ask before then — an error logged
 * that early (record_mysql_error()) is the one place that may — so the first
 * such question is logged with the script that asked: an endpoint that
 * stores an address or sets a cookie without naming the owner first masks
 * the address and sets nothing, rather than tracking in full under a
 * setting that may not be the owner's.
 */
function p202PrivacyInForce(): string
{
    $inForce = $GLOBALS['p202PrivacyInForce'] ?? null;
    if (is_string($inForce)) {
        return $inForce;
    }
    static $logged = false;
    if (!$logged) {
        $logged = true;
        error_log('p202 privacy: asked before the request named whose click this is ('
            . (is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '?') . '); holding back');
    }

    return 'all';
}

/**
 * Whether this visitor may be tracked in full: cookies set, the address
 * stored as it arrived. False under the privacy setting in force
 * (p202PrivacyInForce(): the stricter of the install's and the link owner's)
 * — 'all', or 'eu' for a visitor who may be in the European Union
 * (p202VisitorMayBeInEu()).
 */
function trackingEnabled(): bool
{
    return \Prosper202\Http\PrivacyMode::tracksInFull(p202PrivacyInForce(), p202VisitorMayBeInEu(...));
}

/**
 * The account and campaign of a stored click, for an endpoint that is
 * handed a click id and must apply its owner's privacy setting (go.php's
 * 202v link). Null when there is no such click — and when it cannot be read,
 * logged: the one caller sets no cookie for either, the conservative answer,
 * so the two need not be told apart there.
 *
 * @return array{user_id: int, aff_campaign_id: int}|null
 */
function p202ClickOwner(int $clickId): ?array
{
    $db = $GLOBALS['db'] ?? null;
    if ($clickId <= 0 || !$db instanceof \mysqli) {
        return null;
    }
    try {
        $conn = new \Prosper202\Database\Connection($db);
        $stmt = $conn->prepareRead(
            'SELECT c.user_id, c.aff_campaign_id FROM ' . \Prosper202\Click\LatestVisit::of('?') . ' AS c'
        );
        $conn->bind($stmt, 'i', [$clickId]);
        $row = $conn->fetchOne($stmt);
    } catch (\Throwable $e) {
        error_log('p202ClickOwner: click ' . $clickId . ' could not be read: ' . $e->getMessage());

        return null;
    }

    if ($row === null) {
        return null;
    }

    return ['user_id' => (int) $row['user_id'], 'aff_campaign_id' => (int) $row['aff_campaign_id']];
}

/**
 * The identity signals a click request carries (ClickIdentity::fromRequest())
 * under the privacy setting in force: a visitor held back has no p202vid
 * read, minted or sent, as they are set no click cookie. It was set under
 * every setting.
 *
 * @param array<string, mixed> $get
 */
function p202ClickIdentity(array $get, bool $campaignAllows, bool $mayMint = true): \Prosper202\Identity\ClickIdentity
{
    $inFull = trackingEnabled();

    return \Prosper202\Identity\ClickIdentity::fromRequest(
        $get,
        $inFull ? $_COOKIE : [],
        $campaignAllows,
        $mayMint && $inFull
    );
}

/**
 * The script statements that set click cookies on the landing page's own
 * site — record_simple.php and record_adv.php answer the page's script with
 * them, through landing.php's createCookie() — or none when the visitor is
 * held back, as setClickIdCookie() sets none on the tracker's. They were
 * written into the response under every setting.
 *
 * @param array<string, string> $cookies name => value
 */
function p202ClickCookieJs(array $cookies): string
{
    if (!trackingEnabled()) {
        return '';
    }
    $js = '';
    foreach ($cookies as $name => $value) {
        $arguments = json_encode([(string) $name, (string) $value]);
        if ($arguments === false) {
            error_log('p202 click cookies: ' . $name . ' could not be written into the script; not set');
            continue;
        }
        $js .= 'createCookie(' . substr($arguments, 1, -1) . ',0);' . "\n";
    }

    return $js;
}

/**
 * Whether privacy 'eu' applies to this visitor: unless the GeoIP lookup of
 * their address places them outside Europe and outside the European Union,
 * it does (EuropeanVisitor, which the app intakes ask too).
 *
 * The check used to read $_SESSION['is_european_union'], which nothing ever
 * set: every request under 'eu' raised an undefined-key warning and tracked
 * EU visitors in full — cookies set, the address stored — which is what the
 * setting promises not to do. Only a positive "outside Europe, not in the
 * EU" lifts privacy: an address GeoIP cannot place, one in a European
 * country outside the EU, and everyone when the GeoIP library or database
 * is missing are held back (PrivacyMode::mayBeEuropean() says why). Asked
 * once per request.
 */
function p202VisitorMayBeInEu(): bool
{
    static $mayBe = null;
    if ($mayBe === null) {
        $mayBe = \Prosper202\Http\EuropeanVisitor::mayBe(\Prosper202\Http\VisitorIp::fromServer($_SERVER));
    }

    return $mayBe;
}

class FILTER
{

    /**
     * Whether to record this click as filtered (1) or not (0).
     *
     * The sign-in and netrange checks compare the address the click arrived
     * from (VisitorIp), read here rather than handed in: a caller that
     * passed the address the click path stores — masked under privacy —
     * would match no sign-in and no netrange. Every caller passed one
     * argument more before; StoredVisitorIpSourceTest holds them to four.
     *
     * @param int|string $ip_id the click's stored address row (masked under
     *        privacy): what the duplicate check keeps, the only one it may
     */
    public static function startFilter($db, $click_id, $ip_id, $user_id)
    {
        $arrived = \Prosper202\Http\VisitorIp::fromServer($_SERVER);

        // we only do the other checks, if the first ones have failed.
        // we will return the variable filter, if the $filter returns TRUE, when the click is inserted and recorded we will insert the new click already inserted,
        // what was lagign this query is before it would insert a click, then scan it and then update the click, the updating later on was lagging, now we will just insert and it will not stop the clicks from being redirected becuase of a slow update.

        // check the user
        $filter = FILTER::checkUserIP($db, $arrived);
        if ($filter == false) {

            // check the netrange
            $filter = FILTER::checkNetrange($click_id, $arrived);
            if ($filter == false) {

                $filter = FILTER::checkLastIps($db, $user_id, $ip_id);
            }
        }

        if ($filter == true) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * Whether a user last signed in from the address this click arrived from
     * (SignedInFromAddress): the owner's own clicks are filtered.
     *
     * It compares the address as it arrived, not the click's stored ip_id.
     * The sign-in address is stored unmasked under every privacy setting, the
     * click's masked under privacy, so the ip_id comparison this replaced
     * never matched there and the owner's clicks were counted.
     *
     * @param string|object $arrived VisitorIp's address (or ipAddress()'s object of it)
     */
    public static function checkUserIP($db, $arrived)
    {
        $address = is_object($arrived) ? (string) ($arrived->address ?? '') : (string) $arrived;

        return \Prosper202\Click\SignedInFromAddress::any(new \Prosper202\Database\Connection($db), $address);
    }

    public static function checkNetrange($click_id, $ip)
    {
        $ip_address = ip2long(is_string($ip) ? $ip : $ip->address);

        // check each netrange
        /* google1 */
        if (($ip_address >= 1208926208) and ($ip_address <= 1208942591)) {
            return true;
        }
        /* google2 */
        if (($ip_address >= 3512041472) and ($ip_address <= 3512074239)) {
            return true;
        }
        /* google3 */
        if (($ip_address >= 1123631104) and ($ip_address <= 1123639295)) {
            return true;
        }
        /* Google4 */
        if (($ip_address >= 1089052672) and ($ip_address <= 1089060863)) {
            return true;
        }
        /* google5 */
        if (($ip_address >= -782925824) and ($ip_address <= -782893057)) {
            return true;
        }

        /* level 3 communications */
        if (($ip_address >= 1094189056) and ($ip_address <= 1094451199)) {
            return true;
        }

        /* yahoo1 */
        if (($ip_address >= 3515031552) and ($ip_address <= 3515039743)) {
            return true;
        }
        /* Yahoo2 */
        if (($ip_address >= 3633393664) and ($ip_address <= 3633397759)) {
            return true;
        }
        /* Yahoo3 */
        if (($ip_address >= 3640418304) and ($ip_address <= 3640426495)) {
            return true;
        }
        /* Yahoo4 */
        if (($ip_address >= 1209925632) and ($ip_address <= 1209991167)) {
            return true;
        }
        /* Yahoo5 */
        if (($ip_address >= 1241907200) and ($ip_address <= 1241972735)) {
            return true;
        }

        /* Performance Systems International Inc. */
        if (($ip_address >= 637534208) and ($ip_address <= 654311423)) {
            return true;
        }
        /* Microsoft */
        if (($ip_address >= 3475898368) and ($ip_address <= 3475963903)) {
            return true;
        }
        /* MSN */
        if (($ip_address >= 1093926912) and ($ip_address <= 1094189055)) {
            return true;
        }

        // if it was none of theses, return false
        return false;
    }

    // this will filter out a click if it the IP WAS RECORDED, for a particular user within the last 24 hours, if it existed before, filter out this click.
    //
    // It keys on the click's stored ip_id, both when it looks and when it
    // records (202_last_ips keeps it for a day), so under privacy both sides
    // are the masked address and every visitor in one /24 (/48) is one
    // visitor: the second of two neighbours within the day is filtered.
    // Measured live. That is the price of the setting rather than a mismatch
    // — this check needs a memory of earlier visitors, and the masked address
    // is the only one privacy lets it keep. Unlike checkUserIP, whose other
    // side is an operator's unmasked record, there is nothing unmasked here
    // to compare before the mask.
    public static function checkLastIps($db, $user_id, $ip_id)
    {
        $conn = new \Prosper202\Database\Connection($db);
        $stmt = $conn->prepareWrite('SELECT 1 FROM 202_last_ips WHERE user_id = ? AND ip_id = ? LIMIT 1');
        $conn->bind($stmt, 'ii', [(int) $user_id, (int) $ip_id]);
        if ($conn->fetchOne($stmt) !== null) {
            // if this ip has been seen within the last 24 hours, filter it out.
            return true;
        }

        // else if this ip has not been recorded, record it now
        $stmt = $conn->prepareWrite('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (?, ?, ?)');
        $conn->bind($stmt, 'iii', [(int) $user_id, (int) $ip_id, time()]);
        $conn->executeInsert($stmt);
        return false;
    }
}



/**
 * Whether a rotator rule's redirect row can take a click, in rtr.php and
 * offrtr.php alike. One naming a campaign or landing page that the joins,
 * tied to the account, did not find (deleted, or another account's: CLAUDE.md
 * #27) names nothing, and so does a row with no destination at all.
 *
 * @param array<string, mixed> $redirect a redirect row and its joins
 *
 * @return bool whether it names a destination of the account's
 */
function p202RuleRedirectIsUsable(array $redirect): bool
{
    if (!empty($redirect['redirect_campaign'])) {
        return !empty($redirect['aff_campaign_id']);
    }
    if (!empty($redirect['redirect_lp'])) {
        return !empty($redirect['landing_page_id']);
    }

    return !empty($redirect['redirect_url'])
        || ($redirect['auto_monetizer'] ?? null) != null;
}
function rotateTrackerUrl($db, $tracker_row)
{


    if (! $tracker_row['aff_campaign_rotate'])
        return $tracker_row['aff_campaign_url'];

    $urls = [];
    array_push($urls, $tracker_row['aff_campaign_url']);

    if ($tracker_row['aff_campaign_url_2'])
        array_push($urls, $tracker_row['aff_campaign_url_2']);
    if ($tracker_row['aff_campaign_url_3'])
        array_push($urls, $tracker_row['aff_campaign_url_3']);
    if ($tracker_row['aff_campaign_url_4'])
        array_push($urls, $tracker_row['aff_campaign_url_4']);
    if ($tracker_row['aff_campaign_url_5'])
        array_push($urls, $tracker_row['aff_campaign_url_5']);

    $count = count($urls);

    // Atomic upsert to avoid TOCTOU race condition on concurrent requests
    $conn = new \Prosper202\Database\Connection($db);
    $stmt = $conn->prepareWrite(
        'INSERT INTO 202_rotations SET aff_campaign_id = ?, rotation_num = 0'
            . ' ON DUPLICATE KEY UPDATE rotation_num = IF(rotation_num >= ?, 0, rotation_num + 1)'
    );
    $conn->bind($stmt, 'ii', [(int) $tracker_row['aff_campaign_id'], $count - 1]);
    $conn->executeUpdate($stmt);
    // Read back the current value
    $stmt = $conn->prepareWrite('SELECT rotation_num FROM 202_rotations WHERE aff_campaign_id = ?');
    $conn->bind($stmt, 'i', [(int) $tracker_row['aff_campaign_id']]);
    $row5 = $conn->fetchOne($stmt);
    $num = $row5 !== null ? (int) $row5['rotation_num'] : 0;

    return $urls[$num];
}

/**
 * The URL tokens of one click, from the values it is recorded with: what
 * replaceTrackerPlaceholders() reads back from the click's rows, or what an
 * endpoint that redirects before writing them holds (dl.php,
 * record_simple.php). Values are raw; replaceTokens() URL-encodes them.
 *
 * @param array<string, scalar|null> $click keyword, t202pubid, c1-c4, gclid,
 *     msclkid, fbclid, utm_source, utm_medium, utm_campaign, utm_term,
 *     utm_content, country, country_code, region, city, cpc, cpa, payout,
 *     ppc_account_id
 * @return array<string, scalar|null>
 */
function p202ClickUrlTokens(string $clickId, array $click): array
{
    $text = static fn (string $key): string => (string) ($click[$key] ?? '');
    $account = $text('ppc_account_id');
    // A click with no CPA (click_cpa is NULL: not a CPA tracker) has none, and
    // no click read back has no CPC either: round() would make each a 0 that
    // reads as one. A stored click always has a CPC, 0 when it has no cost.
    $cpc = $text('cpc') === '' ? '' : round((float) $text('cpc'), 2);
    $cpa = $text('cpa') === '' ? '' : round((float) $text('cpa'), 2);
    // The campaign's payout (2 places) and the click's (5) as one number.
    $payout = $text('payout');
    if (is_numeric($payout) && str_contains($payout, '.')) {
        $payout = rtrim(rtrim($payout, '0'), '.');
    }

    return [
        'subid' => $clickId,
        't202kw' => $text('keyword'),
        't202pubid' => $text('t202pubid'),
        'c1' => $text('c1'),
        'c2' => $text('c2'),
        'c3' => $text('c3'),
        'c4' => $text('c4'),
        'gclid' => $text('gclid'),
        'msclkid' => $text('msclkid'),
        'fbclid' => $text('fbclid'),
        'utm_source' => $text('utm_source'),
        'utm_medium' => $text('utm_medium'),
        'utm_campaign' => $text('utm_campaign'),
        'utm_term' => $text('utm_term'),
        'utm_content' => $text('utm_content'),
        'country' => $text('country'),
        'country_code' => $text('country_code'),
        'region' => $text('region'),
        'city' => $text('city'),
        'cpc' => $cpc,
        'cpc2' => $text('cpc'),
        'cpa' => $cpa,
        'timestamp' => time(),
        'payout' => $payout,
        'random' => mt_rand(1000000, 9999999),
        // Encoded here and again by replaceTokens(), as it always has been.
        'referer' => urlencode((string) ($_SERVER['HTTP_REFERER'] ?? '')),
        'sourceid' => $account === '0' ? '' : $account,
    ];
}

/**
 * Fill a URL's [[tokens]] from one click's values (p202ClickUrlTokens()); a
 * token they leave empty, or one no click has, comes out empty.
 *
 * @param array<string, scalar|null> $click
 */
function replaceClickPlaceholders($url, string $clickId, array $click): string
{
    return replaceTokens($url, p202ClickUrlTokens($clickId, $click), 1);
}

/**
 * Fill a URL's [[tokens]] from the click's rows, read back by its id: for an
 * endpoint that has written them (rtr.php inside its writer, a landing page's
 * offer link on its click). One that redirects first hands the values it
 * holds to replaceClickPlaceholders(): read back, they are not there yet.
 *
 * $ownerId is the account whose URL this is: the click is read only if it
 * is that account's. Several callers take the click id from the visitor
 * (lpc.php from a cookie), and its row carries another account's cost,
 * payout and values (CLAUDE.md #27).
 */
function replaceTrackerPlaceholders($db, $url, $click_id, int $ownerId)
{
    // The tokens no click row is needed for first, so a URL that asks for
    // nothing else (the common [[subid]]) is filled without the read below.
    $url = replaceTokens($url, array_intersect_key(
        p202ClickUrlTokens((string) $click_id, []),
        array_flip(['subid', 'timestamp', 'random', 'referer'])
    ));
    if (!preg_match('/\[\[(.*)\]\]/', (string) $url)) {
        return $url;
    }

    // Anything but a click id names no click: its tokens are blank, as for a
    // click not there (compared as text, `12abc` read click 12).
    $tokenClickId = \Prosper202\Click\ClickId::parse((string) $click_id);
    if ($tokenClickId === null) {
        return replaceClickPlaceholders($url, (string) $click_id, []);
    }
    $mysql['click_id'] = (string) $tokenClickId;
    // The click's latest visit: a re-click adds a row. Named in the statement
    // itself, so SingleClickReadsNameTheirVisitTest can see it.
    $click_sql = "
			SELECT 2c.click_id, 
                2tc1.c1, 
                2tc2.c2, 
                2tc3.c3, 
                2tc4.c4,	
                2kw.keyword,
            	2c.click_payout,
            	2c.click_cpc,
            	2trk.click_cpa,
                2c.ppc_account_id,
            	2g.gclid,
                2b.msclkid,
                2f.fbclid,
            	2us.utm_source,
            	2um.utm_medium,
            	2uca.utm_campaign,
            	2ut.utm_term,
            	2uco.utm_content,
		        2lc.country_name,
		        2lc.country_code,
		        2lr.region_name,
                2u.user_public_publisher_id,
		        2lc2.city_name FROM " . \Prosper202\Click\LatestVisit::of($mysql['click_id']) . " AS 2c 
            	LEFT JOIN `202_clicks_advance` AS 2ca USING (`click_id`) 
            	LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2ct.click_id = 2c.click_id) 
            	LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id) 
            	LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id) 
            	LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id) 
            	LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id) 
                LEFT OUTER JOIN 202_cpa_trackers AS 2cpa ON (2cpa.click_id = 2c.click_id)
                LEFT OUTER JOIN 202_trackers AS 2trk
                    ON (2trk.tracker_id_public = 2cpa.tracker_id_public AND 2trk.user_id = 2c.user_id)
            	LEFT JOIN `202_google` AS 2g on (2g.click_id=2c.click_id)
                LEFT JOIN `202_bing` AS 2b on (2b.click_id=2c.click_id)
                LEFT JOIN `202_facebook` AS 2f on (2f.click_id=2c.click_id) 
                LEFT JOIN `202_utm_source` AS 2us ON (2g.utm_source_id = 2us.utm_source_id)
                LEFT JOIN `202_utm_medium` AS 2um ON (2g.utm_medium_id = 2um.utm_medium_id)
                LEFT JOIN `202_utm_campaign` AS 2uca ON (2g.utm_campaign_id = 2uca.utm_campaign_id) 
                LEFT JOIN `202_utm_term` AS 2ut ON (2g.utm_term_id = 2ut.utm_term_id)
                LEFT JOIN `202_utm_content` AS 2uco ON (2g.utm_content_id = 2uco.utm_content_id)
                LEFT JOIN `202_keywords` AS 2kw ON (2ca.`keyword_id` = 2kw.`keyword_id`) 
		        LEFT JOIN `202_locations_country` AS 2lc ON (2ca.`country_id` = 2lc.`country_id`)
		        LEFT JOIN `202_locations_region` AS 2lr ON (2ca.`region_id` = 2lr.`region_id`)    
		        LEFT JOIN `202_locations_city` AS 2lc2 ON (2ca.`city_id` = 2lc2.`city_id`)
                LEFT JOIN `202_users` AS 2u ON (2u.`user_id` = 2c.`user_id`)
		    WHERE
				2c.click_id='" . $mysql['click_id'] . "'
                AND 2c.user_id='" . $ownerId . "'
		";
    // _mysqli_query() answers false for a failed statement, and the fetch
    // false for a failed read: either is recorded, and the error page.
    $failed = 'Reading click ' . $mysql['click_id'] . ' for its URL tokens failed';
    $click_result = _mysqli_query($db, $click_sql);
    if ($click_result === false) {
        record_mysql_error($db, $click_sql, $failed);
    }
    $click_row = $click_result->fetch_assoc();
    if ($click_row === false) {
        record_mysql_error($db, $click_sql, $failed);
    }
    // No such click (a page reading an earlier one by a subid it was sent):
    // its tokens are blank, as they always were.
    $click_row ??= [];

    return replaceClickPlaceholders($url, (string) $click_id, [
        'keyword' => $click_row['keyword'] ?? null,
        't202pubid' => $click_row['user_public_publisher_id'] ?? null,
        'c1' => $click_row['c1'] ?? null,
        'c2' => $click_row['c2'] ?? null,
        'c3' => $click_row['c3'] ?? null,
        'c4' => $click_row['c4'] ?? null,
        'gclid' => $click_row['gclid'] ?? null,
        'msclkid' => $click_row['msclkid'] ?? null,
        'fbclid' => $click_row['fbclid'] ?? null,
        'utm_source' => $click_row['utm_source'] ?? null,
        'utm_medium' => $click_row['utm_medium'] ?? null,
        'utm_campaign' => $click_row['utm_campaign'] ?? null,
        'utm_term' => $click_row['utm_term'] ?? null,
        'utm_content' => $click_row['utm_content'] ?? null,
        'country' => $click_row['country_name'] ?? null,
        'country_code' => $click_row['country_code'] ?? null,
        'region' => $click_row['region_name'] ?? null,
        'city' => $click_row['city_name'] ?? null,
        'cpc' => $click_row['click_cpc'] ?? null,
        'cpa' => $click_row['click_cpa'] ?? null,
        'payout' => $click_row['click_payout'] ?? null,
        'ppc_account_id' => $click_row['ppc_account_id'] ?? null,
    ]);
}

function setClickIdCookie($click_id, $campaign_id = 0)
{
    if (trackingEnabled()) {
        //set the cookie for the PIXEL to fire, expire in 30 days
        $expire = time() + (60 *  60 * 24 * 30);
        $expire_header = 60 *  60 * 24 * 30;
        $path = '/';
        // Domain: the request host without its port, none for an IP (CookieDomain).
        $secure = TRUE;
        $httponly = FALSE; // JS createCookie()/readCookie() must access these cookies

        //legacy cookies

        setcookie('tracking202subid-legacy', (string) $click_id, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER)]);
        setcookie('tracking202subid_a_' . $campaign_id . '-legacy', (string) $click_id, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER)]);

        setcookie('tracking202subid', (string) $click_id,  ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => $httponly, 'samesite' => 'None']);
        setcookie('tracking202subid_a_' . $campaign_id, (string) $click_id,   ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => $httponly, 'samesite' => 'None']);

        // The install-token proof (InstallTokenGrant): set only by the
        // request that allocated the click, so a later lp.php can sign the
        // click the visitor really has and no other. httponly, unlike the
        // click cookies above: no script needs it, and none may set it.
        // A missing or unreadable key sets nothing; the redirect then
        // expands the token empty, which credits no one.
        if (\Prosper202\Click\RecordedClicks::has($click_id)) {
            try {
                $installKey = p202InstallTokenKey();
            } catch (\Throwable) {
                $installKey = null;
            }
            if (is_string($installKey) && strlen($installKey) === 32) {
                $proof = \Api\V3\Apps\Android\InstallToken::forClick((int) $click_id, $installKey);
                $proofCookie = \Api\V3\Apps\Android\InstallTokenGrant::PROOF_COOKIE;
                setcookie($proofCookie, $proof, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => true, 'samesite' => 'None']);
                setcookie($proofCookie . '_a_' . $campaign_id, $proof, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => true, 'samesite' => 'None']);
            }
        }
    }
}

class PLATFORMS
{

    /**
     * The device for this click: the user agent's parse, cached per agent,
     * then this request's address verdict. botCheck() judges the address, so
     * its answer must stay out of an entry keyed by the agent, or the first
     * click from a listed range marks every later visitor with that agent as
     * a bot for as long as the entry lives.
     */
    public static function get_device_info($db, $detect, $ua_string = '')
    {
        global $memcacheWorking, $memcache;
        if (!$detect instanceof DeviceDetect) {
            $detect = new DeviceDetect();
        }

        if ($ua_string != '') {
            $detect->setUserAgent($ua_string);
        }
        $ua = $detect->getUserAgent();

        if ($memcacheWorking) {
            // Not "user-agent": entries under that subject name a device
            // model found by its name alone, of whatever type clicked first.
            // The code's version is in the namespace: the parse that makes
            // the entry is this code's, and it lives 30 days.
            $key = md5("user-agent-typed" . $ua . \Prosper202\Repository\LookupRepositoryFactory::cacheNamespace());
            $device_info = $memcache->get($key);

            // A miss as well: an entry without ua_device_family
            // (ClickBotDetector would parse the agent again), or without
            // ua_only, the mark of a parse with no address verdict in it
            // (another visitor's type 4).
            if (! is_array($device_info)
                || ! array_key_exists('ua_device_family', $device_info)
                || ! array_key_exists('ua_only', $device_info)
            ) {
                $device_info = PLATFORMS::parseUserAgentInfo($db, $detect);
                setCache($key, $device_info);
            }
        } else {
            $device_info = PLATFORMS::parseUserAgentInfo($db, $detect);
        }

        return PLATFORMS::applyAddressVerdict($db, $device_info);
    }

    /**
     * Type 4 and the "Bot" device model when this request's address is in a
     * range botCheck() lists; the user agent's parse unchanged otherwise.
     */
    public static function applyAddressVerdict($db, array $device_info): array
    {
        global $ip_address;

        if (!isset($ip_address)) {
            $ip_address = \Prosper202\Http\VisitorIp::fromServer($_SERVER);
        }
        if (!$ip_address || !PLATFORMS::botCheck($ip_address)) {
            return $device_info;
        }

        $bot = \Prosper202\Repository\DeviceType::Bot;
        $device_info['device'] = \Prosper202\Repository\LookupRepositoryFactory::device($db)
            ->findOrCreateDevice('Bot', $bot);
        $device_info['type'] = (string) $bot->value;

        return $device_info;
    }

    /**
     * What the user agent alone says, as get_device_info() caches it per
     * agent: nothing here may depend on the request's address.
     */
    public static function parseUserAgentInfo($db, $detect)
    {
        $parser = Parser::create();
        $userAgent = $detect->getUserAgent() ?: '';
        $result = $parser->parse($userAgent);
        // Before the rewrite to Desktop/Bot below; ClickBotDetector reads it.
        $uaDeviceFamily = (string) $result->device->family;

        $device = $uaDeviceFamily;
        if ($detect->isTablet()) {
            $type = \Prosper202\Repository\DeviceType::Tablet;
        } elseif ($detect->isMobile()) {
            $type = \Prosper202\Repository\DeviceType::Mobile;
        } elseif ($uaDeviceFamily === 'Bot') {
            $type = \Prosper202\Repository\DeviceType::Bot;
        } else {
            $type = \Prosper202\Repository\DeviceType::Desktop;
            $device = 'Desktop';
        }

        $devices = \Prosper202\Repository\LookupRepositoryFactory::device($db);

        return [
            'browser' => $devices->findOrCreateBrowser((string) $result->ua->family),
            'platform' => $devices->findOrCreatePlatform((string) $result->os->family),
            'device' => $devices->findOrCreateDevice($device, $type),
            'type' => (string) $type->value,
            'ua_device_family' => $uaDeviceFamily,
            // Marks an entry with no address verdict in it; get_device_info()
            // treats a cached entry without it as a miss.
            'ua_only' => true,
        ];
    }

    /**
     * Whether the address is in a listed bot range. Takes the address as
     * ipAddress() returns it (an object with ->address) or as the plain string
     * VisitorIp::fromServer() returns, which rtr.php keeps in $ip_address: read
     * only as an object, a string answered "not a bot" on every rotator click.
     */
    public static function botCheck($ip)
    {
        global $memcacheWorking, $memcache;

        if (is_string($ip)) {
            $address = $ip;
            $ip = new stdClass();
            $ip->address = $address;
        }

        // The ranges below are this code's: its version is in the namespace.
        $ipBotKey = $ip && isset($ip->address)
            ? md5("ip-bot" . $ip->address . \Prosper202\Repository\LookupRepositoryFactory::cacheNamespace())
            : null;
        if ($memcacheWorking && $ipBotKey !== null) {
            $getFromCache = $memcache->get($ipBotKey);
        } else {
            $getFromCache = false;
        }

        if (!$getFromCache) {

            $ranges = [
                '199.60.28.0/24',
                '199.103.122.0/24',
                '192.197.157.0/24',
                '207.68.128.0/18',
                '157.54.0.0/15',
                '157.56.0.0/14',
                '157.60.0.0/16',
                '70.32.128.0/19',
                '172.253.0.0/16',
                '173.194.0.0/16',
                '209.85.128.0/17',
                '72.14.192.0/18',
                '66.249.64.0/19',
                '108.177.0.0/17',
                '64.233.160.0/19',
                '66.102.0.0/20',
                '216.239.32.0/19',
                '203.208.60.0/24',
                '66.249.64.0/19',
                '72.14.199.0/24',
                '209.85.238.0/24',
                '204.236.235.245',
                '75.101.186.145',
                '31.13.97.0/24',
                '31.13.99.0/24',
                '31.13.100.0/24',
                '66.220.144.0/20',
                '69.63.189.0/24',
                '69.63.190.0/24',
                '69.171.224.0/20',
                '69.171.240.0/21',
                '69.171.248.0/24',
                '173.252.73.0/24',
                '173.252.74.0/24',
                '173.252.77.0/24',
                '173.252.100.0/22',
                '173.252.104.0/21',
                '173.252.112.0/24',
                '17.0.0.0/8',
                '157.55.39.0/24'
            ];

            foreach ($ranges as $value) {
                if (PLATFORMS::check_ip_range($ip, $value)) {
                    if ($memcacheWorking && $ipBotKey !== null) {
                        setCache($ipBotKey, true);
                    }

                    return true;
                }
            }

            return false;
        }

        return true;
    }

    public static function check_ip_range($ip, $range)
    {
        // Check if IP object and address property exist
        if (!$ip || !isset($ip->address) || empty($ip->address)) {
            return false;
        }
        
        if (!str_contains((string) $range, '/')) {
            $range .= '/32';
        }
        [$range, $netmask] = explode('/', (string) $range, 2);
        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip->address);
        $wildcard_decimal = 2 ** (32 - (int)$netmask) - 1;
        $netmask_decimal = ~$wildcard_decimal;
        return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
    }
}

/**
 * The installation's install-token key: 32 bytes, null when none was minted.
 * Read once per process, and kept only when it was read: a missing or
 * unreadable key is asked for again next time, never cached as "none".
 *
 * @throws \RuntimeException when there is no database or the read fails
 */
function p202InstallTokenKey(): ?string
{
    static $key = null;
    if ($key === null) {
        $db = $GLOBALS['db'] ?? null;
        if (!($db instanceof mysqli) && class_exists('DB')) {
            $db = DB::getInstance()->getConnection();
        }
        if (!($db instanceof mysqli)) {
            throw new \RuntimeException('no database connection');
        }
        $key = \Api\V3\Apps\Android\InstallTokenKey::load($db);
    }

    return $key;
}

/**
 * `[[p202_install_token]]` for a click (plan §5.1): the click id signed with
 * the installation's install-token key, `<click_id>.<mac>`, which a Google
 * Play store link carries in `referrer=p202%3D[[p202_install_token]]` to the
 * Android intake — but only for a click this request allocated, or one whose
 * token the visitor already holds in the httponly proof cookie the recording
 * request set (setClickIdCookie()).
 *
 * lp.php, lpc.php, off.php, offrtr.php and the pixel endpoints take their
 * click id from a cookie or a query parameter the requester chooses, and
 * click ids are sequential, so signing that value would sign any click an
 * attacker names — and pay its install to the attacker (CLAUDE.md #16). See
 * Api\V3\Apps\Android\InstallTokenGrant.
 *
 * Expands EMPTY — never to the bare click id — for such a click, when the
 * click id is not a canonical positive integer (the fallback redirect's
 * "p202", a cloaked placeholder), or when the key is missing or unreadable:
 * the install is then recorded unattributed rather than attributable to a
 * guessable click (CLAUDE.md #11, #16).
 */
function p202ProvenInstallToken($clickId): string
{
    return \Api\V3\Apps\Android\InstallTokenGrant::forRequest(
        $clickId,
        \Prosper202\Click\RecordedClicks::has($clickId),
        $_COOKIE,
        'p202InstallTokenKey'
    );
}

function replaceTokens($url, $tokens = [], $fillblanks = 0)
{
    // The install token is computed from the RAW click id, through the
    // proof check (p202ProvenInstallToken), only when the
    // URL asks for it, and handed to the one implementation as a token of
    // its own. Its alphabet (digits, ".", base64url) is unchanged by the
    // encoding there. (PR 5; the replacement itself is TrafficSourcePixels'.)
    $tokens = is_array($tokens) ? $tokens : [];
    if (!isset($tokens['p202_install_token']) && stripos((string) $url, '[[p202_install_token]]') !== false) {
        if (isset($tokens['subid'])) {
            $tokens['p202_install_token'] = p202ProvenInstallToken($tokens['subid']);
        } elseif ($fillblanks) {
            $tokens['p202_install_token'] = '';
        }
    }

    // One implementation for the tracker's URLs and the traffic-source
    // pixels alike, so a path that never loads this file (POST /events,
    // the goal notifier) replaces tokens exactly as the tracker does.
    return \Prosper202\Conversion\TrafficSourcePixels::replaceTokens($url, $tokens, $fillblanks ? 1 : 0);
}

function getGeoData($ip)
{
    // Handle both string and object input
    if (is_string($ip)) {
        $ip_address = $ip;
    } else {
        $ip_address = $ip->address ?? '';
    }

    // Check if GeoIP2 Reader class exists
    if (!class_exists(\GeoIp2\Database\Reader::class)) {
        // Fallback to legacy method
        return [
            'country' => '',
            'country_code' => '',
            'is_european_union' => false,
            'continent' => '',
            'city' => '',
            'region' => '',
            'region_code' => '',
            // The key every other answer carries (landing.php reads it): this
            // one said 'postal', so without the GeoIP library every landing
            // page script warned "Undefined array key".
            'postal_code' => ''
        ];
    }

    // Opened per lookup, so the lookup after a refresh's rename reads the new file.
    $reader = new Reader(\Prosper202\GeoIp\CityDatabase::PATH);
    try {
        $record = $reader->city($ip_address);
        $country = $record->country->name;
        $country_code = $record->country->isoCode;
        $is_european_union = $record->country->isInEuropeanUnion;
        $continent = $record->continent->name;
        $city = $record->city->name;
        $region = $record->mostSpecificSubdivision->name;
        $region_code = $record->mostSpecificSubdivision->isoCode;
        $postal = $record->postal->code;
    } catch (\Exception) {
        $record = '';
        $country = '';
        $country_code = '';
        $is_european_union = false;
        $continent = '';
        $city = '';
        $region = '';
        $region_code = '';
        $postal = '';
    }



    if ($record != "null") {
        if ($country == null) {
            $country = "Unknown country";
            $country_code = "non";
        }

        if ($is_european_union == null) {
            $is_european_union = "Unknown";

            //sometime continent is known as not Europe but EU status is still set as unknown
            if ($continent != null && $continent != 'Europe') {
                $is_european_union = false;
            }
        }

        if ($continent == null) {
            $continent = "Unknown continent";
        }

        if ($city == null) {
            $city = "Unknown city";
        }

        if ($region == null) {
            $region = "Unknown region";
            $region_code = 'non';
        }

        if ($postal == null) {
            $postal = "Unknown postal code";
        }
    }

    $geoData = [
        'country' => $country,
        'country_code' => $country_code,
        'is_european_union' => $is_european_union,
        'continent' => $continent,
        'region' => $region,
        'region_code' => $region_code,
        'city' => $city,
        'postal_code' => $postal
    ];

    $reader->close();

    return $geoData;
}

function getIspData($ip)
{
    $ip_address = is_string($ip) ? $ip : ($ip->address ?? '');

    if ($ip_address === '') {
        return "Unknown ISP/Carrier";
    }

    // The ISP databases are user-uploaded (not shipped with the repo), so on
    // immutable container deployments P202_GEO_DIR points them at a persistent
    // volume instead of the ephemeral docroot. The shipped GeoLite2-City.mmdb
    // stays under CONFIG_PATH on purpose — it updates with the image.
    $geo_dir = getenv('P202_GEO_DIR') ?: CONFIG_PATH . '/geo';

    // Try GeoIP2 ISP database (.mmdb) first
    $mmdb_file = $geo_dir . '/GeoIP2-ISP.mmdb';
    if (file_exists($mmdb_file)) {
        try {
            $reader = new Reader($mmdb_file);
            $record = $reader->isp($ip_address);
            $isp = $record->isp ?: ($record->organization ?: 'Unknown ISP/Carrier');
            $reader->close();
            return $isp;
        } catch (\Exception) {
            // Fall through to legacy .dat fallback
        }
    }

    // Legacy GeoIPISP.dat fallback via PHP geoip extension
    $dat_file = $geo_dir . '/GeoIPISP.dat';
    if (file_exists($dat_file) && function_exists('geoip_org_by_name')) {
        $isp = @geoip_org_by_name($ip_address);
        if ($isp) {
            return $isp;
        }
    }

    return "Unknown ISP/Carrier";
}

function setPCIdCookie($click_id_public)
{
    if (trackingEnabled()) {
        //set the cookie for the PIXEL to fire, expire in 30 days
        $expire = 0;
        $expire_header = 0;
        $path = '/';
        // Domain: the request host without its port, none for an IP (CookieDomain).
        $secure = TRUE;
        $httponly = FALSE; // JS createCookie() sets this cookie in record_adv.php

        //legacy cookies
        setcookie('tracking202pci-legacy', (string) $click_id_public, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER)]);

        setcookie('tracking202pci', (string) $click_id_public, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => $httponly, 'samesite' => 'None']);
    }
}

function setOutboundCookie($outbound_site_url)
{
    if (trackingEnabled()) {
        //set the cookie for the PIXEL to fire, expire in 30 days
        $expire = 0;
        $expire_header = 0;
        $path = '/';
        // Domain: the request host without its port, none for an IP (CookieDomain).
        $secure = TRUE;
        $httponly = FALSE; // JS createCookie() sets this cookie in record_simple.php

        //legacy cookies
        setcookie('tracking202outbound-legacy', (string) $outbound_site_url, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER)]);

        setcookie('tracking202outbound', (string) $outbound_site_url, ['expires' => $expire, 'path' => '/', 'domain' => \Prosper202\Http\CookieDomain::fromServer($_SERVER), 'secure' => $secure, 'httponly' => $httponly, 'samesite' => 'None']);
    }
}

/**
 * The query string a redirect passes on to its destination: every incoming
 * parameter except the tracker's own.
 *
 * Identity parameters are the tracker's too, with one distinction. The
 * landing page's first-party id (p202lpid) never leaves. The customer id (every
 * alias and its type), the operator's signature of it and the consent flag go
 * on only to the operator's own landing page ($toOwnLandingPage), whose
 * landing.php reads them from its URL to personalise the page and to honour
 * the refusal. An offer is a third party: a customer id is personal data the
 * operator gave this tracker, and it is not forwarded there.
 *
 * @param array<string, mixed> $vars
 */
function getPrePopVars($vars, bool $toOwnLandingPage = false)
{
    $urlvars = '';
    $stoplist = [
        'subid',
        'c1',
        'c2',
        'c3',
        'c4',
        't202kw',
        't202id',
        't202b',
        't202ctx', // Landing Page Optimizer context token: minted fresh per click, never re-passed
        'p202lpid',
        't202ref',
        't202pubid',
        'acip',
        '202v',
        '202vars',
        'lpip',
        'pci',
        'gclid',
        'msclkid',
        'fbclid',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content'
    ];
    if (!$toOwnLandingPage) {
        array_push($stoplist, 'cust', 'customer_ref', 'cust_type', 'customer_ref_type', 'cust_sig', 'p202_consent');
    }

    foreach ($vars as $key => $value) {
        // A list (x[]=1) has no one value to pass on: it went as x=Array.
        if (! in_array($key, $stoplist) && is_string($value)) {
            $urlvars .= $key . "=" . $value . "&";
        }
    }

    return $urlvars;
}

function setPrePopVars($urlvars, $redirect_site_url, $b64 = false)
{
    if (isset($urlvars) && $urlvars != '') {

        // remove & at the end of the string
        $urlvars = rtrim((string) $urlvars, '&');
        if ($b64) {
            $urlvars = "202vars=" . base64_encode($urlvars);
        }
        if (! parse_url((string) $redirect_site_url, PHP_URL_QUERY)) {

            // if there is no query url the add a ? to thecVars but before doing that remove case where there may be a ? at the end of the url and nothing else
            $redirect_site_url = rtrim((string) $redirect_site_url, '?');

            // remove the & from thecVars and put a ? in fron t of it

            $redirect_site_url .= "?" . $urlvars;
        } else {

            $redirect_site_url .= "&" . $urlvars;
        }
    }

    return $redirect_site_url;
}

/**
 * The key of the entry that takes this visit, each entry's chance its
 * 'weight' over the sum of the weights: the one chooser for a rotator rule's
 * redirects (rtr.php and offrtr.php).
 *
 * A weight that is not a positive number takes no share, as a 0 does — a
 * blank one threw a TypeError from the sum. When no entry has a share, the
 * first entry takes the visit; that was the intent all along, but the zeroes
 * had already been unset, so it returned null and the visitor got a blank
 * page.
 */
function getSplitTestValue(array $values)
{
    $weights = [];

    foreach ($values as $key => $value) {
        $weight = is_numeric($value['weight'] ?? null) ? (int) $value['weight'] : 0;
        if ($weight > 0) {
            $weights[$key] = $weight;
        }
    }

    if ($weights === []) {
        // No positively-weighted entries; nothing eligible to split-test.
        return array_key_first($values);
    }

    $rand = mt_rand(1, array_sum($weights));

    foreach ($weights as $key => $weight) {
        $rand -= $weight;
        if ($rand <= 0) {
            return $key;
        }
    }
}

/**
 * A path on this install for a URL in the requester's own response (a form
 * action, a redirect to the 404 page): the install's directory under the
 * document root (TrackingBaseUrl::installPath()), then $relative. The
 * cloaked redirects' forms posted to a root-absolute
 * '/tracking202/redirect/cl2.php', which on an install in a subdirectory is
 * a 404 (measured: served from /agent-…/, off.php's form named
 * /tracking202/redirect/cl2.php). ClickPathUrlsTest refuses a root-absolute
 * path of this install in the click endpoints.
 */
function p202InstallPath(string $relative): string
{
    return \Prosper202\Click\TrackingBaseUrl::installPath($_SERVER, ROOT_PATH) . ltrim($relative, '/');
}

/**
 * True when the current request is a SPECULATIVE fetch — a browser prefetch /
 * prerender, a link-preview scanner, or a HEAD probe — rather than a real human
 * navigation. The click-recording redirect endpoints (dl/lp/rtr/off/…) must not
 * record a click for these: Chrome's Network Prediction (on by default) fetches
 * the URL from the address bar or a hovered link BEFORE the click, then fetches
 * it again on the real navigation, so counting both double-counts every click.
 * Detected via the standard speculation-request headers each browser sends, plus
 * the HTTP method. Callers answer a true result with 204 and record nothing; the
 * real navigation re-requests and is counted exactly once.
 */
function p202IsSpeculativeRequest(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return true;
    }
    // Collapse EVERY speculation-intent header into one lowercase haystack and
    // substring-match. The headers each browser sends:
    //   Chrome/Edge  Sec-Purpose: "prefetch" | "prefetch;prerender" | "prerender"
    //   Chrome legacy Purpose:     "prefetch" (and joined variants like
    //                              "prefetch;prerender")
    //   Safari       X-Purpose:    "preview" | "prefetch"
    //   Firefox      X-moz:        "prefetch"
    // Substring matching is essential, not stylistic: the legacy Purpose /
    // X-Purpose / X-moz headers used to require the value to be EXACTLY
    // "prefetch", so a speculative hit sending "Purpose: prefetch;prerender"
    // (any comma/semicolon-joined variant) was not recognized, fell through the
    // guard, and got recorded as a real click ON TOP OF the real navigation —
    // the double-count. Sec-Purpose was already tolerant; now all of them are.
    $signals = strtolower(
        (string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' '
        . (string) ($_SERVER['HTTP_PURPOSE'] ?? '') . ' '
        . (string) ($_SERVER['HTTP_X_PURPOSE'] ?? '') . ' '
        . (string) ($_SERVER['HTTP_X_MOZ'] ?? '')
    );

    return str_contains($signals, 'prefetch')
        || str_contains($signals, 'prerender')
        || str_contains($signals, 'preview');
}

/**
 * Opt-in click tracer. Off by default with ZERO overhead: it logs one line per
 * redirect hit ONLY while the flag file 202-config/.click-debug exists, so a
 * double-count can be diagnosed against real traffic without guessing. Each
 * line carries the decision (recorded / declined-speculative) plus every header
 * that distinguishes a real navigation from a speculative/duplicate fetch
 * (Sec-Purpose/Purpose/X-Purpose/X-moz and the Sec-Fetch-* set, where a genuine
 * user navigation is Sec-Fetch-Mode: navigate + Sec-Fetch-User: ?1). Compare
 * the two lines for one click to see exactly what the second hit is.
 *
 * Enable:  touch 202-config/.click-debug
 * Read:    tail -f <system temp dir>/p202-click-debug.log   (path is error_logged once)
 * Disable: rm 202-config/.click-debug
 */
function p202LogRedirectHit(string $endpoint, string $decision): void
{
    static $enabled = null;
    if ($enabled === null) {
        $enabled = is_file(__DIR__ . '/.click-debug');
    }
    if (!$enabled) {
        return;
    }

    $h = static fn (string $k): string => str_replace(["\t", "\n", "\r"], ' ', (string) ($_SERVER[$k] ?? ''));
    $line = implode("\t", [
        date('Y-m-d H:i:s'),
        'ep=' . $endpoint,
        'decision=' . $decision,
        'method=' . $h('REQUEST_METHOD'),
        // Every address masked to its /24 (/48), whatever the privacy
        // setting: this is a diagnostic log in the temp directory, which
        // tells two hits of one click apart by their headers, and wrote each
        // visitor's address in full while it was on. The forwarded chain is
        // masked element by element; what is not an address shows as '?'.
        'ip=' . \Prosper202\Http\StoredVisitorIp::mask(trim($h('REMOTE_ADDR'))),
        'xff=' . implode(',', array_map(
            static fn (string $hop): string => \Prosper202\Http\StoredVisitorIp::mask(trim($hop)) ?: '?',
            array_filter(explode(',', \Prosper202\Http\VisitorIp::forwardedForAsSent($_SERVER)), static fn (string $hop): bool => trim($hop) !== '')
        )),
        'visitor_ip=' . \Prosper202\Http\StoredVisitorIp::mask(\Prosper202\Http\VisitorIp::fromServer($_SERVER)),
        'sec_purpose=' . $h('HTTP_SEC_PURPOSE'),
        'purpose=' . $h('HTTP_PURPOSE'),
        'x_purpose=' . $h('HTTP_X_PURPOSE'),
        'x_moz=' . $h('HTTP_X_MOZ'),
        'sf_dest=' . $h('HTTP_SEC_FETCH_DEST'),
        'sf_mode=' . $h('HTTP_SEC_FETCH_MODE'),
        'sf_site=' . $h('HTTP_SEC_FETCH_SITE'),
        'sf_user=' . $h('HTTP_SEC_FETCH_USER'),
        'ua=' . $h('HTTP_USER_AGENT'),
        'uri=' . $h('REQUEST_URI'),
    ]);

    $logFile = sys_get_temp_dir() . '/p202-click-debug.log';
    if (@file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        error_log('P202CLICKDBG ' . $line);
    } else {
        static $announced = false;
        if (!$announced) {
            $announced = true;
            error_log('P202CLICKDBG click tracer active -> ' . $logFile);
        }
    }
}

/**
 * Decline a speculative fetch: record nothing and end the request with an
 * empty, NON-cacheable 204. The no-store headers are essential — without them
 * a prefetched/prerendered 204 can be stored and REUSED to satisfy the user's
 * real navigation, which would then be neither counted nor redirected to the
 * destination (the click silently vanishes). Forcing revalidation guarantees
 * the actual click re-enters the redirect path. Every redirect endpoint that
 * calls p202IsSpeculativeRequest() answers through here.
 */
function p202DeclineSpeculativeRequest(): never
{
    p202LogRedirectHit(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'redirect')), 'declined-speculative');
    if (!headers_sent()) {
        http_response_code(204);
        p202NoStore();
    }
    die();
}

function setDirtyHour($mysql)
{
    $de = new DataEngine();
    $de->setDirtyHour($mysql['click_id']);
}

function isSSL(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || intval($_SERVER['SERVER_PORT']) == intval(getservbyname('https', 'tcp'));
}

/** A click cookie, or its -legacy twin (Prosper202\Http\ClickCookie). */
function getCookie202($cookieName)
{
    return \Prosper202\Http\ClickCookie::value($_COOKIE, (string) $cookieName);
}

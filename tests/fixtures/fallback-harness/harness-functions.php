<?php

// The connect2.php functions lp.php and off.php call before and around their
// fallback refresh, for the stand-in connect2.php beside this file.

declare(strict_types=1);

function systemHash(): string
{
    return 'h';
}

function setCache($key, $value, $exp = null)
{
    global $memcache;
    $memcache->entries[$key] = $value;
    $memcache->writes[$key] = $value;

    return true;
}

function memcache_mysql_fetch_assoc($db, $sql)
{
    global $p202Harness;

    return $p202Harness['row'];
}

// The script's first write after the refresh ends the run.
function record_mysql_error(...$args)
{
    exit;
}

function p202IsSpeculativeRequest(): bool
{
    return false;
}

function p202DeclineSpeculativeRequest(): void
{
}

function rotateTrackerUrl($db, $row)
{
    return (string) ($row['aff_campaign_url'] ?? '');
}

function replaceTrackerPlaceholders($db, $url, $clickId)
{
    return $url;
}

function getPrePopVars($vars)
{
    return [];
}

function setPrePopVars($vars, $url, $encode)
{
    return $url;
}

function p202NoStore(): void
{
}

// The address off.php looks a cookie-less visitor's last click up by. Empty:
// LastClickFromAddress then answers "no click" without a query, which is what
// this harness's reads answer anyway (its mysqli never connects).
function p202StoredVisitorIp(): string
{
    return '';
}

// connect2.php's click-cookie reader, as it is: the cookie or its -legacy twin.
function getCookie202($cookieName)
{
    return \Prosper202\Http\ClickCookie::value($_COOKIE, (string) $cookieName);
}

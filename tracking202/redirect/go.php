<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

// go.php is a direct public entry point that uses RedirectHelper before any
// bootstrap (connect2.php) loads the Composer autoloader, so pull in the
// self-contained class explicitly. The class lives in the PSR-4 directory
// tracking202/Redirect/ (capital R), while this script sits in lowercase
// tracking202/redirect/; reference it via ../Redirect/ so the path is correct
// on case-sensitive (Linux production) filesystems. A bare
// __DIR__ . '/RedirectHelper.php' would resolve to the lowercase directory and
// fatal with "Failed opening required" everywhere except case-insensitive macOS.
require_once __DIR__ . '/../Redirect/RedirectHelper.php';

$vars = explode(' ', base64_decode((string) RedirectHelper::getStringParam('202v')));

if(isset($vars[1])){
$_GET['pci']=$vars[1];
$expire = time() + 2592000;
@setcookie('tracking202subid',$vars[0], ['expires' => $expire, 'path' => '/', 'domain' => (string) $_SERVER['SERVER_NAME']]);
@setcookie('tracking202subid_a_' . $vars[2],$vars[0], ['expires' => $expire, 'path' => '', 'domain' => (string) $_SERVER['SERVER_NAME']]);
@setcookie('tracking202pci',$vars[1], ['expires' => $expire, 'path' => '/', 'domain' => (string) $_SERVER['SERVER_NAME']]);
}
$redirect_site_url='';


// Simple LP redirect
if (isset($_GET['lpip']) && is_numeric($_GET['lpip'])) {
    if (isset($_COOKIE['tracking202outbound'])) {
        $tracking202outbound = $_COOKIE['tracking202outbound'];
    } else {
        require_once substr(__DIR__, 0, -21) . '/tracking202/redirect/lp.php';
    }

    RedirectHelper::redirect($tracking202outbound);
}

// Advanced LP redirect
if (isset($_GET['acip']) && is_numeric($_GET['acip'])) {
    include_once substr(__DIR__, 0, -21) . '/tracking202/redirect/off.php';
}

// Rotator redirect on ALP
if (isset($_GET['rpi']) && is_numeric($_GET['rpi'])) {
    include_once substr(__DIR__, 0, -21) . '/tracking202/redirect/offrtr.php';
}

  die("Missing LPIP, ACIP or RPI variable!");
  




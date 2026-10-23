<?php
declare(strict_types=1);

use Prosper202\Http\RedirectHelper;

// go.php uses RedirectHelper and ClickCookie before any bootstrap
// (connect2.php) loads the Composer autoloader, so it loads both itself
// (GoPhpBeforeBootstrapTest runs this script without one).
require_once __DIR__ . '/../../202-config/Http/RedirectHelper.php';
require_once __DIR__ . '/../../202-config/Http/ClickCookie.php';

$vars = explode(' ', base64_decode((string) RedirectHelper::getStringParam('202v')));

if (isset($vars[1])) {
    $_GET['pci'] = $vars[1];
    // The click cookies a 202v link carries ("<click id> <public id>
    // <campaign id>") are the click path's tracking cookies, so they are set
    // as every endpoint sets them: through connect2.php's setters, under the
    // privacy setting of the account whose click the link names, and only for
    // a click that exists. They were set here, before any bootstrap, under
    // every setting and for any id a link named. The bootstrap is loaded for
    // this path only; the endpoints included below load it again as a no-op.
    // Goes on when the database is out of reach: lp.php and off.php fall
    // back, and offrtr.php asks for the database itself.
    $p202OutageFallback = true;
    include_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';
    $goClickId = ctype_digit($vars[0]) ? (int) $vars[0] : 0;
    $goClick = p202ClickOwner($goClickId);
    if ($goClick !== null) {
        p202ApplyOwnerPrivacy($goClick['user_id']);
        // The campaign the link names, or the click's own when it names none.
        $goCampaign = ctype_digit($vars[2] ?? '') ? $vars[2] : (string) $goClick['aff_campaign_id'];
        setClickIdCookie((string) $goClickId, $goCampaign);
        if (ctype_digit($vars[1])) {
            setPCIdCookie($vars[1]);
        }
    }
}
$redirect_site_url = '';


// Simple LP redirect
if (isset($_GET['lpip']) && is_numeric($_GET['lpip'])) {
    // A cookie sent as a list is no URL (redirect() takes a string): the
    // visitor goes on to lp.php, as with no cookie.
    $tracking202outbound = \Prosper202\Http\ClickCookie::value($_COOKIE, 'tracking202outbound');
    if (is_string($tracking202outbound)) {
        RedirectHelper::redirect($tracking202outbound);
    }
    require_once substr(__DIR__, 0, -21) . '/tracking202/redirect/lp.php';
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
  




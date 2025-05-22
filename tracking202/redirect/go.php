<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

$vars = explode(' ', base64_decode((string)($_GET['202v'] ?? '')));

if(isset($vars[1])){
$_GET['pci']=$vars[1];
$expire = time() + 2592000;
@setcookie('tracking202subid',$vars[0],$expire,'/', $_SERVER['SERVER_NAME']);
@setcookie('tracking202subid_a_' . $vars[2],$vars[0],$expire,'', $_SERVER['SERVER_NAME']);
@setcookie('tracking202pci',$vars[1],$expire,'/', $_SERVER['SERVER_NAME']);
}
$redirect_site_url='';


// Simple LP redirect
if (isset($_GET['lpip']) && is_numeric($_GET['lpip'])) {
    if (isset($_COOKIE['tracking202outbound'])) {
        $tracking202outbound = $_COOKIE['tracking202outbound'];
    } else {
        require_once substr(dirname(__FILE__), 0, -21) . '/tracking202/redirect/lp.php';
    }

    RedirectHelper::redirect($tracking202outbound);
}

// Advanced LP redirect
if (isset($_GET['acip']) && is_numeric($_GET['acip'])) {
    include_once substr(dirname(__FILE__), 0, -21) . '/tracking202/redirect/off.php';
}

// Rotator redirect on ALP
if (isset($_GET['rpi']) && is_numeric($_GET['rpi'])) {
    include_once substr(dirname(__FILE__), 0, -21) . '/tracking202/redirect/offrtr.php';
}

  die("Missing LPIP, ACIP or RPI variable!");
  




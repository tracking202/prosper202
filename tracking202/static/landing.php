<?php
declare(strict_types=1);
use UAParser\Parser;
header('Content-type: application/javascript');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
if ( isset( $_SERVER["HTTPS"] ) && strtolower( (string) $_SERVER["HTTPS"] ) == "on" ) {
$strProtocol = 'https';
} else {
$strProtocol = 'http';
}

// Process geo/UA data once (previously duplicated in both _.t202Data and t202Data)
$data = getGeoData($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
if($data['country']==='Unknown country')
    $data['country']='';
if($data['country_code']==='non')
   $data['country_code']='';
if($data['region']==='Unknown region')
    $data['region']='';
if($data['city']==='Unknown city')
    $data['city']='';
if($data['postal_code']==='Unknown postal code')
    $data['postal_code']='';

$parser = Parser::create();
$detect = new DeviceDetect();
$ua = $detect->getUserAgent();
$result = $parser->parse($ua);

$IspData = getIspData($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
if($IspData==="Unknown ISP/Carrier")
    $data['isp']='';
else
    $data['isp']=$IspData;

// Build the geo data object safely using json_encode to prevent XSS
$t202ServerData = [
    't202Country' => $data['country'],
    't202CountryCode' => $data['country_code'],
    't202Region' => $data['region'],
    't202City' => $data['city'],
    't202Postal' => $data['postal_code'],
    't202Browser' => $result->ua->family,
    't202OS' => $result->os->family,
    't202Device' => $result->device->family,
    't202ISP' => $data['isp'],
];

// Mapping of URL parameter names to their t202DataObj keys for client-side values.
// Server-side keys (geo/UA) are already in $t202ServerData above.
$t202ClientParamMap = [
    't202kw' => 't202kw',
    'c1' => 't202c1',
    'c2' => 't202c2',
    'c3' => 't202c3',
    'c4' => 't202c4',
    'utm_source' => 't202utm_source',
    'utm_medium' => 't202utm_medium',
    'utm_term' => 't202utm_term',
    'utm_content' => 't202utm_content',
    'utm_campaign' => 't202utm_campaign',
];

// Resolve custom variables server-side to eliminate an extra HTTP round-trip.
// The loader snippet now forwards t202id from the landing page URL.
$t202CustomVars = [];
$t202id = $_GET['t202id'] ?? '';
if ($t202id !== '') {
    $mysql_t202id = $db->real_escape_string((string)$t202id);
    $cv_sql = "SELECT 2cv.parameters
        FROM 202_trackers
        LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
        LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
        WHERE tracker_id_public = '".$mysql_t202id."'";
    $cv_result = $db->query($cv_sql);
    if ($cv_result && $cv_result->num_rows > 0) {
        $cv_row = $cv_result->fetch_assoc();
        if (!empty($cv_row['parameters'])) {
            $t202CustomVars = explode(',', $cv_row['parameters']);
        }
    }
}

$baseUrl = $strProtocol . '://' . getTrackingDomain() . get_absolute_url();
$lpip = htmlentities((string) ($_GET['lpip'] ?? ''));
?>

(function() {
var _params = new URLSearchParams(window.location.search);

function t202GetVar(name) {
	var values = _params.getAll(name);
	var result = values.join(', ');
	return result.replace(/\+/g, ' ');
}

function t202Enc(e) {
	return encodeURIComponent(e);
}

function createCookie(name, value, days) {
	var expires = "";
	if (days) {
		var date = new Date();
		date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
		expires = "; expires=" + date.toGMTString();
	}
	document.cookie = name + "=" + value + expires + "; path=/";
}

function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(';');
	for (var i = 0; i < ca.length; i++) {
		var c = ca[i];
		while (c.charAt(0) === ' ') c = c.substring(1, c.length);
		if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
	}
	return null;
}

function eraseCookie(name) {
	createCookie(name, "", -1);
}

// Expose cookie/param functions globally for record_simple.php, record_adv.php,
// and outbound JS redirect snippets that depend on them
window.t202GetVar = t202GetVar;
window.t202Enc = t202Enc;
window.createCookie = createCookie;
window.readCookie = readCookie;
window.eraseCookie = eraseCookie;

// Read URL params once — shared between tracking init and dynamic content
var t202kw = readCookie('t202forcedkw') || t202GetVar('t202kw');
var c1 = t202GetVar('c1');
var c2 = t202GetVar('c2');
var c3 = t202GetVar('c3');
var c4 = t202GetVar('c4');
var utm_source = t202GetVar('utm_source');
var utm_medium = t202GetVar('utm_medium');
var utm_term = t202GetVar('utm_term');
var utm_content = t202GetVar('utm_content');
var utm_campaign = t202GetVar('utm_campaign');

// --- Tracking beacon ---
(function() {
	var lpip = '<?php echo $lpip; ?>';
	var t202id = t202GetVar('t202id');
	var t202ref = t202GetVar('t202ref');
	var t202b = t202GetVar('t202b');
	var referer = document.referrer;
	var resolution = screen.width + 'x' + screen.height;
	var language = (navigator.language || '').substring(0, 2);

	// Custom variables resolved server-side — no extra HTTP request needed
	var customVarNames = <?php echo json_encode($t202CustomVars); ?>;
	var customVarValues = [];
	for (var i = 0; i < customVarNames.length; i++) {
		customVarValues.push(t202GetVar(customVarNames[i]));
	}

	// Build tracking URL using array join (faster than 20+ string concatenations)
	var parts = [
		"<?php echo $baseUrl; ?>tracking202/static/record.php?lpip=" + t202Enc(lpip),
		"t202id=" + t202Enc(t202id),
		"t202kw=" + t202kw,
		"t202ref=" + t202Enc(t202ref),
		"OVRAW=" + t202Enc(t202GetVar('OVRAW')),
		"OVKEY=" + t202Enc(t202GetVar('OVKEY')),
		"OVMTC=" + t202Enc(t202GetVar('OVMTC')),
		"c1=" + t202Enc(c1),
		"c2=" + t202Enc(c2),
		"c3=" + t202Enc(c3),
		"c4=" + t202Enc(c4),
		"t202b=" + t202Enc(t202b),
		"gclid=" + t202Enc(t202GetVar('gclid')),
		"target_passthrough=" + t202Enc(t202GetVar('target_passthrough')),
		"keyword=" + t202Enc(t202GetVar('keyword')),
		"utm_source=" + t202Enc(utm_source),
		"utm_medium=" + t202Enc(utm_medium),
		"utm_term=" + t202Enc(utm_term),
		"utm_content=" + t202Enc(utm_content),
		"utm_campaign=" + t202Enc(utm_campaign),
		"referer=" + t202Enc(referer),
		"resolution=" + t202Enc(resolution),
		"language=" + t202Enc(language)
	];
	for (var i = 0; i < customVarNames.length; i++) {
		parts.push(customVarNames[i] + "=" + t202Enc(customVarValues[i]));
	}

	// Inject record.php as script — its response calls createCookie() to set tracking cookies
	var js202a = document.createElement("script");
	js202a.src = parts.join("&");
	js202a.async = true;
	js202a.id = "recjs";
	(document.head || document.getElementsByTagName("script")[0].parentNode).appendChild(js202a);
})();

// --- Dynamic content replacement ---
(function() {
	// Build data object: server-side geo/UA + client-side URL params (read once above)
	var t202DataObj = <?php echo json_encode($t202ServerData, JSON_UNESCAPED_UNICODE); ?>;
	var clientParamMap = <?php echo json_encode($t202ClientParamMap); ?>;
	var clientValues = {t202kw: t202kw, c1: c1, c2: c2, c3: c3, c4: c4, utm_source: utm_source, utm_medium: utm_medium, utm_term: utm_term, utm_content: utm_content, utm_campaign: utm_campaign};
	for (var urlParam in clientParamMap) {
		if (clientParamMap.hasOwnProperty(urlParam)) {
			t202DataObj[clientParamMap[urlParam]] = clientValues[urlParam];
		}
	}

	// Single DOM query instead of 19 separate getElementsByName calls
	var selector = Object.keys(t202DataObj).map(function(k) { return '[name="' + k + '"]'; }).join(',');
	var matchedElements = document.querySelectorAll(selector);
	for (var i = 0; i < matchedElements.length; i++) {
		var el = matchedElements[i];
		var name = el.getAttribute('name');
		el.textContent = t202DataObj[name] || el.getAttribute('t202Default') || '';
	}
})();

})();

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
$data = getGeoData($_SERVER['HTTP_X_FORWARDED_FOR']);
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

$IspData = getIspData($_SERVER['HTTP_X_FORWARDED_FOR']);
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

function t202Init(vars) {
	var t202kw;
	if (readCookie('t202forcedkw')) {
		t202kw = readCookie('t202forcedkw');
	} else {
		t202kw = t202GetVar('t202kw');
	}

	var lpip = '<?php echo $lpip; ?>';
	var t202id = t202GetVar('t202id');
	var t202ref = t202GetVar('t202ref');
	var OVRAW = t202GetVar('OVRAW');
	var OVKEY = t202GetVar('OVKEY');
	var OVMTC = t202GetVar('OVMTC');
	var c1 = t202GetVar('c1');
	var c2 = t202GetVar('c2');
	var c3 = t202GetVar('c3');
	var c4 = t202GetVar('c4');
	var t202b = t202GetVar('t202b');
	var gclid = t202GetVar('gclid');
	var target_passthrough = t202GetVar('target_passthrough');
	var keyword = t202GetVar('keyword');
	var referer = document.referrer;
	var utm_source = t202GetVar('utm_source');
	var utm_medium = t202GetVar('utm_medium');
	var utm_term = t202GetVar('utm_term');
	var utm_content = t202GetVar('utm_content');
	var utm_campaign = t202GetVar('utm_campaign');
	var resolution = screen.width + 'x' + screen.height;
	var language = navigator.language || navigator.browserLanguage || '';
	language = language.substr(0, 2);

	var custom_vars = [];
	for (var i = 0; i < vars.length; i++) {
		custom_vars.push(t202GetVar(vars[i]));
	}

	var rurl = "<?php echo $baseUrl; ?>tracking202/static/record.php?lpip=" + t202Enc(lpip)
		+ "&t202id=" + t202Enc(t202id)
		+ "&t202kw=" + t202kw
		+ "&t202ref=" + t202Enc(t202ref)
		+ "&OVRAW=" + t202Enc(OVRAW)
		+ "&OVKEY=" + t202Enc(OVKEY)
		+ "&OVMTC=" + t202Enc(OVMTC)
		+ "&c1=" + t202Enc(c1)
		+ "&c2=" + t202Enc(c2)
		+ "&c3=" + t202Enc(c3)
		+ "&c4=" + t202Enc(c4)
		+ "&t202b=" + t202Enc(t202b)
		+ "&gclid=" + t202Enc(gclid)
		+ "&target_passthrough=" + t202Enc(target_passthrough)
		+ "&keyword=" + t202Enc(keyword)
		+ "&utm_source=" + t202Enc(utm_source)
		+ "&utm_medium=" + t202Enc(utm_medium)
		+ "&utm_term=" + t202Enc(utm_term)
		+ "&utm_content=" + t202Enc(utm_content)
		+ "&utm_campaign=" + t202Enc(utm_campaign)
		+ "&referer=" + t202Enc(referer)
		+ "&resolution=" + t202Enc(resolution)
		+ "&language=" + t202Enc(language);

	for (var i = 0; i < vars.length; i++) {
		rurl = rurl + "&" + vars[i] + "=" + t202Enc(custom_vars[i]);
	}

	// Inject record.php as script — its response calls createCookie() to set tracking cookies
	var js202a = document.createElement("script");
	js202a.src = rurl;
	js202a.async = true;
	js202a.id = "recjs";
	(document.head || document.getElementsByTagName("script")[0].parentNode).appendChild(js202a);
}

function t202Data() {
	var t202DataObj = <?php echo json_encode($t202ServerData, JSON_UNESCAPED_UNICODE); ?>;
	t202DataObj.t202kw = t202GetVar('t202kw');
	t202DataObj.t202c1 = t202GetVar('c1');
	t202DataObj.t202c2 = t202GetVar('c2');
	t202DataObj.t202c3 = t202GetVar('c3');
	t202DataObj.t202c4 = t202GetVar('c4');
	t202DataObj.t202utm_source = t202GetVar('utm_source');
	t202DataObj.t202utm_medium = t202GetVar('utm_medium');
	t202DataObj.t202utm_term = t202GetVar('utm_term');
	t202DataObj.t202utm_content = t202GetVar('utm_content');
	t202DataObj.t202utm_campaign = t202GetVar('utm_campaign');

	var t202Elements = ['t202Country','t202CountryCode','t202Region','t202City','t202Postal','t202Browser','t202OS','t202Device','t202ISP','t202kw','t202c1','t202c2','t202c3','t202c4','t202utm_source','t202utm_medium','t202utm_term','t202utm_content','t202utm_campaign'];

	t202Elements.forEach(function(element) {
		var elements = document.getElementsByName(element);
		if (elements.length !== 0) {
			if (t202DataObj[element]) {
				for (var i = 0; i < elements.length; ++i) {
					elements[i].innerHTML = t202DataObj[element];
				}
			} else {
				for (var i = 0; i < elements.length; ++i) {
					elements[i].innerHTML = elements[i].getAttribute('t202Default');
				}
			}
		}
	});
}

// Fetch custom variables then initialize tracking and dynamic content
var get_custom_vars_url = '<?php echo $baseUrl; ?>tracking202/static/get_custom_vars.php?t202id=' + t202GetVar('t202id');

fetch(get_custom_vars_url)
	.then(function(response) { return response.json(); })
	.then(function(custom_variables) {
		t202Init(custom_variables);
		t202Data();
	})
	.catch(function() {
		// If custom vars fetch fails, init with empty array so tracking still fires
		t202Init([]);
		t202Data();
	});

})();

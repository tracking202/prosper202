<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * The code Setup › Get LP Code hands out for a landing page: the loader
 * script for the page itself, and the ways out of it to the offer.
 *
 * - A simple page (one campaign) links out through go.php?lpip=, or through
 *   a PHP or a JavaScript redirect page that reads the tracking202outbound
 *   cookie and falls back to lp.php?lpip= (get_landing_code.php).
 * - An advanced page (several offers) links each offer out through
 *   go.php?acip= (a campaign) or go.php?rpi= (a redirector), or through a
 *   PHP redirect to off.php or offrtr.php (get_adv_landing_code.php).
 *
 * The pages and the REST API (GET /landing-pages/{id}/code) build every
 * snippet here, so the code an agent is handed is the code a person copies:
 * the strings are the pages', byte for byte, including the date the PHP
 * redirects carry in their comment.
 *
 * $base is where every snippet starts: `//host[:port]/<install path>/`,
 * scheme-relative as the pages write it, so the code works on a landing page
 * served over http or https alike. The pages build it from
 * getTrackingDomain() and get_absolute_url(); the API from
 * Prosper202\Click\TrackingBaseUrl (protocolRelative()).
 */
final class LandingPageCode
{
    /**
     * The elements the loader fills on a landing page (Dynamic Content
     * Segments), and what each shows. The one list: the setup pages' help
     * text and getDynamicContentSegments() read it.
     */
    public const SEGMENTS = [
        't202Country'      => "Visitor's Country",
        't202CountryCode'  => "Visitor's Country Code",
        't202Region'       => "Visitor's Region/State",
        't202City'         => "Visitor's City",
        't202Postal'       => "Visitor's Postal/Zip Code",
        't202Browser'      => "Visitor's Browser",
        't202OS'           => "Visitor's Operating System",
        't202Device'       => "Visitor's Device Type",
        't202ISP'          => "Visitor's ISP",
        't202kw'           => 'Value passed in t202kw',
        't202c1'           => 'Value passed in C1',
        't202c2'           => 'Value passed in C2',
        't202c3'           => 'Value passed in C3',
        't202c4'           => 'Value passed in C4',
        't202utm_source'   => 'Value passed in utm_source',
        't202utm_medium'   => 'Value passed in utm_medium',
        't202utm_term'     => 'Value passed in utm_term',
        't202utm_content'  => 'Value passed in utm_content',
        't202utm_campaign' => 'Value passed in utm_campaign',
    ];

    private function __construct()
    {
    }

    /**
     * A tracking base with its scheme dropped, the form the code is written
     * in: `https://track.example.com/p202/` becomes `//track.example.com/p202/`.
     */
    public static function protocolRelative(string $trackingBaseUrl): string
    {
        $at = strpos($trackingBaseUrl, '://');
        if ($at === false) {
            throw new \InvalidArgumentException('Not a tracking base URL: ' . $trackingBaseUrl);
        }

        return '//' . substr($trackingBaseUrl, $at + 3);
    }

    /** The script that goes above </body> of the page visitors arrive on (both page types). */
    public static function loader(string $base, string $landingPageIdPublic): string
    {
        return '<script>
	(function(d, s) {
		var upxf = d.getElementsByTagName(s)[0], load = function(url, id) {
			if (d.getElementById(id)) {return;}
			var if202 = d.createElement("script");if202.src = url;if202.async = true;if202.id = id;
			upxf.parentNode.insertBefore(if202, upxf);
		};
		var t = new URLSearchParams(window.location.search).get("t202id") || "";
		load("' . $base . 'tracking202/static/landing.php?lpip=' . $landingPageIdPublic . '&t202id=" + encodeURIComponent(t), "upxif");
	}(document, "script"));
	</script>';
    }

    /** A simple page's outbound link (Option 1): go.php?lpip=. */
    public static function simpleOutboundLink(string $base, string $landingPageIdPublic): string
    {
        return $base . 'tracking202/redirect/go.php?lpip=' . $landingPageIdPublic;
    }

    /** A simple page's PHP redirect page (Option 2): the outbound cookie, else lp.php?lpip=. */
    public static function simpleOutboundPhp(string $base, string $landingPageIdPublic, string $landingPageUrl, int $now): string
    {
        $link = htmlentities($base . 'tracking202/redirect/lp.php?lpip=' . $landingPageIdPublic);

        return '<?php

  // -------------------------------------------------------------------
  //
  // Tracking202 PHP Redirection, created on ' . date('D M, Y', $now) .'
  //
  // This PHP code is to be used for the following landing page.
  // ' . $landingPageUrl . '
  //
  // -------------------------------------------------------------------

  if (isset($_COOKIE[\'tracking202outbound\'])) {
	$tracking202outbound = $_COOKIE[\'tracking202outbound\'];
  } else {
	$tracking202outbound = \''.$link.'&pci=\'.$_COOKIE[\'tracking202pci\'];
  }

  header(\'location: \'.$tracking202outbound);

?>';
    }

    /** A simple page's JavaScript redirect page (Option 3): lets other tags fire first. */
    public static function simpleOutboundJavascript(string $base, string $landingPageIdPublic): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
	<title>GO</title>
</head>
<body>

<!-- PLACE OTHER LANDING PAGE CLICK THROUGH CONVERSION TRACKING PIXELS HERE -->

<!-- NOW THE TRACKING202 REDIRECTS OUT -->
<script type="text/javascript">
if (readCookie(\'tracking202outbound\') != \'\') {
	window.location=readCookie(\'tracking202outbound\');
} else {
	window.location=\''. $base .'tracking202/redirect/lp.php?lpip=' . $landingPageIdPublic .'\';
}

function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(\';\');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==\' \') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return urldecode(c.substring(nameEQ.length,c.length));
	}
	return false;
}

function urldecode(url) {
	  return decodeURIComponent(url.replace(/\+/g, \' \'));
}
</script>
</body>
</html>';
    }

    /** An advanced page's outbound link to a campaign: go.php?acip=. */
    public static function campaignOutboundLink(string $base, string $campaignIdPublic): string
    {
        return $base . 'tracking202/redirect/go.php?acip=' . $campaignIdPublic;
    }

    /** An advanced page's PHP redirect to a campaign: off.php?acip=. */
    public static function campaignOutboundPhp(string $base, string $campaignIdPublic, string $campaignName, string $landingPageUrl, int $now): string
    {
        return '
<?php

// -------------------------------------------------------------------
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y', $now) .'
//
// This PHP code is to be used for the following campaign:
// ' . $campaignName . ' on ' . $landingPageUrl . '
//
// -------------------------------------------------------------------

$tracking202outbound = \''. $base .'tracking202/redirect/off.php?acip='.$campaignIdPublic.'&pci=\'.$_COOKIE[\'tracking202pci\'];

header(\'location: \'.$tracking202outbound);

?>';
    }

    /** An advanced page's outbound link to a redirector: go.php?rpi=. */
    public static function rotatorOutboundLink(string $base, string $rotatorPublicId): string
    {
        return $base . 'tracking202/redirect/go.php?rpi=' . $rotatorPublicId;
    }

    /** An advanced page's PHP redirect to a redirector: offrtr.php?rpi=. */
    public static function rotatorOutboundPhp(string $base, string $rotatorPublicId, string $rotatorName, string $landingPageUrl, int $now): string
    {
        return '
<?php

// -------------------------------------------------------------------
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y', $now) .'
//
// This PHP code is to be used for the following campaign:
// ' . $rotatorName . ' on ' . $landingPageUrl . '
//
// -------------------------------------------------------------------

$tracking202outbound = \''. $base .'tracking202/redirect/offrtr.php?rpi='.$rotatorPublicId.'\';

header(\'location: \'.$tracking202outbound);

?>';
    }
}

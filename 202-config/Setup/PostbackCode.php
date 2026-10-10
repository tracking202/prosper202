<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * The conversion pixels and postback URLs Setup › Postback / Pixel shows
 * (tracking202/setup/get_postback.php):
 *
 * - simple: an image pixel on gpx.php and a server-to-server postback URL on
 *   gpb.php, each carrying `amount` and `subid`;
 * - advanced: the same with `cid`, the campaign a conversion is recorded
 *   under when one visitor has clicked several;
 * - universal: the smart pixel on upx.php, which also fires the traffic
 *   source's own pixels, as a JavaScript tag (with an iframe fallback for
 *   browsers without JavaScript) and as a plain iframe.
 *
 * The page renders these for its defaults (every value empty) and its
 * script (202-js/p202-setup.js, postbackBuilder()) rewrites them as the
 * amount, campaign and sub id change; the REST API
 * (GET /conversions/postback-code) builds them here with the values it was
 * given. The strings are the script's, byte for byte, including that the
 * JavaScript pixel's `cid` stays empty whatever campaign was chosen.
 *
 * $root is `scheme://host[:port]/<install path>/tracking202/static/`. The
 * values are written in as given: a network's macro such as {aff_sub} or
 * #s2# must reach it unencoded. A caller that takes them from outside says
 * which characters it refuses (problem()).
 */
final class PostbackCode
{
    /** The longest value a pixel takes for amount or sub id. */
    public const MAX_VALUE_LENGTH = 255;

    private function __construct()
    {
    }

    /**
     * Every snippet the page shows, for these values.
     *
     * @return array{simple: array{pixel: string, postback_url: string}, advanced: array{pixel: string, postback_url: string}, universal: array{javascript: string, iframe: string}}
     */
    public static function snippets(string $root, string $amount, string $campaignId, string $subid): array
    {
        return [
            'simple' => [
                'pixel' => '<img height="1" width="1" border="0" style="display: none;" src="' . $root . 'gpx.php?amount=' . $amount . '&subid=' . $subid . '" />',
                'postback_url' => $root . 'gpb.php?amount=' . $amount . '&subid=' . $subid,
            ],
            'advanced' => [
                'pixel' => '<img height="1" width="1" border="0" style="display: none;" src="' . $root . 'gpx.php?amount=' . $amount . '&cid=' . $campaignId . '&subid=' . $subid . '" />',
                'postback_url' => $root . 'gpb.php?amount=' . $amount . '&cid=' . $campaignId . '&subid=' . $subid,
            ],
            'universal' => [
                'javascript' => "<script>\n var vars202={amount:\"" . $amount . "\",cid:\"\",subid:\"" . $subid . "\"};(function(d, s) {\n \tvar js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {\n \t\tif (d.getElementById(id)) {return;}\n \t\tif202 = d.createElement(\"iframe\");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = \"no\";if202.noResize = true;\n \t\tupxf.parentNode.insertBefore(if202, upxf);\n \t};\n \tload(\"" . $root . "upx.php?amount=\"+vars202['amount']+\"&cid=\"+vars202['cid']+\"&subid=\"+vars202['subid'], \"upxif\");\n }(document, \"script\"));</script>\n<noscript>\n \t<iframe height=\"1\" width=\"1\" border=\"0\" style=\"display: none;\" frameborder=\"0\" scrolling=\"no\" src=\"" . $root . 'upx.php?amount=' . $amount . '&cid=&subid=' . $subid . "\" seamless></iframe>\n</noscript>",
                'iframe' => '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="' . $root . 'upx.php?amount=' . $amount . '&subid=' . $subid . '" seamless></iframe>',
            ],
        ];
    }

    /**
     * Why a value cannot be written into the snippets, or null. Amount and
     * sub id are a number or a network's macro ({payout}, {aff_sub}, #s2#,
     * [=SID=], %subid1%), placed in a URL inside an HTML attribute and a
     * JavaScript string, so what would end any of those is refused rather
     * than escaped: an escaped macro is not the macro the network replaces.
     */
    public static function problem(string $value): ?string
    {
        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            return 'At most ' . self::MAX_VALUE_LENGTH . ' characters';
        }
        if (preg_match('/[\s\x00-\x1f\x7f"\'<>\\\\&]/', $value) === 1) {
            return "Must not contain spaces, quotes, <, >, \\, & or control characters: it is written into the pixel and the URL as given (a number, or your network's macro such as {payout} or {aff_sub})";
        }

        return null;
    }
}

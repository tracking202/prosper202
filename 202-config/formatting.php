<?php

function wptexturize($text) {
	global $wp_cockneyreplace;
	$next = true;
	$output = '';
	$curl = '';
	$textarr = preg_split('/(<.*>)/Us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	$stop = count($textarr);

	// if a plugin has provided an autocorrect array, use it
	if ( isset($wp_cockneyreplace) ) {
		$cockney = array_keys($wp_cockneyreplace);
		$cockneyreplace = array_values($wp_cockneyreplace);
	} else {
		$cockney = array("'tain't","'twere","'twas","'tis","'twill","'til","'bout","'nuff","'round","'cause");
		$cockneyreplace = array("&#8217;tain&#8217;t","&#8217;twere","&#8217;twas","&#8217;tis","&#8217;twill","&#8217;til","&#8217;bout","&#8217;nuff","&#8217;round","&#8217;cause");
	}

	$static_characters = array_merge(array('---', ' -- ', '--', 'xn&#8211;', '...', '``', '\'s', '\'\'', ' (tm)'), $cockney);
	$static_replacements = array_merge(array('&#8212;', ' &#8212; ', '&#8211;', 'xn--', '&#8230;', '&#8220;', '&#8217;s', '&#8221;', ' &#8482;'), $cockneyreplace);

	$dynamic_characters = array('/\'(\d\d(?:&#8217;|\')?s)/', '/(\s|\A|")\'/', '/(\d+)"/', '/(\d+)\'/', '/(\S)\'([^\'\s])/', '/(\s|\A)"(?!\s)/', '/"(\s|\S|\Z)/', '/\'([\s.]|\Z)/', '/(\d+)x(\d+)/');
	$dynamic_replacements = array('&#8217;$1','$1&#8216;', '$1&#8243;', '$1&#8242;', '$1&#8217;$2', '$1&#8220;$2', '&#8221;$1', '&#8217;$1', '$1&#215;$2');

	for ( $i = 0; $i < $stop; $i++ ) {
 		$curl = $textarr[$i];

		if (isset($curl{0}) && '<' != $curl{0} && $next) { // If it's not a tag
			// static strings
			$curl = str_replace($static_characters, $static_replacements, $curl);
			// regular expressions
			$curl = preg_replace($dynamic_characters, $dynamic_replacements, $curl);
		} elseif (strpos($curl, '<code') !== false || strpos($curl, '<pre') !== false || strpos($curl, '<kbd') !== false || strpos($curl, '<style') !== false || strpos($curl, '<script') !== false) {
			$next = false;
		} else {
			$next = true;
		}

		$curl = preg_replace('/&([^#])(?![a-zA-Z1-4]{1,8};)/', '&#038;$1', $curl);
		$output .= $curl;
	}

  	return $output;
}




function seems_utf8($Str) { # by bmorel at ssi dot fr
	for ($i=0; $i<strlen($Str); $i++) {
		if (ord($Str[$i]) < 0x80) continue; # 0bbbbbbb
		elseif ((ord($Str[$i]) & 0xE0) == 0xC0) $n=1; # 110bbbbb
		elseif ((ord($Str[$i]) & 0xF0) == 0xE0) $n=2; # 1110bbbb
		elseif ((ord($Str[$i]) & 0xF8) == 0xF0) $n=3; # 11110bbb
		elseif ((ord($Str[$i]) & 0xFC) == 0xF8) $n=4; # 111110bb
		elseif ((ord($Str[$i]) & 0xFE) == 0xFC) $n=5; # 1111110b
		else return false; # Does not match any model
		for ($j=0; $j<$n; $j++) { # n bytes matching 10bbbbbb follow ?
			if ((++$i == strlen($Str)) || ((ord($Str[$i]) & 0xC0) != 0x80))
			return false;
		}
	}
	return true;
}

function wp_specialchars( $text, $quotes = 0 ) {
	// Like htmlspecialchars except don't double-encode HTML entities
	$text = str_replace('&&', '&#038;&', $text);
	$text = str_replace('&&', '&#038;&', $text);
	$text = preg_replace('/&(?:$|([^#])(?![a-z1-4]{1,8};))/', '&#038;$1', $text);
	$text = str_replace('<', '&lt;', $text);
	$text = str_replace('>', '&gt;', $text);
	if ( 'double' === $quotes ) {
		$text = str_replace('"', '&quot;', $text);
	} elseif ( 'single' === $quotes ) {
		$text = str_replace("'", '&#039;', $text);
	} elseif ( $quotes ) {
		$text = str_replace('"', '&quot;', $text);
		$text = str_replace("'", '&#039;', $text);
	}
	return $text;
}

function utf8_uri_encode( $utf8_string, $length = 0 ) {
	$unicode = '';
	$values = array();
	$num_octets = 1;

	for ($i = 0; $i < strlen( $utf8_string ); $i++ ) {

		$value = ord( $utf8_string[ $i ] );

		if ( $value < 128 ) {
			if ( $length && ( strlen($unicode) + 1 > $length ) )
				break;
			$unicode .= chr($value);
		} else {
			if ( count( $values ) == 0 ) $num_octets = ( $value < 224 ) ? 2 : 3;

			$values[] = $value;

			if ( $length && ( (strlen($unicode) + ($num_octets * 3)) > $length ) )
				break;
			if ( count( $values ) == $num_octets ) {
				if ($num_octets == 3) {
					$unicode .= '%' . dechex($values[0]) . '%' . dechex($values[1]) . '%' . dechex($values[2]);
				} else {
					$unicode .= '%' . dechex($values[0]) . '%' . dechex($values[1]);
				}

				$values = array();
				$num_octets = 1;
			}
		}
	}

	return $unicode;
}


function sanitize_file_name( $name ) { // Like sanitize_title, but with periods
	$name = strtolower( $name );
	$name = preg_replace('/&.+?;/', '', $name); // kill entities
	$name = str_replace( '_', '-', $name );
	$name = preg_replace('/[^a-z0-9\s-.]/', '', $name);
	$name = preg_replace('/\s+/', '-', $name);
	$name = preg_replace('|-+|', '-', $name);
	$name = trim($name, '-');
	return $name;
}

function sanitize_user( $username, $strict = false ) {
	$raw_username = $username;
	$username = strip_tags($username);
	// Kill octets
	$username = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '', $username);
	$username = preg_replace('/&.+?;/', '', $username); // Kill entities

	// If strict, reduce to ASCII for max portability.
	if ( $strict )
		$username = preg_replace('|[^a-z0-9 _.\-@]|i', '', $username);

	return apply_filters('sanitize_user', $username, $raw_username, $strict);
}

function sanitize_title($title, $fallback_title = '') {
	$title = strip_tags($title);
	$title = apply_filters('sanitize_title', $title);

	if (empty($title)) {
		$title = $fallback_title;
	}

	return $title;
}


function convert_chars($content, $flag = 'obsolete') {
	// Translation of invalid Unicode references range to valid range
	$wp_htmltranswinuni = array(
	'&#128;' => '&#8364;', // the Euro sign
	'&#129;' => '',
	'&#130;' => '&#8218;', // these are Windows CP1252 specific characters
	'&#131;' => '&#402;',  // they would look weird on non-Windows browsers
	'&#132;' => '&#8222;',
	'&#133;' => '&#8230;',
	'&#134;' => '&#8224;',
	'&#135;' => '&#8225;',
	'&#136;' => '&#710;',
	'&#137;' => '&#8240;',
	'&#138;' => '&#352;',
	'&#139;' => '&#8249;',
	'&#140;' => '&#338;',
	'&#141;' => '',
	'&#142;' => '&#382;',
	'&#143;' => '',
	'&#144;' => '',
	'&#145;' => '&#8216;',
	'&#146;' => '&#8217;',
	'&#147;' => '&#8220;',
	'&#148;' => '&#8221;',
	'&#149;' => '&#8226;',
	'&#150;' => '&#8211;',
	'&#151;' => '&#8212;',
	'&#152;' => '&#732;',
	'&#153;' => '&#8482;',
	'&#154;' => '&#353;',
	'&#155;' => '&#8250;',
	'&#156;' => '&#339;',
	'&#157;' => '',
	'&#158;' => '',
	'&#159;' => '&#376;'
	);

	// Remove metadata tags
	$content = preg_replace('/<title>(.+?)<\/title>/','',$content);
	$content = preg_replace('/<category>(.+?)<\/category>/','',$content);

	// Converts lone & characters into &#38; (a.k.a. &amp;)
	$content = preg_replace('/&([^#])(?![a-z1-4]{1,8};)/i', '&#038;$1', $content);

	// Fix Word pasting
	$content = strtr($content, $wp_htmltranswinuni);

	// Just a little XHTML help
	$content = str_replace('<br>', '<br />', $content);
	$content = str_replace('<hr>', '<hr />', $content);

	return $content;
}



function balanceTags( $text, $force = false ) {
	if ( !$force && get_option('use_balanceTags') == 0 )
		return $text;
	return force_balance_tags( $text );
}



function zeroise($number,$threshold) { // function to add leading zeros when necessary
	return sprintf('%0'.$threshold.'s', $number);
}


function backslashit($string) {
	$string = preg_replace('/^([0-9])/', '\\\\\\\\\1', $string);
	$string = preg_replace('/([a-z])/i', '\\\\\1', $string);
	return $string;
}

function trailingslashit($string) {
	return untrailingslashit($string) . '/';
}

function untrailingslashit($string) {
	return rtrim($string, '/');
}

function addslashes_gpc($gpc) {
	global $wpdb;

	if (get_magic_quotes_gpc()) {
		$gpc = stripslashes($gpc);
	}

	return $wpdb->escape($gpc);
}


function stripslashes_deep($value) {
	 $value = is_array($value) ?
		 array_map('stripslashes_deep', $value) :
		 stripslashes($value);

	 return $value;
}

function urlencode_deep($value) {
	 $value = is_array($value) ?
		 array_map('urlencode_deep', $value) :
		 urlencode($value);

	 return $value;
}

function human_time_diff( $from, $to = '' ) {
	if ( empty($to) )
		$to = time();
	$diff = (int) abs($to - $from);
	if ($diff <= 3600) {
		$mins = round($diff / 60);
		if ($mins <= 1) {
			$mins = 1;
		}
		$since = sprintf(__ngettext('%s min', '%s mins', $mins), $mins);
	} else if (($diff <= 86400) && ($diff > 3600)) {
		$hours = round($diff / 3600);
		if ($hours <= 1) {
			$hour = 1;
		}
		$since = sprintf(__ngettext('%s hour', '%s hours', $hours), $hours);
	} elseif ($diff >= 86400) {
		$days = round($diff / 86400);
		if ($days <= 1) {
			$days = 1;
		}
		$since = sprintf(__ngettext('%s day', '%s days', $days), $days);
	}
	return $since;
}



function clean_url( $url, $protocols = null, $context = 'display' ) {
	$original_url = $url;

	if ('' == $url) return $url;
	$url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@]|i', '', $url);
	$strip = array('%0d', '%0a');
	$url = str_replace($strip, '', $url);
	$url = str_replace(';//', '://', $url);
	/* If the URL doesn't appear to contain a scheme, we
	 * presume it needs http:// appended (unless a relative
	 * link starting with / or a php file).
	*/
	if ( strpos($url, ':') === false &&
		substr( $url, 0, 1 ) != '/' && !preg_match('/^[a-z0-9-]+?\.php/i', $url) )
		$url = 'http://' . $url;

	// Replace ampersands ony when displaying.
	if ( 'display' == $context )
		$url = preg_replace('/&([^#])(?![a-z]{2,8};)/', '&#038;$1', $url);

	if ( !is_array($protocols) )
		$protocols = array('http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'gopher', 'nntp', 'feed', 'telnet');
	if ( wp_kses_bad_protocol( $url, $protocols ) != $url )
		return '';

	return apply_filters('clean_url', $url, $original_url, $context);
}

function sanitize_url( $url, $protocols = null ) {
	return clean_url( $url, $protocols, 'db');
}



// Escape single quotes, specialchar double quotes, and fix line endings.
function js_escape($text) {
	$safe_text = wp_specialchars($text, 'double');
	$safe_text = preg_replace('/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes($safe_text));
	$safe_text = preg_replace("/\r?\n/", "\\n", addslashes($safe_text));
	return apply_filters('js_escape', $safe_text, $text);
}

// Escaping for HTML attributes
function attribute_escape($text) {
	$safe_text = wp_specialchars($text, true);
	return apply_filters('attribute_escape', $safe_text, $text);
}


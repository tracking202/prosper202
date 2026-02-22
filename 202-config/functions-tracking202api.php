<?php

declare(strict_types=1);
function getUrl($url, $requestType = 'GET', $timeout = 30, $postArray = [])
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

	if ($requestType == "POST") {
		$postString = http_build_query($postArray);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postString);
	}

	$result = curl_exec($ch);
	curl_close($ch);
	return $result === false ? '' : $result;
}

function checkForApiErrors($array)
{

	//check to see if there were any errors
	$errors = $array['errors']['error'];
	if ($errors) {
		for ($x = 0; $x < count($errors); $x++) {
			$html = array_map(htmlentities(...), $errors[$x]);
			echo "<p>ErrorCode: {$html['errorCode']}<br/>";
			echo "ErrorMessage: {$html['errorMessage']}</p>";
		}
		die();
	}
}

function convertXmlIntoArray($xml)
{
	$element = @simplexml_load_string((string) $xml);
	if ($element === false) {
		return [];
	}
	return json_decode(json_encode($element), true);
}

if (!function_exists('http_build_query')) {
	function http_build_query($data, $prefix = '', $sep = '', $key = '')
	{
		$ret = [];
		foreach ((array)$data as $k => $v) {
			if (is_int($k) && $prefix != null) {
				$k = urlencode($prefix . $k);
			}
			if ((!empty($key)) || ($key === 0))  $k = $key . '[' . urlencode((string) $k) . ']';
			if (is_array($v) || is_object($v)) {
				array_push($ret, http_build_query($v, '', $sep, $k));
			} else {
				array_push($ret, $k . '=' . urlencode((string) $v));
			}
		}
		if (empty($sep)) $sep = ini_get('arg_separator.output');
		return implode($sep, $ret);
	} // http_build_query 
} //if 

function userPrefDate()
{
	$time = grab_timeframe();
	$date['from_date'] = date('Y-m-d', $time['from']);
	$date['to_date'] = date('Y-m-d', $time['to']);
	return $date;
}

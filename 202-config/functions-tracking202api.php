<?php

declare(strict_types=1);
function getUrl($url, $requestType = 'GET', $timeout = 30)
{

	$curl = new curl();
	$curl->setopt(CURLOPT_URL, $url);
	$curl->setopt(CURLOPT_RETURNTRANSFER, true);
	$curl->setopt(CURLOPT_TIMEOUT, $timeout);

	if ($requestType == "POST") {

		$postString = "";
		foreach ($postArray as $postField => $postValue) {
			$postString .= $postField . '=' . $postValue . '&';
		}
		$postString = rtrim($postString, '&');

		$curl->setopt(CURLOPT_POST, true);
		$curl->setopt(CURLOPT_POSTFIELDS, $postString);
	}

	$result = $curl->exec();
	$curl->close();
	return $result;
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
	$xmlToArray = new XmlToArray($xml);
	$arr = $xmlToArray->createArray();
	return $arr;
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

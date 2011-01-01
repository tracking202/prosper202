<?php

function getUrl($url, $requestType = 'GET', $timeout = 30) {
	
	$curl = new curl();
	$curl->curl($url);
	
	if( $requestType == "POST" ) {
	 
		$postString = "";
	 	foreach( $postArray as $postField => $postValue ) {
	  		$postString .= "$postField=".( $postValue )."&" ; 
	 	}
	 	$postString .= "Enter=";
	 
	   $curl->setopt( CURLOPT_POST, 1 );
	   $curl->setopt( CURLOPT_POSTFIELDS, $postString );
	}
	
	$curl->setopt( CURLOPT_FRESH_CONNECT, TRUE );
	$curl->setopt( CURLOPT_SSL_VERIFYPEER, FALSE );
	$curl->setopt( CURLOPT_USERAGENT, MAGPIE_USER_AGENT );
	$curl->setopt( CURLOPT_FOLLOWLOCATION, 1 );// allow redirects
	$curl->setopt( CURLOPT_RETURNTRANSFER, 1 ); // return into a variable
	$curl->setopt( CURLOPT_FORBID_REUSE, 1 ); 
	
	$curl->setopt( CURLOPT_TIMEOUT, $timeout ); // times out after x seconds
	
	$result = $curl->exec(); // run the whole process
	$curl->close();
	
	return $result;
}

function checkForApiErrors($array) {

	//check to see if there were any errors
	$errors = $array['errors']['error'];
	if ($errors) { 
		for ($x = 0; $x < count($errors); $x++) { 
			$html = array_map('htmlentities', $errors[$x]);
			echo "<p>ErrorCode: {$html['errorCode']}<br/>";
			echo "ErrorMessage: {$html['errorMessage']}</p>";
		}
		die();
	}
}

function convertXmlIntoArray($xml) {
	$xmlToArray = new XmlToArray($xml); 
	$arr = $xmlToArray->createArray(); 
	return $arr;
}

if (!function_exists('http_build_query')) { 
    function http_build_query($data, $prefix='', $sep='', $key='') { 
        $ret = array(); 
        foreach ((array)$data as $k => $v) { 
            if (is_int($k) && $prefix != null) { 
                $k = urlencode($prefix . $k); 
            } 
            if ((!empty($key)) || ($key === 0))  $k = $key.'['.urlencode($k).']'; 
            if (is_array($v) || is_object($v)) { 
                array_push($ret, http_build_query($v, '', $sep, $k)); 
            } else { 
                array_push($ret, $k.'='.urlencode($v)); 
            } 
        } 
        if (empty($sep)) $sep = ini_get('arg_separator.output'); 
        return implode($sep, $ret); 
    }// http_build_query 
}//if 

function userPrefDate() {
	$time = grab_timeframe();   
	$date['from_date'] = date('Y-m-d', $time['from']);
	$date['to_date'] = date('Y-m-d', $time['to']);
	return $date;
}


?>
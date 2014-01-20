<? 

class AUTH {
	
	
	function logged_in() {
		
		$session_time_passed = time() - $_SESSION['session_time'];

		if  ($_SESSION['user_name'] 
		AND $_SESSION['user_id'] 
		AND ($_SESSION['session_fingerprint'] == md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id()))
		AND ($session_time_passed < 50000)) {
			
			$_SESSION['session_time'] = time();
			return true;
			
		} else {
			
			return false;
			
		}
	}

	function require_user($auth_type='') {
		if (AUTH::logged_in() == false) {
			if($auth_type=="toolbar")
				$_SESSION['toolbar'] = 'true';
				//echo "what";
			die(include_once($_SERVER['DOCUMENT_ROOT']. '/202-access-denied.php'));
		}
		AUTH::set_timezone($_SESSION['user_timezone']);  
	}
	
	function require_valid_api_key() { 

		$user_api_key = $_SESSION['user_api_key'];
		if (AUTH::is_valid_api_key($user_api_key) == false) {
			header('location: /202-account/api-key-required.php'); die();
		}
	}
	
	function require_valid_app_key($appName, $user_api_key, $user_app_key) { 
		if (AUTH::is_valid_app_key($appName, $user_api_key, $user_app_key) == false) {
			header('location: /202-account/app-key-required.php'); die();
		}
	}
	
	
	//this checks if this api key is valid
	function is_valid_api_key($user_api_key) { 
		$url = TRACKING202_API_URL . "/auth/isValidApiKey?apiKey=$user_api_key";
		
		//check the tracking202 api authentication server
		$xml = getUrl($url);
		$isValidApiKey = convertXmlIntoArray($xml);
		$isValidApiKey = $isValidApiKey['isValidApiKey'];
		
		//returns true or false if it is a valid key
		if ($isValidApiKey['isValid'] == 'true') 	return true;
		else 									return false;
	}
	
	//this checks if the application key is valid
	function is_valid_app_key($appName, $user_api_key, $user_app_key) {
		if($user_app_key!='') {
			switch ($appName) {
				case "stats202": // check to make sure this is a valid stats202 app key
					$url = TRACKING202_API_URL . "/auth/isValidStats202AppKey?apiKey=$user_api_key&stats202AppKey=$user_app_key";
					$xml = getUrl($url); 
					$isValidStats202AppKey = convertXmlIntoArray($xml);
					$isValidStats202AppKey = $isValidStats202AppKey['isValidStats202AppKey'];
					
					if ($isValidStats202AppKey['isValid'] == 'true') {
						return true;
					} else {
						return false;
					}
				break;
			}
		}
		return false;
	}
	
	
	function set_timezone($user_timezone) {
		
		if (isset($_SESSION['user_timezone'])) { 
			$user_timezone = $_SESSION['user_timezone'];	
		}
		
		if ($user_timezone == '-12') { @putenv('TZ=NZS-12NZD'); } 
		if ($user_timezone == '-11') { @putenv('TZ=SST11'); }    
		if ($user_timezone == '-10') { @putenv('TZ=HST10HDT'); }    
		if ($user_timezone == '-9') { @putenv('TZ=AKS9AKD'); }    
		if ($user_timezone == '-8') { @putenv('TZ=PST8PDT'); }    
		if ($user_timezone == '-7') { @putenv('TZ=MST7MDT'); }    
		if ($user_timezone == '-6') { @putenv('TZ=CST6CDT'); }    
		if ($user_timezone == '-5') { @putenv('TZ=EST5EDT'); }      
		if ($user_timezone == '-4') { @putenv('TZ=AST4ADT'); }    
		if ($user_timezone == '-3.5') { @putenv('TZ=NST3:30NDT'); }    
		if ($user_timezone == '-3') { @putenv('TZ=BST3'); }    
		if ($user_timezone == '-2') { @putenv('TZ=FST2FDT'); }    
		if ($user_timezone == '0') { @putenv('TZ=Europe/London'); }    
		if ($user_timezone == '1') { @putenv('TZ=Europe/Paris'); }    
		if ($user_timezone == '2') { @putenv('TZ=Asia/Istanbul'); }    
		if ($user_timezone == '3') { @putenv('TZ=Asia/Kuwait'); }     
		if ($user_timezone == '3.5') { @putenv('TZ=Asia/Tehran'); }    
		if ($user_timezone == '5.5') { @putenv('TZ=IST-5:30'); }    
		if ($user_timezone == '7') { @putenv('TZ=Asia/Bangkok'); }    
		if ($user_timezone == '8') { @putenv('TZ=Asia/Hong_Kong'); }    
		if ($user_timezone == '9') { @putenv('TZ=Asia/Tokyo'); }    
		if ($user_timezone == '9.5') { @putenv('TZ=Australia/Darwin'); }    
		if ($user_timezone == '10') { @putenv('TZ=Australia/Sydney'); }    
		if ($user_timezone == '12') {  @putenv('TZ=Pacific/Auckland'); }     

	}
	
	
}
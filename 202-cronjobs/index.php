<?php ob_start();
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/class-dataengine.php');

set_time_limit(0);
ignore_user_abort(true);

//heres the psuedo cronjobs
if (RunSecondsCronjob() == true) { 
	if (RunHourlyCronJob() == true) { 
	    RunDailyCronjob();
	}
	AutoOptimizeDatabase();
	ClearOldClicks();
}else{
    AutoOptimizeDatabase();
    ClearOldClicks();
}

function RunDailyCronjob() {

	$database = DB::getInstance();
	$db = $database->getConnection();

	//check to run the daily cronjob
	$now = time();

	$today_day = date('j', $now);
	$today_month = date('n', $now);
	$today_year = date('Y', $now);

	//the click_time is recorded in the middle of the day
	$cronjob_time = mktime(12,0,0,$today_month,$today_day,$today_year);
	$mysql['cronjob_time'] = $db->real_escape_string($cronjob_time);
	$mysql['cronjob_type'] = $db->real_escape_string('daily');
	
	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='".$mysql['cronjob_type']."' AND cronjob_time='".$mysql['cronjob_time']."'";
	$check_result = _mysqli_query($check_sql);
	$check_count = $check_result->num_rows;      
	
	if ($check_count == 0 ) {
	    echo 'Processing Daily Jobs...';
	    ob_flush();
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = _mysqli_query($insert_sql);
		
		/* -------- THIS CLEARS OUT THE CLICK SPY MEMORY TABLE --------- */	
		//this function runs everyday at midnight to clear out the temp clicks_memory table
		$from = $now - 86400;
		
		//this makes it so we only have the most recent last 24 hour stuff, anything older, kill it.
		//we want to keep our SPY TABLE, low
		$click_sql = "DELETE FROM 202_clicks_spy WHERE click_time < $from";
		$click_result = _mysqli_query($click_sql);
				
		//clear the last 24 hour ip addresses
		$last_ip_sql = "DELETE FROM 202_last_ips WHERE time < $from";
		$last_ip_result = _mysqli_query($last_ip_sql);
		$last_ip_affected_rows = $last_ip_result->affected_rows;
		
		/* -------- THIS CLEARS OUT THE CHART TABLE --------- */	
		
		//$chart_sql = "DELETE FROM 202_charts";
		//$chart_result = _mysqli_query($chart_sql);
		//$chart_count = mysql_affected_rows(); */
		
		/* -------- NOW DELETE ALL THE OLD CRONJOB ENTRIES STUFF --------- */	
		$mysql['cronjob_time'] = $mysql['cronjob_time'] - 86400;
		$delete_sql = "DELETE FROM 202_cronjobs WHERE cronjob_time < ".$mysql['cronjob_time']."";
		$delete_result = _mysqli_query($delete_sql);
		echo 'Done';
		flush();
		return true;
	}  else {
		return false;	
	}

	$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".$now.")";
	$log_result = _mysqli_query($log_sql);
}



function RunHourlyCronJob() { 
 	$database = DB::getInstance();
	$db = $database->getConnection();

	//check to run the daily cronjob, not currently in-use
	$now = time();

	$today_day = date('j', $now);
	$today_month = date('n', $now);
	$today_year = date('Y', $now);
	$today_hour = date('G', $now);
	
	//the click_time is recorded in the middle of the day
	$cronjob_time = mktime($today_hour,0,0,$today_month,$today_day,$today_year);
	$mysql['cronjob_time'] = $db->real_escape_string($cronjob_time);
	$mysql['cronjob_type'] = $db->real_escape_string('hour');
	
	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='".$mysql['cronjob_type']."' AND cronjob_time='".$mysql['cronjob_time']."'";
	$check_result = _mysqli_query($check_sql);
	$check_count = $check_result->num_rows;      
	
	if ($check_count == 0 ) {
	    echo 'Processing Daily Jobs...';
	    ob_flush();
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = _mysqli_query($insert_sql);
		echo 'Done<br>';
		ob_flush();
		return true;
	}  else {
		return false;	
	}

	$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".$now.")";
	$log_result = _mysqli_query($log_sql);
}


function RunSecondsCronjob() { 

	$database = DB::getInstance();
	$db = $database->getConnection();
	
//check to run the 1minute cronjob, change this to every minute
	$now = time();

	$everySeconds = 1;

//check to run the 1minute cronjob, change this to every minute

	$today_second = date('s', $now);
	$today_minute = date('i', $now);
	$today_hour = date('G', $now);
	$today_day = date('j', $now);
	$today_month = date('n', $now);
	$today_year = date('Y', $now);
	
	$today_second = ceil($today_second / $everySeconds);
	if ($today_second == 0) $today_second++;
	
	//the click_time is recorded in the middle of the day
	$cronjob_time = mktime($today_hour,$today_minute,$today_second,$today_month,$today_day,$today_year);
	
	$mysql['cronjob_time'] = $db->real_escape_string($cronjob_time);
	$mysql['cronjob_type'] = $db->real_escape_string('secon');
	
	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='".$mysql['cronjob_type']."' AND cronjob_time='".$mysql['cronjob_time']."'";
	$check_result = $db->query($check_sql) or record_mysql_error($check_sql);
	$check_count = $check_result->num_rows;  

	if ($check_count == 0 ) {
	    echo 'Processing Seconds Jobs...';
	    ob_flush();
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = $db->query($insert_sql);
		
		/* -------- THIS RUNS THE DELAYED QUERIES --------- */	

		$delayed_sql = "
			SELECT delayed_sql
			FROM 202_delayed_sqls
			WHERE delayed_time <=".$now."
		";
		$delayed_result = _mysqli_query($delayed_sql);
		while ($delayed_row = $delayed_result->fetch_assoc())  {
		
			//run each sql
			$update_sql = $delayed_row['delayed_sql'];
			$update_result = _mysqli_query($update_sql);
			
		}
		
		//delete all old delayed sqls
		$delayed_sql = "DELETE FROM 202_delayed_sqls WHERE delayed_time <=".$now;
		$delayed_result = _mysqli_query($delayed_sql);

		$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".$now.")";
		$log_result = _mysqli_query($log_sql);

		$de = new DataEngine();
		$de->processDirtyHours();

		$de->processClickUpgrade();

		echo 'Done<br>';
		ob_flush();
		return true;
	}  else {
		return false;	
	}
}

function AutoOptimizeDatabase() {    
	$database = DB::getInstance();
	$db = $database->getConnection();

	$sql = "SELECT user_auto_database_optimization_days FROM 202_users_pref where user_id = 1";
	$result = $db->query($sql);
	$row = $result->fetch_assoc();

	if ($row['user_auto_database_optimization_days']) {
		$date_to = date('Y-m-d', strtotime('-1 days', strtotime(date("Y-m-d"))));
		$date_to = $date_to.' 23:59:59';

		$date_from = date('Y-m-d', strtotime('-'.$row['user_auto_database_optimization_days'].' days', strtotime($date_to)));
		$date_from = $date_from.' 23:59:59';
		$to = strtotime($date_from);

		

		{
		    echo " Processing Auto DB Delete -";
		    flush();
		    ob_flush();
			
			$tables = is_array($tables) ? $tables : explode(',','202_clicks,202_clicks_advance,202_clicks_record,202_clicks_site,202_clicks_spy,202_clicks_tracking,202_dataengine,202_google,202_bing,202_clicks_variable');
			if (isset($mysql['click_id'])) {
			    foreach ($tables as $table) {
			
			        $click_sql = "DELETE FROM $table WHERE click_id < ".$mysql['click_id']." LIMIT 10000";
			        $db->query($click_sql);
			    }
			}	
			echo 'Done Processing Batch<br>';
			ob_flush();
		}
	}
}

function ClearOldClicks(){
    $database = DB::getInstance();
    $db = $database->getConnection();
    $mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
    
    $sql ="SELECT user_delete_data_clickid from 202_users_pref WHERE user_id = '".$mysql['user_own_id']."'";
    $db->query($sql);
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    

    if ($result->num_rows > 0) {
        echo " Processing Clear Old Clicks...";
        $mysql['click_id']= $db->real_escape_string($row['user_delete_data_clickid']);
        
        $tables = is_array($tables) ? $tables : explode(',','202_clicks,202_clicks_advance,202_clicks_record,202_clicks_site,202_clicks_spy,202_clicks_tracking,202_dataengine,202_google,202_bing,202_clicks_variable');
        if (isset($mysql['click_id'])) {
         foreach ($tables as $table) {
        
         $click_sql = "DELETE FROM $table WHERE click_id < ".$mysql['click_id']." LIMIT 5000";
        
         $db->query($click_sql);
         }
         }
        
         if ($slack)
             $slack->push('click_data_deleted', array('user' => $username, 'date' => $_POST['database_management'])); 
         echo 'Done Processing Batch<br>';
		ob_flush();
    }
    
}
ob_end_flush();
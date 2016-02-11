<?php include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/connect.php'); 
include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/class-dataengine.php');
set_time_limit(0);

//heres the psuedo cronjobs
if (RunSecondsCronjob() == true) { 
	if (RunHourlyCronJob() == true) { 
		RunDailyCronjob();	
	}
}

function RunDailyCronjob() {

	$database = DB::getInstance();
	$db = $database->getConnection();

	//check to run the daily cronjob
	$now = time();

	$today_day = date('j', time());
	$today_month = date('n', time());
	$today_year = date('Y', time());

	//the click_time is recorded in the middle of the day
	$cronjob_time = mktime(12,0,0,$today_month,$today_day,$today_year);
	$mysql['cronjob_time'] = $db->real_escape_string($cronjob_time);
	$mysql['cronjob_type'] = $db->real_escape_string('daily');
	
	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='".$mysql['cronjob_type']."' AND cronjob_time='".$mysql['cronjob_time']."'";
	$check_result = _mysqli_query($check_sql);
	$check_count = $check_result->num_rows;      
	
	if ($check_count == 0 ) {
		
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = _mysqli_query($insert_sql);
		
		/* -------- THIS CLEARS OUT THE CLICK SPY MEMORY TABLE --------- */	
		//this function runs everyday at midnight to clear out the temp clicks_memory table
		$from = time() - 86400;
		
		//this makes it so we only have the most recent last 24 hour stuff, anything older, kill it.
		//we want to keep our SPY TABLE, low
		$click_sql = "DELETE FROM 202_clicks_spy WHERE click_time < $from";
		$click_result = _mysqli_query($click_sql);
		
		//Clear out the 202_clicks_counter table
		//we want to keep our SPY TABLE, low
		$click_sql = "DELETE FROM 202_clicks_counter";
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

		return true;
	}  else {
		return false;	
	}

	$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".time().")";
	$log_result = _mysqli_query($log_sql);
}



function RunHourlyCronJob() { 

	$database = DB::getInstance();
	$db = $database->getConnection();

	//check to run the daily cronjob, not currently in-use
	$now = time();

	$today_day = date('j', time());
	$today_month = date('n', time());
	$today_year = date('Y', time());
	$today_hour = date('G', time());
	
	//the click_time is recorded in the middle of the day
	$cronjob_time = mktime($today_hour,0,0,$today_month,$today_day,$today_year);
	$mysql['cronjob_time'] = $db->real_escape_string($cronjob_time);
	$mysql['cronjob_type'] = $db->real_escape_string('hour');
	
	//check to make sure this click_summary doesn't already exist
	$check_sql = "SELECT  *  FROM 202_cronjobs WHERE cronjob_type='".$mysql['cronjob_type']."' AND cronjob_time='".$mysql['cronjob_time']."'";
	$check_result = _mysqli_query($check_sql);
	$check_count = $check_result->num_rows;      
	
	if ($check_count == 0 ) {
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = _mysqli_query($insert_sql);

		return true;
	}  else {
		return false;	
	}

	$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".time().")";
	$log_result = _mysqli_query($log_sql);
}


function RunSecondsCronjob() { 

	$database = DB::getInstance();
	$db = $database->getConnection();
	
//check to run the 1minute cronjob, change this to every minute
	$now = time();

	$everySeconds = 20;

//check to run the 1minute cronjob, change this to every minute
	$now = time();

	$today_second = date('s', time());
	$today_minute = date('i', time());
	$today_hour = date('G', time());
	$today_day = date('j', time());
	$today_month = date('n', time());
	$today_year = date('Y', time());
	
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
		
		//if a cronjob hasn't run today, record it now.
		$insert_sql = "INSERT INTO 202_cronjobs SET cronjob_type='".$mysql['cronjob_type']."', cronjob_time='".$mysql['cronjob_time']."'";
		$insert_result = $db->query($insert_sql);
		
		/* -------- THIS RUNS THE DELAYED QUERIES --------- */	

		$delayed_sql = "
			SELECT delayed_sql
			FROM 202_delayed_sqls
			WHERE delayed_time <=".time()."
		";
		$delayed_result = _mysqli_query($delayed_sql);
		while ($delayed_row = $delayed_result->fetch_assoc())  {
		
			//run each sql
			$update_sql = $delayed_row['delayed_sql'];
			$update_result = _mysqli_query($update_sql);
			
		}
		
		//delete all old delayed sqls
		$delayed_sql = "DELETE FROM 202_delayed_sqls WHERE delayed_time <=".time();
		$delayed_result = _mysqli_query($delayed_sql);

		$log_sql = "REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ".time().")";
		$log_result = _mysqli_query($log_sql);

		$de = new DataEngine();
		$de->processDirtyHours();

		$de->processClickUpgrade();

		
		return true;
	}  else {
		return false;	
	}
}

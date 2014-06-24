<?php
  
  
class SessionManager {

   var $life_time;

   function SessionManager() {
	  
	   //$session_maxlifetime = get_cfg_var("session.gc_maxlifetime");
	   $session_maxlifetime = 43200;
	  
	  // Read the maxlifetime setting from PHP
	  $this->life_time = $session_maxlifetime;
	  
	  // Register this object as the session handler
	  session_set_save_handler( 
		array( &$this, "open" ), 
		array( &$this, "close" ),
		array( &$this, "read" ),
		array( &$this, "write"),
		array( &$this, "destroy"),
		array( &$this, "gc" )
	  );

   }

   function open( $save_path, $session_name ) {

	  global $sess_save_path;

	  $sess_save_path = $save_path;

	  // Don't need to do anything. Just return TRUE.

	  return true;

   }

   function close() {

	  return true;

   }

   function read( $id ) {

	  // Set empty result
	  $data = '';

	  // Fetch session data from the selected database

	  $time = time();

	  $newid = mysql_real_escape_string($id);
	  $sql = "SELECT `session_data` FROM `202_sessions` WHERE `session_id` = '$newid' AND `expires` > $time";

	  $rs = mysql_query($sql);                           
	  $a = mysql_num_rows($rs);

	  if($a > 0) {
		$row = mysql_fetch_assoc($rs);
		$data = $row['session_data'];

	  }

	  return $data;

   }

   function write( $id, $data ) {

	  // Build query                
	  $time = time() + $this->life_time;

	  $newid = mysql_real_escape_string($id);
	  $newdata = mysql_real_escape_string($data);

	  $sql = "REPLACE `202_sessions` (`session_id`,`session_data`,`expires`) VALUES('$newid', '$newdata', $time)";

	  $rs = mysql_query($sql);

	  return TRUE;

   }

   function destroy( $id ) {

	  // Build query
	  $newid = mysql_real_escape_string($id);
	  $sql = "DELETE FROM `202_sessions` WHERE `session_id` =
'$newid'";

	  mysql_query($sql);

	  return TRUE;

   }

   function gc() {

	  // Garbage Collection

					   

	  // Build DELETE query.  Delete all records who have passedthe expiration time
	  $sql = 'DELETE FROM `202_sessions` WHERE `expires` < UNIX_TIMESTAMP();';

	  mysql_query($sql);

	  // Always return TRUE
	  return true;

   }

}

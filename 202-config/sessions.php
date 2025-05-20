<?php

declare(strict_types=1);
class SessionManager
{

	var $life_time;

	// PHP4-style constructor replaced with __construct for PHP 7+
	function __construct()
	{
		//$session_maxlifetime = get_cfg_var("session.gc_maxlifetime");
		$session_maxlifetime = 43200;
		$this->life_time = $session_maxlifetime;
		session_set_save_handler(
			array(&$this, "open"),
			array(&$this, "close"),
			array(&$this, "read"),
			array(&$this, "write"),
			array(&$this, "destroy"),
			array(&$this, "gc")
		);
	}

	function open($save_path, $session_name)
	{

		global $sess_save_path;

		$sess_save_path = $save_path;

		// Don't need to do anything. Just return TRUE.

		return true;
	}

	function close()
	{

		return true;
	}

	function read($id)
	{
		$data = '';
		$db = DB::getInstance()->getConnection();
		$id = $db->real_escape_string($id);
		$sql = "SELECT session_data FROM 202_sessions WHERE session_id = '$id' AND expires > UNIX_TIMESTAMP()";
		$rs = $db->query($sql);
		if ($rs && $rs->num_rows > 0) {
			$row = $rs->fetch_assoc();
			$data = $row['session_data'];
		}
		return $data;
	}

	function write($id, $data)
	{
		$db = DB::getInstance()->getConnection();
		$time = time() + $this->life_time;
		$id = $db->real_escape_string($id);
		$data = $db->real_escape_string($data);
		$sql = "REPLACE INTO 202_sessions (session_id, session_data, expires) VALUES('$id', '$data', $time)";
		$db->query($sql);
		return true;
	}

	function destroy($id)
	{
		$db = DB::getInstance()->getConnection();
		$id = $db->real_escape_string($id);
		$sql = "DELETE FROM 202_sessions WHERE session_id = '$id'";
		$db->query($sql);
		return true;
	}

	function gc()
	{
		$db = DB::getInstance()->getConnection();
		$sql = 'DELETE FROM 202_sessions WHERE expires < UNIX_TIMESTAMP()';
		$db->query($sql);
		return true;
	}
}

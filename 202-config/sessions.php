<?php

declare(strict_types=1);
class SessionManager
{

	public $life_time;

	// PHP4-style constructor replaced with __construct for PHP 7+
	function __construct()
	{
		//$session_maxlifetime = get_cfg_var("session.gc_maxlifetime");
		$session_maxlifetime = 43200;
		$this->life_time = $session_maxlifetime;
		session_set_save_handler(
			$this->open(...),
			$this->close(...),
			$this->read(...),
			$this->write(...),
			$this->destroy(...),
			$this->gc(...)
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
        // Thrown, session_start() throws and nothing is written back. '' is
        // no session, which PHP writes back over the stored one when the
        // request ends; false only warns, and the page reads the visitor as
        // signed out.
        if ($rs === false) {
            throw self::failed($db);
        }
        $row = p202FetchRow($rs, $sql);
        if ($row !== null) {
            $data = (string) $row['session_data'];
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
        // PHP warns that the session could not be written, and the stored
        // one stays as it was.
        if ($db->query($sql) === false) {
            p202LogFailedQuery($db, $sql, ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }
        return true;
	}

	function destroy($id)
	{
		$db = DB::getInstance()->getConnection();
		$id = $db->real_escape_string($id);
		$sql = "DELETE FROM 202_sessions WHERE session_id = '$id'";
        // Not false: session_destroy() would answer it with a warning that
        // sign-out never reads, and the row it left signs the cookie in again.
        if ($db->query($sql) === false) {
            throw self::failed($db);
        }
        return true;
	}

	function gc()
	{
		$db = DB::getInstance()->getConnection();
		$sql = 'DELETE FROM 202_sessions WHERE expires < UNIX_TIMESTAMP()';
        // read() takes no expired row, and the next collection deletes them.
        if ($db->query($sql) === false) {
            p202LogFailedQuery($db, $sql, ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }
        return true;
	}

    private static function failed(\mysqli $db): \Prosper202\Database\Exceptions\QueryException
    {
        return new \Prosper202\Database\Exceptions\QueryException('MySQL query failed: '
            . p202FailedQueryMessage($db->errno, $db->error) . ' [errno ' . $db->errno . ']');
    }
}

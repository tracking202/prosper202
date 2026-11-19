<?php
declare(strict_types=1);
// ** MySQL settings** //
$dbname = 'prosper202_test_install'; // The name of the database
$dbuser = 'root'; // Your MySQL username
$dbpass = 'root_password'; // ...and password
$dbhost = 'db-test'; // 99% chance you won't need to change this value
$dbhostro = 'db-test'; // Only change this to use a read replica for reading data
$mchost = 'memcached'; // this is the memcache server host, if you don't know what this is, don't touch it.

/*---DONT EDIT ANYTHING BELOW THIS LINE!---*/

//Database connection class
class DB {
        private $_connection,$_connectionro;
        private static $_instance; //The single instance

        /*
        Get an instance of the Database
        @return Instance
        */
        public static function getInstance() {
                if(!self::$_instance) { // If no instance then make one
                       self::$_instance = new self();
                }
                return self::$_instance;
        }

        // Constructor

        // The primary connection only: a click never reads through the read
        // connection, and every connection held counts against MySQL's
        // max_connections. getConnectionro() opens that one when it is asked for.
        private function __construct() {
                global $dbhost;
                global $dbuser;
                global $dbpass;
                global $dbname;

                $this->_connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        }

        // Magic method clone is empty to prevent duplication of connection
        private function __clone() { }

        // Get mysqli connection
        public function getConnection() {
                return $this->_connection;
        }

        // Get mysqli ro connection: the primary when $dbhostro names no other
        // server, else the replica, opened the first time it is asked for. A
        // replica that cannot be reached is logged and read through the primary.
        public function getConnectionro() {
                global $dbhost,$dbhostro;
                global $dbuser;
                global $dbpass;
                global $dbname;

                if ($this->_connectionro === null) {
                        $this->_connectionro = $this->_connection;
                        if (is_string($dbhostro) && $dbhostro !== '' && $dbhostro !== $dbhost) {
                                try {
                                        $replica = @new mysqli($dbhostro, $dbuser, $dbpass, $dbname);
                                        if ($replica->connect_errno === 0) {
                                                $this->_connectionro = $replica;
                                        } else {
                                                error_log('Prosper202: the read replica could not be reached (' . $replica->connect_error . '); reading from the primary.');
                                        }
                                } catch (Throwable $e) {
                                        error_log('Prosper202: the read replica could not be reached (' . $e->getMessage() . '); reading from the primary.');
                                }
                        }
                }
                return $this->_connectionro;
        }
}

try {
        $database = DB::getInstance();
        $db = $database->getConnection();
} catch (Exception) {
        $db = false;
}

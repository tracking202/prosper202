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

        private function __construct() {
                global $dbhost,$dbhostro;
                global $dbuser;
                global $dbpass;
                global $dbname;

                $this->_connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
                $this->_connectionro = new mysqli($dbhostro, $dbuser, $dbpass, $dbname);
        }

        // Magic method clone is empty to prevent duplication of connection
        private function __clone() { }

        // Get mysqli connection
        public function getConnection() {
                return $this->_connection;
        }

        // Get mysqli ro connection
        public function getConnectionro() {
            return $this->_connectionro;
        }
}

try {
        $database = DB::getInstance();
        $db = $database->getConnection();
        $dbro = $database->getConnectionro();
} catch (Exception) {
        $db = false;
        $dbro = false;
}

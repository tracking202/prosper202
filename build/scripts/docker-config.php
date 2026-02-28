<?php
declare(strict_types=1);
// Docker environment database configuration
$dbname = 'prosper202';
$dbuser = 'p202user';
$dbpass = 'p202pass';
$dbhost = 'db';
$dbhostro = 'db';  // same host in Docker (no replica)
$mchost = 'localhost';

/*---DONT EDIT ANYTHING BELOW THIS LINE!---*/

//Database connection class
class DB {
        private $_connection,$_connectionro;
        private static $_instance; //The single instance

        public static function getInstance() {
                if(!self::$_instance) {
                       self::$_instance = new self();
                }
                return self::$_instance;
        }

        private function __construct() {
                global $dbhost,$dbhostro;
                global $dbuser;
                global $dbpass;
                global $dbname;

                $this->_connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
                $this->_connectionro = new mysqli($dbhostro, $dbuser, $dbpass, $dbname);
        }

        private function __clone() { }

        public function getConnection() {
                return $this->_connection;
        }

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

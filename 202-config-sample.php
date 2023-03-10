<?php

// ** MySQL settings** //
$dbname = 'putyourdbnamehere'; // The name of the database
$dbuser = 'usernamehere'; // Your MySQL username
$dbpass = 'yourpasswordhere'; // ...and password
$dbhost = 'localhost'; // 99% chance you won't need to change this value
$dbhostro = 'localhost'; // Only change this to use a read replica for reading data
$mchost = 'localhost'; // this is the memcache server host, if you don't know what this is, don't touch it.

/*---DONT EDIT ANYTHING BELOW THIS LINE!---*/

//Database conncetion class
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
} catch (Exception $e) {
        $db = false;
        $dbro = false;
}
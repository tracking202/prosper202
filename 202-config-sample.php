<?php
declare(strict_types=1);
// ** MySQL settings** //
$dbname = 'putyourdbnamehere'; // The name of the database
$dbuser = 'usernamehere'; // Your MySQL username
$dbpass = 'yourpasswordhere'; // ...and password
$dbhost = 'localhosthere'; // 99% chance you won't need to change this value
$dbhostro = 'localhostreplica'; // Only change this to use a read replica for reading data
$mchost = 'localhostmemcache'; // this is the memcache server host, if you don't know what this is, don't touch it.

// ** Optional: JSON report transport ** //
// Off by default. When enabled, tracking202/ajax/report_dispatch.php answers flat
// report requests as JSON. The Analyze reports themselves render server-side
// whatever this says. Uncomment to enable:
//
// NOTE: these define() calls must come *after* the declare(strict_types=1) above —
// declare must be the first statement in the file or PHP fatals on load.
//
// define('TRACKING202_JSON_ARCHITECTURE_ENABLED', true);

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
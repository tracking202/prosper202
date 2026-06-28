<?php
declare(strict_types=1);
// Sample 202-config.php for the staging Docker stack (docker-compose.staging.yml).
//
//   cp build/staging-config.sample.php build/staging-config.php
//
// staging-config.php is gitignored; this committed sample keeps the staging
// stack one command, like build/test-install-config.php does for test-install.
// The defaults below already match the db2 service in docker-compose.staging.yml,
// so the copy works as-is. These credentials are throwaway container defaults
// reachable only inside the compose network — not secrets.

// ** MySQL settings for staging instance ** //
$dbname = 'prosper202_staging'; // Staging database (matches db2 MYSQL_DATABASE)
$dbuser = 'root'; // Your MySQL username
$dbpass = 'root_password'; // ...and password (matches db2 MYSQL_ROOT_PASSWORD)
$dbhost = 'db2'; // Staging MySQL container
$dbhostro = 'db2'; // Read replica (same as primary for staging)
$mchost = 'memcached'; // Shared memcache server

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

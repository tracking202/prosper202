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
// Off by default. When enabled, the Analyze reports fetch their data as JSON from
// tracking202/ajax/report_dispatch.php and render client-side, and the bounded filter
// dropdowns (country/region/isp/device/browser/platform) are rendered server-side
// instead of via six AJAX round-trips. Any transport error falls back to the legacy
// HTML path automatically, so this is safe to toggle. Uncomment to enable:
//
// NOTE: these define() calls must come *after* the declare(strict_types=1) above —
// declare must be the first statement in the file or PHP fatals on load.
//
// define('TRACKING202_JSON_ARCHITECTURE_ENABLED', true);
//
// Guardrails for the server-rendered filters. A dropdown larger than MAX_OPTIONS is
// left on the legacy AJAX path, and if the six of them together would exceed MAX_BYTES
// of HTML they are all left on it — a big ISP or region table should not bloat the page.
// define('TRACKING202_STATIC_FILTER_SSR_MAX_OPTIONS', 1500);
// define('TRACKING202_STATIC_FILTER_SSR_MAX_BYTES', 65536);

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
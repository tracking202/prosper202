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

// ** Optional: Conversion Logs' Download CSV ** //
// A download holds the newest 100,000 conversions of the list. To hold fewer
// (a small server), uncomment and set a smaller whole number:
//
// define('P202_CONVERSION_LOGS_CSV_MAX', 50000);

// ** Optional: the integration secrets key ** //
// The key that seals the integration secrets (documentation/features/integration-secrets.md),
// kept outside the database: a file holding 64 hexadecimal characters (openssl rand -hex 32).
// Unset, a key kept in the database is used. Uncomment to use one:
//
// define('P202_SECRETS_KEY_FILE', '/etc/prosper202/secrets.key');

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
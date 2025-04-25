<?php

class User
{
    private static $db;
    private $userRoles; // Added property definition

    public function __construct($user_id)
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
            return; // Exit constructor if DB connection fails
        }

        // Skip loading roles during installation
        if (
            strpos($_SERVER['PHP_SELF'], '202-config/install.php') !== false ||
            strpos($_SERVER['PHP_SELF'], '202-config/get_apikey.php') !== false
        ) {
            return;
        }

        $this->userRoles = array();

        try {
            $mysql['user_id'] = self::$db->real_escape_string($user_id);
            $sql = "SELECT user_id FROM 202_users WHERE user_id = '" . $mysql['user_id'] . "'";
            $results = self::$db->query($sql);
            if ($results && $results->num_rows) {
                $row = $results->fetch_assoc();
                $this->loadRoles($row['user_id']);
            }
        } catch (mysqli_sql_exception $e) {
            // Table doesn't exist yet or other DB error, just return
            return;
        }
    }

    protected function loadRoles($user_id)
    {
        // Ensure $db is a valid mysqli object
        if (!(self::$db instanceof mysqli)) {
            return; // Skip if database connection failed or is not a mysqli object
        }

        $mysql['user_id'] = self::$db->real_escape_string($user_id);
        $sql = "SELECT 2ur.role_id, 2r.role_name FROM 202_user_role AS 2ur INNER JOIN 202_roles AS 2r ON 2ur.role_id = 2r.role_id WHERE 2ur.user_id = '" . $mysql['user_id'] . "'";
        $results = self::$db->query($sql);

        if ($results && $results->num_rows > 0) {
            while ($row = $results->fetch_assoc()) {
                $this->userRoles[$row["role_name"]] = Role::getRole($row["role_id"]);
            }
        }
    }

    public function hasPermission($permission)
    {
        if (!isset($this->userRoles) || !is_array($this->userRoles)) {
            return false;
        }

        foreach ($this->userRoles as $role) {
            if ($role && $role->verifyPermission($permission)) {
                return true;
            }
        }
        return false;
    }
}

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
        } catch (Exception) {
            self::$db = false;
            return; // Exit constructor if DB connection fails
        }

        // Skip loading roles during installation, and on the upgrade page,
        // whose schema may predate the roles tables (the 1.9.4 rung) and
        // which asks no permission.
        if (
            str_contains((string) $_SERVER['PHP_SELF'], '202-config/install.php') ||
            str_contains((string) $_SERVER['PHP_SELF'], '202-config/get_apikey.php') ||
            str_contains((string) $_SERVER['PHP_SELF'], '202-config/upgrade.php')
        ) {
            return;
        }

        $this->userRoles = [];

        // A read that fails throws, so no page answers as though the user had
        // no roles: connect.php's handler records it and shows the database
        // error page. Only a missing 202_users (1146) answers, as
        // is_installed() reads it: not installed, so no account (index.php
        // and the setup pages load connect.php before the tables exist).
        $db = self::$db;
        $mysql['user_id'] = $db->real_escape_string((string) $user_id);
        $sql = "SELECT user_id FROM 202_users WHERE user_id = '" . $mysql['user_id'] . "'";
        $results = $db->query($sql);
        if ($results === false) {
            if ($db->errno !== 1146) {
                throw self::queryFailed($db);
            }
            return;
        }
        $row = p202FetchRow($results, $sql);
        if ($row !== null) {
            $this->loadRoles($row['user_id']);
        }
    }

    protected function loadRoles($user_id)
    {
        // Ensure $db is a valid mysqli object
        if (!(self::$db instanceof mysqli)) {
            return; // Skip if database connection failed or is not a mysqli object
        }

        $db = self::$db;
        $mysql['user_id'] = $db->real_escape_string((string) $user_id);
        $sql = "SELECT 2ur.role_id, 2r.role_name FROM 202_user_role AS 2ur INNER JOIN 202_roles AS 2r ON 2ur.role_id = 2r.role_id WHERE 2ur.user_id = '" . $mysql['user_id'] . "'";
        $results = $db->query($sql);
        if ($results === false) {
            throw self::queryFailed($db);
        }

        while ($row = $results->fetch_assoc()) {
            $this->userRoles[$row["role_name"]] = Role::getRole($row["role_id"]);
        }
    }

    private static function queryFailed(mysqli $db): \Prosper202\Database\Exceptions\QueryException
    {
        return new \Prosper202\Database\Exceptions\QueryException('MySQL query failed: '
            . p202FailedQueryMessage($db->errno, $db->error) . ' [errno ' . $db->errno . ']');
    }

    public function hasPermission($permission)
    {
        if (!isset($this->userRoles) || !is_array($this->userRoles)) {
            return false;
        }
        return array_any($this->userRoles, fn($role) => $role && $role->verifyPermission($permission));
    }
}

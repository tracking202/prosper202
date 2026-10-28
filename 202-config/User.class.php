<?php

class User
{
    private static $db;
    private $userRoles; // Added property definition

    /** The user this object was built for, when its row was read. */
    private ?int $userId = null;

    /** Whether that user may act, read with the row; null when no row was read. */
    private ?\Prosper202\User\UserStanding $standing = null;

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

        // No session, no user to read.
        $id = filter_var($user_id, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            return;
        }

        // A read that fails throws, so no page answers as though the user had
        // no roles, nor as though they may or may not act: connect.php's
        // handler records it and shows the database error page. Only a
        // missing 202_users (1146) answers, as is_installed() reads it: not
        // installed, so no account (index.php and the setup pages load
        // connect.php before the tables exist).
        try {
            $standing = \Prosper202\User\UserStanding::read(self::$db, $id);
        } catch (\Prosper202\Database\Exceptions\QueryException $e) {
            if ($e->getCode() !== 1146) {
                throw $e;
            }
            return;
        }
        $this->userId = $id;
        $this->standing = $standing;
        // A user who may not act holds no permission, whatever their roles.
        if ($standing->canAct()) {
            $this->loadRoles($id);
        }
    }

    /**
     * Whether the user may act, as read when this object was built, for that
     * user only; null for another user, or when no row was read (no session,
     * the install, key and upgrade pages, no 202_users yet). AUTH reads the
     * session's user here rather than asking the database again.
     */
    public function standingOf(int $userId): ?\Prosper202\User\UserStanding
    {
        return $this->userId === $userId ? $this->standing : null;
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

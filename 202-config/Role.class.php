<?php
declare(strict_types=1);
class Role
{
    private static $db;
    protected array $permissionList = [];
    
    protected function __construct()
    {   
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception) {
            self::$db = false;
        }

        $this->permissionList = [];
    }
 
    public static function getRole($role_id)
    {
        $role = new Role();
        
        $db = self::$db;
        $mysql['role_id'] = $db->real_escape_string((string) $role_id);
        $sql = "SELECT 2p.permission_description FROM 202_role_permission AS 2rp INNER JOIN 202_permissions AS 2p ON 2rp.permission_id = 2p.permission_id WHERE 2rp.role_id = '".$mysql['role_id']."'";
        $results = $db->query($sql);
        // A failed read is not a role without permissions: thrown, as User's are.
        if ($results === false) {
            throw new \Prosper202\Database\Exceptions\QueryException('MySQL query failed: '
                . p202FailedQueryMessage($db->errno, $db->error) . ' [errno ' . $db->errno . ']');
        }

        while($row = $results->fetch_assoc())
        {
            $role->permissionList[$row["permission_description"]] = true;
        }

        return $role;
    }
 
    public function verifyPermission($permission)
    {
        return isset($this->permissionList[$permission]);
    }
}
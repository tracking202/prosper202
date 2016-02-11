<?php

class Role
{   
    private static $db;
    
    protected function __construct()
    {   
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }

        $this->permissionList = array();
    }
 
    public static function getRole($role_id)
    {
        $role = new Role();
        
        $mysql['role_id'] = self::$db->real_escape_string($role_id);
        $sql = "SELECT 2p.permission_description FROM 202_role_permission AS 2rp INNER JOIN 202_permissions AS 2p ON 2rp.permission_id = 2p.permission_id WHERE 2rp.role_id = '".$mysql['role_id']."'";
        $results = self::$db->query($sql);
        
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
?>
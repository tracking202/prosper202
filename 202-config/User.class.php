<?php

class User 
 {
 	private static $db;

 	public function __construct($user_id)
 	{	
 		try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }

        $this->userRoles = array();

 		$mysql['user_id'] = self::$db->real_escape_string($user_id);
 		$sql = "SELECT user_id FROM 202_users WHERE user_id = '".$mysql['user_id']."'";
	 	$results = self::$db->query($sql);
	 	if($results->num_rows)
	 	{
	 		$row = $results->fetch_assoc();
		 	self::loadRoles($row['user_id']);
	 	}
 	}
 	
    protected function loadRoles($user_id)
    {	
    	$mysql['user_id'] = self::$db->real_escape_string($user_id);
        $sql = "SELECT 2ur.role_id, 2r.role_name FROM 202_user_role AS 2ur INNER JOIN 202_roles AS 2r ON 2ur.role_id = 2r.role_id WHERE 2ur.user_id = '".$mysql['user_id']."'";
        $results = self::$db->query($sql);
        if ($results->num_rows > 0) {
            while($row = $results->fetch_assoc())
            {
                $this->userRoles[$row["role_name"]] = Role::getRole($row["role_id"]);
            }
        }
        
    }
 
    public function hasPermission($permission)
    {   
        foreach ($this->userRoles as $role)
        {
            if ($role->verifyPermission($permission))
            {   
                return true;
            }
        }
        return false;
    }
}
?>
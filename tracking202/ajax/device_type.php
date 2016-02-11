<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

?>

<select class="form-control input-sm" name="device_id" id="device_id">
    <option value="0"> -- </option>
	<?php 		$device_sql = "SELECT *
                        FROM    202_device_types
                        ORDER BY `type_name` ASC";
        $device_result = $db->query($device_sql) or record_mysql_error($device_sql);

        while ($device_row = $device_result->fetch_array(MYSQLI_ASSOC)) {
            
            $html['type_name'] = htmlentities($device_row['type_name'], ENT_QUOTES, 'UTF-8');
            $html['type_id'] = htmlentities($device_row['type_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['device_id'] == $device_row['type_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['type_id'],$html['type_name']);

        } ?>
</select>
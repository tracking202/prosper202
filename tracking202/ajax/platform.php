<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();
?>

<select class="form-control input-sm" name="platform_id" id="platform_id">
    <option value="0"> -- </option>
	<?php 		$platform_sql = "SELECT *
                        FROM    202_platforms
                        GROUP BY `platform_name` ORDER BY `platform_name` ASC";
        $platform_result = $db->query($platform_sql) or record_mysql_error($platform_sql);

        while ($platform_row = $platform_result->fetch_array(MYSQLI_ASSOC)) {

            $html['platform_name'] = htmlentities($platform_row['platform_name'], ENT_QUOTES, 'UTF-8');
            $html['platform_id'] = htmlentities($platform_row['platform_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['platform_id'] == $platform_row['platform_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['platform_id'],$html['platform_name']);

        } ?>
</select>
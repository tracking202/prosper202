<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();
?>

<select class="form-control input-sm" name="region_id" id="region_id">
    <option value="0"> -- </option>
	<?php 		$region_sql = "SELECT *
                        FROM    202_locations_region
                        GROUP BY `region_name` ORDER BY `region_name` ASC";
        $region_result = $db->query($region_sql) or record_mysql_error($region_sql);

        while ($region_row = $region_result->fetch_array(MYSQLI_ASSOC)) {
            
            $html['region_name'] = htmlentities($region_row['region_name'], ENT_QUOTES, 'UTF-8');
            $html['region_id'] = htmlentities($region_row['region_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['region_id'] == $region_row['region_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['region_id'],$html['region_name']);

        } ?>
</select>
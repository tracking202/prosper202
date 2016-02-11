<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

?>

<select class="form-control input-sm" name="country_id" id="country_id">
    <option value="0"> -- </option>
	<?php 		$country_sql = "SELECT *
                        FROM    202_locations_country
                        GROUP BY `country_name` ORDER BY `country_name` ASC";
        $country_result = $db->query($country_sql) or record_mysql_error($country_sql);

        while ($country_row = $country_result->fetch_array(MYSQLI_ASSOC)) {
            
            $html['country_name'] = htmlentities($country_row['country_name'], ENT_QUOTES, 'UTF-8');
            $html['country_id'] = htmlentities($country_row['country_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['country_id'] == $country_row['country_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['country_id'],$html['country_name']);

        } ?>
</select>
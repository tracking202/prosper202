<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();


?>

<select class="form-control input-sm" name="isp_id" id="isp_id">
    <option value="0"> -- </option>
	<?php 		$isp_sql = "SELECT *
                        FROM    202_locations_isp
                        GROUP BY `isp_name` ORDER BY `isp_name` ASC";
        $isp_result = $db->query($isp_sql) or record_mysql_error($isp_sql);

        while ($isp_row = $isp_result->fetch_array(MYSQLI_ASSOC)) {
            
            $html['isp_name'] = htmlentities($isp_row['isp_name'], ENT_QUOTES, 'UTF-8');
            $html['isp_id'] = htmlentities($isp_row['isp_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['isp_id'] == $isp_row['isp_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['isp_id'],$html['isp_name']);

        } ?>
</select>
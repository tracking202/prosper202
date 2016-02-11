<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

?>

<select class="form-control input-sm" name="browser_id" id="browser_id">
    <option value="0"> -- </option>
	<?php 		$browser_sql = "SELECT *
                        FROM    202_browsers
                        GROUP BY `browser_name` ORDER BY `browser_name` ASC";
        $browser_result = $db->query($browser_sql) or record_mysql_error($browser_sql);

        while ($browser_row = $browser_result->fetch_array(MYSQLI_ASSOC)) {

            $html['browser_name'] = htmlentities($browser_row['browser_name'], ENT_QUOTES, 'UTF-8');
            $html['browser_id'] = htmlentities($browser_row['browser_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['browser_id'] == $browser_row['browser_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['browser_id'],$html['browser_name']);

        } ?>
</select>
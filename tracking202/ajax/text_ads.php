<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();


 $mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);      
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$text_ad_sql = "SELECT * FROM `202_text_ads`
						WHERE `user_id`='".$mysql['user_id']."' 
						AND `aff_campaign_id`='".$mysql['aff_campaign_id']."' 
						AND `text_ad_deleted`='0' 
						ORDER BY `aff_campaign_id`, `text_ad_name` ASC"; 
		$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);

		if ($text_ad_result->num_rows == 0) {
		
			echo '<select class="form-control input-sm" id="text_ad_id" disabled="">
			            <option>--</option>
			      </select>';
		
		} else { ?>
		
			<select class="form-control input-sm" id="text_ad_id" name="text_ad_id" onchange="load_ad_preview(this.value);">					
			<option value="0"> -- </option> <?php
		
				while ($text_ad_row = $text_ad_result->fetch_array(MYSQLI_ASSOC)) {
		
					$html['text_ad_id'] = htmlentities($text_ad_row['text_ad_id'], ENT_QUOTES, 'UTF-8');
					$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
		
					if ($_POST['text_ad_id'] == $text_ad_row['text_ad_id']) {
                        $selected = 'selected=""';   
                    } else {
                        $selected = '';  
                    }
        
					printf('<option %s value="%s">%s</option>', $selected, $html['text_ad_id'], $html['text_ad_name']);  
		
				} ?>
			</select> 
		<?php }   
 
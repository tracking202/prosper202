<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
	
		$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);      
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$text_ad_sql = "SELECT * FROM `202_text_ads`
						WHERE `user_id`='".$mysql['user_id']."' 
						AND `landing_page_id`='".$mysql['landing_page_id']."' 
						AND `text_ad_deleted`='0' 
						AND text_ad_type=1
						ORDER BY `aff_campaign_id`, `text_ad_name` ASC";
		$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);

		if (mysql_num_rows($text_ad_result) == 0) {
		
			echo '<div class="error">You have not added any text ads to this advanced landing page yet.</div>';
		
		} else { ?>
		
			<select id="text_ad_id" name="text_ad_id" onchange="load_ad_preview(this.value);">					
			<option value="0"> -- </option> <?
		
				while ($text_ad_row = mysql_fetch_array($text_ad_result, MYSQL_ASSOC)) {
		
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
		<? }   
 
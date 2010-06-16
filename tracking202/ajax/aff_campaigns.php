<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


$mysql['aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);      
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
        $aff_campaign_sql = "SELECT * 
                             FROM `202_aff_campaigns` 
							 WHERE `user_id`='".$mysql['user_id']."' 
                             AND `aff_network_id`='".$mysql['aff_network_id']."' 
							 AND `aff_campaign_deleted`='0' 
                             ORDER BY `aff_campaign_name` ASC";
        $aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sqlql);

        if (mysql_num_rows($aff_campaign_result) == 0) {
        
		   // echo '<div class="error">You have not added any campaigns for this affiliate network yet.</div>';
		
        } else { ?>
		
			<select name="aff_campaign_id" id="aff_campaign_id" onchange="load_text_ad_id(this.value);  if($('landing_page_style_type')){load_landing_page( $('aff_campaign_id').value, 0, $('landing_page_style_type').getValue());}; if($('unsecure_pixel')) { pixel_data_changed(); }">
            <option value="0"> -- </option> <?
        
			while ($aff_campaign_row = mysql_fetch_array($aff_campaign_result, MYSQL_ASSOC)) {
    
                $html['aff_campaign_id'] = htmlentities($aff_campaign_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
				$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
				$html['aff_campaign_payout'] = htmlentities($aff_campaign_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
                
                if ($_POST['aff_campaign_id'] == $aff_campaign_row['aff_campaign_id']) {
                    $selected = 'selected=""';   
                } else {
                    $selected = '';  
                }
				
                printf('<option %s value="%s">%s &middot; &#36;%s</option>', $selected, $html['aff_campaign_id'], $html['aff_campaign_name'],$html['aff_campaign_payout']);  
    
			} ?>
        </select> 
    <? }  
 
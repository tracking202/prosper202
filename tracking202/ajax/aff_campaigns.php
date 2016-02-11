<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

$mysql['aff_network_id'] = $db->real_escape_string($_POST['aff_network_id']);      
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
        $aff_campaign_sql = "SELECT * 
                             FROM `202_aff_campaigns` 
							 WHERE `user_id`='".$mysql['user_id']."' 
                             AND `aff_network_id`='".$mysql['aff_network_id']."' 
							 AND `aff_campaign_deleted`='0' 
                             ORDER BY `aff_campaign_name` ASC";
        $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sqlql);

        if ($aff_campaign_result->num_rows == 0) { ?>
        
		 <select class="form-control input-sm" name="aff_campaign_id" id="aff_campaign_id" disabled="">
            <option value="0"> -- </option>
        </select>
		
        <?php } else { ?>
		
			<select class="form-control input-sm" name="aff_campaign_id" id="aff_campaign_id" onchange="load_text_ad_id(this.value); if($('#landing_page_style_type')){load_landing_page( $('#aff_campaign_id option:selected').val(), 0, $('input:radio[name=landing_page_type]:checked').val()?$('input:radio[name=landing_page_type]:checked').val():'landingpage');} if($('#unsecure_pixel').length != 0) { change_pixel_data();}">
            <option value="0"> -- </option> <?php         
			while ($aff_campaign_row = $aff_campaign_result->fetch_array(MYSQLI_ASSOC)) {
    
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
    <?php }  
 
<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




if (($_POST['type'] != 'landingpage') and  ($_POST['type'] != 'advlandingpage')) { ?>
	
	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id" disabled="">
        <option value="0"> -- </option>
    </select>

    <?php die();    
}

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

if ($_POST['type'] == 'landingpage') {
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);      
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `landing_page_deleted`='0' ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC";
}

if ($_POST['type'] == 'advlandingpage') {
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);      
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_type`='1' AND `landing_page_deleted`='0' ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC";
}

#print_r_html($_POST);

?><input id="landing_page_style_type" type="hidden" name="landing_page_style_type" value="<?php echo htmlentities($_POST['type']); ?>"/><?

$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);

if ($landing_page_result->num_rows == 0) { ?>

	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id">
        <option value="0"> -- </option>
    </select>

<?php } else { ?>

	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id" onchange="<?php if ($_POST['type' ] =='advlandingpage') echo 'load_adv_text_ad_id(this.value);'; else  echo ' load_text_ad_id( $(\'#aff_campaign_id\').val() ); ';  ?>">					
		<option value="0"> -- </option> <?
		while ($landing_page_row = $landing_page_result->fetch_array(MYSQL_ASSOC)) {

			$html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
			$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');

			 if ($_POST['landing_page_id'] == $landing_page_row['landing_page_id']) {
				$selected = 'selected=""';   
			} else {
				$selected = '';  
			}
            
			printf('<option %s value="%s">%s</option>',  $selected, $html['landing_page_id'], $html['landing_page_nickname']);  

		} ?>
	</select> <?
} ?>
 
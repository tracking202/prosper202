<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();
if (($_POST['type'] != 'landingpage' and $_POST['type'] != 'landingpages' ) and  ($_POST['type'] != 'advlandingpage')) { ?>
	
	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id" disabled="">
        <option value="0"> -- </option>
    </select>

    <?php die();    
}
if($_POST['aff_campaign_id']==0)
   $eq=">=";
else 
    $eq='=';

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);


if ($_POST['type'] == 'landingpage') {
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);      
	$landing_page_sql = "SELECT * FROM `202_landing_pages` AS 2lp JOIN 202_aff_campaigns using(aff_campaign_id) JOIN 202_aff_networks using(aff_network_id) WHERE 2lp.user_id='".$mysql['user_id']."' AND 2lp.aff_campaign_id".$eq."'".$mysql['aff_campaign_id']."' AND `landing_page_deleted`='0' AND aff_campaign_deleted='0' AND `aff_network_deleted`='0' ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC";
}

if ($_POST['type'] == 'advlandingpage') {
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);      
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_type`='1' AND `landing_page_deleted`='0' ORDER BY `landing_page_nickname` ASC";
}
//if on a refine page, we want to list both SLP and ALP use a UNION to get them both
if ($_POST['type'] == 'landingpages') {
    $mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
    $landing_page_sql = "(SELECT landing_page_id,landing_page_nickname FROM `202_landing_pages` AS 2lp JOIN 202_aff_campaigns using(aff_campaign_id) JOIN 202_aff_networks using(aff_network_id) WHERE 2lp.user_id='".$mysql['user_id']."' AND 2lp.aff_campaign_id".$eq."'".$mysql['aff_campaign_id']."' AND `landing_page_deleted`='0' AND aff_campaign_deleted='0' AND `aff_network_deleted`='0' ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC) UNION (SELECT landing_page_id,landing_page_nickname FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_type`='1' AND `landing_page_deleted`='0' ORDER BY `landing_page_nickname` ASC)";
}

?><input id="landing_page_style_type" type="hidden" name="landing_page_style_type" value="<?php echo htmlentities($_POST['type']); ?>"/><?php 
$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);

if ($landing_page_result->num_rows == 0) { ?>

	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id">
        <option value="0"> -- </option>
    </select>

<?php } else { ?>

	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id" onchange="<?php if ($_POST['type' ] =='advlandingpage') echo 'load_adv_text_ad_id(this.value);'; else  echo ' load_text_ad_id( $(\'#aff_campaign_id\').val() ); ';  ?>">					
		<option value="0"> -- </option> <?php 		while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {

			$html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
			$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');

			 if ($_POST['landing_page_id'] == $landing_page_row['landing_page_id']) {
				$selected = 'selected=""';   
			} else {
				$selected = '';  
			}
            
			printf('<option %s value="%s">%s</option>',  $selected, $html['landing_page_id'], $html['landing_page_nickname']);  

		} ?>
	</select> <?php } ?>
 
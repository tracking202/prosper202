<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	
	$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `text_ad_id`='".$mysql['text_ad_id']."' AND `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();

	if ($text_ad_result->num_rows == 0) { ?>

		<div class="panel panel-default" style="opacity:0.5; border-color: #3498db; margin-bottom:0px; width: 220px">
			<div class="panel-body" style="width: 220px">
				<span id="ad-preview-headline">aLuxury Cruise to Mars</span><br/>
				<span id="ad-preview-body">Visit the Red Planet in style. Low-gravity fun for everyone!</span><br/>
				<span id="ad-preview-url">www.example.com</span>
			</div>
		</div>

	<?php }

	if ($text_ad_result->num_rows > 0) {
		$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
		$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
		$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8'); ?>

		<div class="panel panel-default" style="border-color: #3498db; margin-bottom:0px; width:220px">
			<div class="panel-body" style="width: 220px">
				<span id="ad-preview-headline"><?php echo $html['text_ad_headline']; ?></span><br/>
				<span id="ad-preview-body"><?php echo $html['text_ad_description']; ?></span><br/>
				<span id="ad-preview-url"><?php echo $html['text_ad_display_url']; ?></span>
			</div>
		</div>

<?php  }
} ?>  
 
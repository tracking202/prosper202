<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	$mysql['text_ad_id'] = mysql_real_escape_string($_POST['text_ad_id']);
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	
	$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `text_ad_id`='".$mysql['text_ad_id']."' AND `user_id`='".$mysql['user_id']."'";
	$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = mysql_fetch_assoc($text_ad_result);

	if (mysql_num_rows($text_ad_result) > 0) {
		$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
		$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
		$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8'); ?>

		<table id="ad_preview" class="ad_copy" cellspacing="0" cellpadding="3">
		    <tr>
				<td valign="bottom" style="white-space: normal;">
					<div class="ad_copy_headline"><? echo $html['text_ad_headline']; ?></div>
					<div class="ad_copy_description"><? echo $html['text_ad_description']; ?></div>
					<div class="ad_copy_display_url"><? echo $html['text_ad_display_url']; ?></div>
			    </td>
		    </tr>
	    </table>

<?  }
} ?>  
 
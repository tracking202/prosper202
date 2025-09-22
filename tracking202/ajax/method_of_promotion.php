<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
//switch method of promotion based on if users is on a page with the refine box or not
if (isset($_POST['page']) && $db->real_escape_string(trim((string) $_POST['page'])) == 'refine')
	$method_of_promotion = "landingpages";
else
	$method_of_promotion = "landingpage";
?>

<select class="form-control input-sm" name="method_of_promotion" id="method_of_promotion" onchange="tempLoadMethodOfPromotion(this);">
	<option value="0"> -- </option>
	<option <?php if ($_POST['method_of_promotion'] == 'directlink') {
				echo 'selected=""';
			} ?> value="directlink">Direct Linking</option>
	<option <?php if ($_POST['method_of_promotion'] == 'landingpage') {
				echo 'selected=""';
			} ?> value="<?php echo $method_of_promotion; ?>">Landing Page</option>
</select>
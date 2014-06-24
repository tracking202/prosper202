<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


  ?>

<select class="form-control input-sm" name="method_of_promotion" id="method_of_promotion" onchange="tempLoadMethodOfPromotion(this);">
	<option value="0"> -- </option>
	<option <?php if ($_POST['method_of_promotion'] == 'directlink') { echo 'selected=""'; } ?> value="directlink">Direct Linking</option>
	<option <?php if ($_POST['method_of_promotion'] == 'landingpage') { echo 'selected=""'; } ?> value="landingpage">Landing Page</option>
</select>
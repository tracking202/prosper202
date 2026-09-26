<?php
declare(strict_types=1);
//include mysql settings
include_once('connect.php');

//check to see if this is already installed, if so don't do anything
if (is_installed() == true) {
	if (isset($_GET['customers_api_key']) && $_GET['customers_api_key'] != '') {
		// encode value before placing in redirect header, then terminate
		header('Location: /202-login.php?customers_api_key=' . rawurlencode((string) $_GET['customers_api_key']));
		exit;
	} else {
		_die("<h6>Already Installed</h6>
    <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");
	}
}

$html['user_api'] = isset($_GET['customers_api_key']) ? htmlentities((string) $_GET['customers_api_key'], ENT_QUOTES, 'UTF-8') : '';
$error = ['user_email' => false];

info_top(['title' => 'License key - Prosper202 ClickServer']);
if ($html['user_api'] == '') {
	echo p202_standalone_card('Get your license key', 'Step 2 of 3: your API key activates Prosper202 ClickServer. You need an active subscription; you can start one on the next screens.');
} else {
	echo p202_standalone_card('We found your license key', 'Step 2 of 3: save it and move on to creating your account.');
} ?>
	<form action="" id="getapikey">
		<div class="mb-3">
			<label for="user_api" class="form-label">Prosper202 API key</label>
			<input type="text" class="form-control font-monospace" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>" placeholder="Filled in when you fetch it" readonly>
			<div class="form-text">Never share your API key: it is linked to your payment details.</div>
			<div class="invalid-feedback d-block" id="getapikey-error" role="alert"></div>
		</div>
		<?php if ($html['user_api'] == '') { ?>
			<a class="btn btn-primary btn-lg w-100" href="https://my.tracking202.com/api/customers/login?redirect=get-api">Get my API key</a>
		<?php } else { ?>
			<button class="btn btn-primary btn-lg w-100" type="submit">Save the key and continue</button>
		<?php } ?>
	</form>
<?php echo p202_standalone_card_end(); ?>
<script>
	// The key is checked by the license service before the installer is
	// opened; the installer reads it from the user_api cookie.
	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('getapikey');
		if (!form) { return; }
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var key = document.getElementById('user_api').value;
			$.post(<?php echo json_encode(get_absolute_url() . '202-account/ajax/validate-apikey.php'); ?>, { apikey: key }).done(function (response) {
				var json = {};
				try { json = JSON.parse(response); } catch (e) { json = {}; }
				if (json.msg === 'Key valid') {
					// Secure whenever the page is on HTTPS (as the browser sees it, so
					// behind a TLS-terminating proxy too): the key is a credential.
					document.cookie = 'user_api=' + encodeURIComponent(key) + '; path=' + <?php echo json_encode(get_absolute_url() . '202-config/'); ?> + '; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
					document.location.href = 'install.php';
				} else {
					document.getElementById('getapikey-error').textContent = 'This API key is not valid. Fetch it again from my.tracking202.com.';
					if (confirm('Your Api Key Is Invalid. Would You Like Help Finding Your Key?')) {
						document.location.href = 'https://my.tracking202.com/api/customers/login?redirect=get-api';
					}
				}
			});
		});
	});
</script>

<?php
if (isset($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) == "on") {
	$strProtocol = 'https://';
} else {
	$strProtocol = 'http://';
}

?>
<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo base64_encode($strProtocol .  $_SERVER['SERVER_NAME'] . get_absolute_url()); ?>" alt="" width="1" height="1" class="d-block">
<?php info_bottom();

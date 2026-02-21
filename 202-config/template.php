<?php

declare(strict_types=1);
ob_start();

/**
 * Render the standard Prosper202 page chrome.
 *
 * Historically template_top() accepted up to four loosely defined positional
 * arguments (title, meta description, keywords, body/body-class tweaks). Some
 * legacy pages – and the static analysers that scan them – still pass those
 * extra values. To remain backwards compatible we now accept a flexible
 * options payload while keeping the simple "just pass a title" usage working.
 *
 * Supported invocations:
 *   template_top();
 *   template_top('My Page Title');
 *   template_top('Title', 'Meta description', 'Meta keywords', 'extra-body-class');
 *   template_top('Title', ['meta_description' => '...', 'meta_keywords' => '...',
 *                          'body_class' => 'dashboard', 'body_id' => 'home',
 *                          'body_style' => 'background: #000;',
 *                          'extra_head' => '<link rel="...">']);
 *
 * Any unrecognised legacy positional arguments are ignored safely.
 *
 * @param string $title Page title (default: 'Prosper202 ClickServer')
 * @param mixed ...$legacyArgs Variable number of legacy arguments:
 *   - $legacyArgs[0]: string|array Meta description OR options array
 *   - $legacyArgs[1]: string Meta keywords (legacy format only)
 *   - $legacyArgs[2]: string Extra head content (legacy format only)
 *   - $legacyArgs[3]: string Body class (legacy format only)
 *   - $legacyArgs[4]: string Body ID (legacy format only)
 *   - $legacyArgs[5]: string Body style (legacy format only)
 * @return void
 * @since 1.0.0
 */
function template_top($title = 'Prosper202 ClickServer', ...$legacyArgs): void
{
	global $navigation;

	global $userObj;
	$user_data = [];
	if (!isset($_SESSION['publisher'])) {
		if (isset($_SESSION['user_id'])) {
			$user_data = get_user_data_feedback($_SESSION['user_id']);
		}
	}

	// Normalise primary argument to string for HTML output.
	$title = (string) $title;

	// Default page metadata / presentation.
	$metaDescription = 'description';
	$metaKeywords = 'keywords';
	$extraHeadMarkup = '';
	$bodyAttributes = [];
	$defaultBodyStyle = "background-image: url(https://dp5k1x6z3k332.cloudfront.net/p202bg.jpg);\n  -webkit-background-size: cover;\n  -moz-background-size: cover;\n  -o-background-size: cover;\n  background-size: cover;\n  background-repeat: no-repeat;\n  background-position: center;\n  background-attachment: fixed;";
	$bodyStyle = $defaultBodyStyle;

	$options = [];

	if ($legacyArgs !== []) {
		$firstArg = $legacyArgs[0] ?? null;
		if (is_array($firstArg)) {
			$options = $firstArg;
		} elseif ($firstArg !== null) {
			$options['meta_description'] = $firstArg;
		}

		if (isset($legacyArgs[1]) && $legacyArgs[1] !== null) {
			$options['meta_keywords'] = $legacyArgs[1];
		}

		if (isset($legacyArgs[2]) && $legacyArgs[2] !== null) {
			// Historically this slot was occasionally used for extra head markup.
			$options['extra_head'] = ($options['extra_head'] ?? '') . (string) $legacyArgs[2];
		}

		if (isset($legacyArgs[3]) && $legacyArgs[3] !== null) {
			$options['body_class'] = $legacyArgs[3];
		}

		// Allow fifth & sixth positional arguments for completeness (e.g. body id/style)
		if (isset($legacyArgs[4]) && $legacyArgs[4] !== null) {
			$options['body_id'] = $legacyArgs[4];
		}

		if (isset($legacyArgs[5]) && $legacyArgs[5] !== null) {
			$options['body_style'] = $legacyArgs[5];
		}
	}

	if (isset($options['meta_description'])) {
		$metaDescription = (string) $options['meta_description'];
	}

	if (isset($options['meta_keywords'])) {
		$metaKeywords = (string) $options['meta_keywords'];
	}

	if (isset($options['extra_head'])) {
		$extraHeadMarkup = (string) $options['extra_head'];
	}

	$bodyClassValue = null;
	if (isset($options['body_class'])) {
		$bodyClassValue = $options['body_class'];
		if (is_array($bodyClassValue)) {
			$bodyClassValue = implode(' ', array_filter(array_map(strval(...), $bodyClassValue)));
		}
		$bodyClassValue = trim((string) $bodyClassValue);
	}

	if ($bodyClassValue !== null && $bodyClassValue !== '') {
		$bodyAttributes[] = 'class="' . htmlspecialchars($bodyClassValue, ENT_QUOTES, 'UTF-8') . '"';
	}

	if (isset($options['body_id'])) {
		$bodyId = trim((string) $options['body_id']);
		if ($bodyId !== '') {
			$bodyAttributes[] = 'id="' . htmlspecialchars($bodyId, ENT_QUOTES, 'UTF-8') . '"';
		}
	}

	if (isset($options['body_style'])) {
		$bodyStyle = (string) $options['body_style'];
	}

	$bodyAttributes[] = 'style="' . htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') . '"';
	$bodyAttributeString = $bodyAttributes ? ' ' . implode(' ', $bodyAttributes) : '';
?>

	<!DOCTYPE html>
	<html lang="en">

	<head>
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title><?php echo $title; ?></title>
		<meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>" />
		<meta name="keywords" content="<?php echo htmlspecialchars($metaKeywords, ENT_QUOTES, 'UTF-8'); ?>" />
		<meta name="copyright" content="Prosper202, Inc" />
		<meta name="author" content="Prosper202, Inc" />
		<meta name="MSSmartTagsPreventParsing" content="TRUE" />
		<meta charset="utf-8">
		<meta name="robots" content="noindex, nofollow" />
		<meta http-equiv="Content-Script-Type" content="text/javascript" />
		<meta http-equiv="Content-Style-Type" content="text/css" />
		<meta http-equiv="imagetoolbar" content="no" />
		<link rel="shortcut icon" href="<?php echo get_absolute_url(); ?>202-img/favicon.gif" type="image/ico" />
		<!-- Loading Bootstrap -->
		<link href="<?php echo get_absolute_url(); ?>202-css/css/bootstrap.min.css" rel="stylesheet">
		<!-- Loading Flat UI -->
		<link href="<?php echo get_absolute_url(); ?>202-css/css/flat-ui-pro.min.css" rel="stylesheet">
		<!-- Loading Font Awesome -->
		<link href="<?php echo get_absolute_url(); ?>202-css/css/font-awesome.min.css" rel="stylesheet">
		<!-- Loading Tags Input CSS -->
		<link href="<?php echo get_absolute_url(); ?>202-css/css/bootstrap-tokenfield.min.css" rel="stylesheet">
		<link href="<?php echo get_absolute_url(); ?>202-css/css/tokenfield-typeahead.min.css" rel="stylesheet">
		<?php if (isset($navigation[2]) && isset($navigation[3]) && ($navigation[2] == "setup") and ($navigation[3] == "aff_campaigns.php")) { ?>
			<link href="https://dp5k1x6z3k332.cloudfront.net/jquery.tablesorter.pager.min.css" rel="stylesheet">
			<link href="https://dp5k1x6z3k332.cloudfront.net/theme.bootstrap.min.css" rel="stylesheet">
		<?php } ?>
		<link href="<?php echo get_absolute_url(); ?>202-css/css/select2.css" rel="stylesheet" />
		<!-- Loading Custom CSS -->
		<link href="<?php echo get_absolute_url(); ?>202-css/custom.css" rel="stylesheet">
		<!--[if lt IE 9]>
      <script src="202-js/html5shiv.js"></script>
      <script src="202-js/respond.min.js"></script>
<![endif]-->
		<!-- Load JS here -->
		<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/jquery-1.11.2.min.js"></script>
		<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/jquery-ui.min.js"></script>
		<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/bootstrap.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/fileinput.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/radiocheck.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/jquery.validate.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/bootstrap-tokenfield.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/typeahead.bundle.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/tablesort.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/iio-rum.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/list.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/list.fuzzysearch.min.js"></script>
		<?php if ($extraHeadMarkup !== '') {
			echo $extraHeadMarkup;
		} ?>
		<?php if (isset($navigation[1])) { switch ($navigation[1]) {

			case "tracking202": ?>
				<script type="text/javascript" src="https://code.highcharts.com/highcharts.js"></script>
				<!-- Uncomment to enable Highcharts accessibility (screen readers, keyboard nav) -->
				<!-- <script type="text/javascript" src="https://code.highcharts.com/modules/accessibility.js"></script> -->
				<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/chart.theme.js"></script>
				<?php if (isset($navigation[2]) && isset($navigation[3]) && ($navigation[2] == "setup") and ($navigation[3] == "aff_campaigns.php")) { ?>
					<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/jquery.tablesorter.min.js"></script>
					<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/jquery.tablesorter.widgets.js"></script>
					<script type="text/javascript" src="https://dp5k1x6z3k332.cloudfront.net/jquery.tablesorter.pager.min.js"></script>
					<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/dni.search.offers.tablesorter.php?ddlci=<?php echo urlencode($_GET['ddlci'] ?? ''); ?>"></script>
				<?php } ?>
				<?php if (isset($navigation[2]) && isset($navigation[3]) && ($navigation[2] == "setup") and ($navigation[3] == "ads.php")) { ?>
					<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/dropzone.js"></script>
				<?php } ?>
			<?php break;

			case "202-account": ?>
				<?php if (isset($navigation[1]) && isset($navigation[2]) && ($navigation[1] == "202-account") and !$navigation[2]) { ?>
					<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/home.php"></script>
				<?php } ?>
				<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/account.php"></script>
				<?php if (isset($navigation[2]) && $navigation[2] == 'attribution.php') { ?>
					<script type="text/javascript" src="https://code.highcharts.com/highcharts.js"></script>
					<!-- Uncomment to enable Highcharts accessibility (screen readers, keyboard nav) -->
					<!-- <script type="text/javascript" src="https://code.highcharts.com/modules/accessibility.js"></script> -->
					<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/chart.theme.js"></script>
					<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/attribution.js"></script>
				<?php } ?>
                <?php break;
                } } // End isset navigation check ?>
		<script src="https://dp5k1x6z3k332.cloudfront.net/select2.min.js"></script>
		<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/custom.php"></script>
		<script>
			var eventMethod = window.addEventListener ? "addEventListener" : "attachEvent";
			var eventer = window[eventMethod];
			var messageEvent = eventMethod == "attachEvent" ? "onmessage" : "message";

			eventer(messageEvent, function(e) {
				if (typeof e.data == 'number') {
					document.getElementById('adframe').height = e.data + 'px';
				}
			}, false);
		</script>

	</head>

	<body<?php echo $bodyAttributeString; ?>>

		<!-- START MAIN CONTAINER -->
		<div class="container">
			<div class="main_wrapper">
				<div class="row">
					<div class="col-xs-3">
						<div style="background-color: white;left:15px;width:100%;
  float: none;
  background-color: white;
  border: 2px solid #EBEBEB;
  -webkit-appearance: none;
  appearance: none;
  border-radius: 6px;
  -webkit-box-shadow: none;
  box-shadow: none;
  -webkit-transition: border .25s linear, color .25s linear, background-color .25s linear;
  transition: border .25s linear, color .25s linear, background-color .25s linear;">
							<!-- this is the prosper202 top-left logo/banner placement -->
							<iframe class="advertise-top-left" src="<?php echo TRACKING202_ADS_URL; ?>/prosper202-cs-topleft/?t202aid=<?php echo $_SESSION['user_cirrus_link'] ?? ''; ?>" scrolling="no" frameborder="0"></iframe>
						</div>
					</div>
					<div class="col-xs-9">
						<nav class="navbar navbar-default" role="navigation">
							<ul class="nav navbar-nav">
                                                                <li <?php if (isset($navigation[1]) && isset($navigation[2]) && ($navigation[1] === '202-account') and !$navigation[2]) {
										echo 'class="active";';
									} ?>><a href="<?php echo get_absolute_url(); ?>202-account/" id="HomePage"><span class="fui-home"></span> Home </a></li>
                                                                <li <?php if (isset($navigation[1]) && $navigation[1] === 'tracking202') {
                                                                                echo 'class="active";';
                                                                        } ?>><a href="<?php echo get_absolute_url(); ?>tracking202/" id="ClickServerPage"><span class="fui-heart"></span> Prosper202 CS </a></li>
                                                                <?php if (isset($userObj) && $userObj->hasPermission("view_attribution_reports")) { ?>
                                                                        <li <?php if (isset($navigation[1]) && isset($navigation[2]) && $navigation[1] === '202-account' && $navigation[2] === 'attribution.php') {
                                                                                        echo 'class="active";';
                                                                                } ?>><a href="<?php echo get_absolute_url(); ?>202-account/attribution.php" id="AttributionPage"><span class="fui-graph"></span> Attribution </a></li>
                                                                <?php } ?>
                                                                <li <?php if (isset($navigation[1]) && $navigation[1] == '202-tv') {
                                                                                echo 'class="active";';
                                                                        } ?>><a href="<?php echo get_absolute_url(); ?>202-tv/" id="Tv202Page"><span class="fui-video" aria-hidden="true"></span> Watch TV202 </a> </li>
								<li <?php if (isset($navigation[1]) && $navigation[1] == '202-resources') {
										echo 'class="active";';
									} ?>><a href="<?php echo get_absolute_url(); ?>202-resources/" id="ResourcesPage"><span class="fui-star-2"></span> Hot Deals & Discounts </a></li>
							</ul>
							<ul class="nav navbar-nav navbar-right">
								<li id="account-dropdown" class="dropdown <?php if (isset($navigation[1]) && isset($navigation[2]) && $navigation[1] == '202-account' and $navigation[2]) {
																				echo 'active';
																			} ?>">
									<a href="#" class="dropdown-toggle" data-toggle="dropdown">My Account <?php if (isset($user_data['vip_perks_status']) && $user_data['vip_perks_status']) echo '<span class="label label-important" id="notification">1</span>'; ?><b class="caret"></b></a>
									<span class="dropdown-arrow"></span>
									<ul class="dropdown-menu">
										<li <?php if ($navigation[2] == 'account.php') {
												echo 'class="active";';
											} ?>><a href="<?php echo get_absolute_url(); ?>202-account/account.php" id="PersonalSettingsPage">Personal Settings</a></li>
										<?php if (isset($userObj) && $userObj->hasPermission("access_to_vip_perks")) { ?><li <?php if (isset($navigation[2]) && $navigation[2] == 'vip-perks.php') {
																												echo 'class="active";';
																											} ?>><a href="<?php echo get_absolute_url(); ?>202-account/vip-perks.php" id="VIPPerksPage">VIP Perks Profile</a> <?php if (isset($user_data['vip_perks_status']) && $user_data['vip_perks_status']) echo '<span class="label label-important" id="notification-perks">1</span>'; ?></li><?php } ?>
										<?php if (isset($userObj) && $userObj->hasPermission("access_to_api_integrations")) { ?><li <?php if (isset($navigation[2]) && $navigation[2] == 'api-integrations.php') {
																														echo 'class="active";';
																													} ?>><a href="<?php echo get_absolute_url(); ?>202-account/api-integrations.php" id="3rdPartyAPIPage">3rd Party API Integrations</a></li><?php } ?>
                                                                                <?php if (isset($userObj) && $userObj->hasPermission("view_attribution_reports")) { ?>
                                                                                        <li <?php if (isset($navigation[2]) && $navigation[2] === 'attribution.php') {
                                                                                                        echo 'class="active";';
                                                                                                } ?>>
                                                                                                <a href="<?php echo get_absolute_url(); ?>202-account/attribution.php" id="AttributionAnalyticsPage">Attribution Analytics</a>
                                                                                        </li>
                                                                                <?php } ?>
										<?php if (isset($userObj) && $userObj->hasPermission("add_users")) { ?><li <?php if (isset($navigation[2]) && $navigation[2] == 'user-management.php') {
																									echo 'class="active";';
																								} ?>><a href="<?php echo get_absolute_url(); ?>202-account/user-management.php" id="UserManagementPage">User Management</a></li><?php } ?>
										<?php if (isset($userObj) && $userObj->hasPermission("access_to_settings")) { ?><li <?php if (isset($navigation[2]) && $navigation[2] == 'administration.php') {
																												echo 'class="active";';
																											} ?>><a href="<?php echo get_absolute_url(); ?>202-account/administration.php" id="SettingsPage"><span class="fui-gear icon-navbar"></span> Settings</a></li><?php } ?>
										<li <?php if ($navigation[2] == 'help.php') {
												echo 'class="active";';
											} ?>><a href="<?php echo get_absolute_url(); ?>202-account/help.php" id="HelpPage">Help<span class="fui-question icon-navbar"></span></a></li>
									</ul>
								</li>
								<li><a href="<?php echo get_absolute_url(); ?>202-account/signout.php" id="SignoutPage"><span class="fui-exit icon-navbar"></span> Sign Out</a></li>
							</ul>
						</nav>
					</div>
				</div>
				<div id="update_needed"></div>

				<?php if ($navigation[1] == 'tracking202') {
					include_once(substr(__DIR__, 0, -10) . '/tracking202/_config/top.php');
				} ?>
				<div class="main" <?php if ($navigation[2] == 'setup') {
										echo 'style="border-top-left-radius:0px;"';
									} ?>>

					<?php if ($navigation[1] == 'tracking202') {
						if (($navigation[2] == 'setup') or ($navigation[2] == 'bots') or ($navigation[2] == 'overview') or ($navigation[2] == 'analyze') or ($navigation[2] == 'update') or ($navigation[2] == 'export')) {
							include_once(substr(__DIR__, 0, -10) . '/tracking202/_config/sub-menu.php');
						}
					} ?>

				<?php }
			function template_bottom()
			{
				global $version;

				?>
				</div>


				<div style="clear: both;"></div>
				<div class="row footer main">
					<div class="col-xs-12">
						Thank you for marketing with <a href="http://prosper202.com" target="_blank">Prosper202</a>
						&middot;
						<a href="../202-account/help.php">Help</a>
						&middot;
						<a href="http://support.tracking202.com" target="_blank">Documentation</a>
						&middot;

						<?php if (isset($_SESSION['update_needed']) && $_SESSION['update_needed'] == true) { ?>
							<strong class="bg-danger">Your Prosper202 ClickServer <?php echo $version; ?> is out of date. <a href="https://my.tracking202.com/api/customers/login" target="_blank">Download New Version</a>.</strong>
						<?php } else { ?>
							Your Prosper202 ClickServer <?php echo $version; ?> is up to date.
						<?php } ?>

						<br><br>Local time: <?php echo date(DATE_RFC2822); ?>

						<br><br><a rel="license" href="https://my.tracking202.com/license/" target="_blank">Copyright &copy; <?php echo date("Y") ?> Blue Terra LLC. All rights reserved</a>.
					</div>
				</div>
			</div>
		</div>


		<script type="text/javascript">
			(function(i, s, o, g, r, a, m) {
				i['ProfitWellObject'] = r;
				i[r] = i[r] || function() {
					(i[r].q = i[r].q || []).push(arguments)
				}, i[r].l = 1 * new Date();
				a = s.createElement(o), m = s.getElementsByTagName(o)[0];
				a.async = 1;
				a.src = g;
				m.parentNode.insertBefore(a, m);
			})(window, document, 'script', 'https://dna8twue3dlxq.cloudfront.net/js/profitwell.js', 'profitwell');
			profitwell('auth_token', '574889f9aff2755319487e8819d11658');
			profitwell('user_email', '<?php echo getDashEmail(); ?>');
		</script>


		<!-- Support widget removed -->
		<?php
				if (!isset($_SESSION['publisher']) || $_SESSION['publisher'] !== true) {
					$user_data = get_user_data_feedback($_SESSION['user_id']);

					if (!$user_data['modal_status']) {
						$data = getSurveyData($user_data['install_hash']); ?>

				<script type="text/javascript">
					$(window).load(function() {
						$('#survey-modal').modal({
							backdrop: 'static',
							show: true,
						})
					});
				</script>

				<!-- Start survey modal -->
				<div id="survey-modal" class="modal fade" role="dialog" aria-hidden="true">
					<div class="modal-dialog">
						<div class="modal-content">
							<div class="modal-header">
								<h4 class="modal-title">Prosper202 VIP Perks</h4>
							</div>
							<div class="modal-body">
								<span class="infotext">Wouldn't you love to have new campaign opportunities, private campaigns, business relationships, discounts and special offers and more handed to you? Now you can with the Prosper202 VIP Perk program.<br></br>
									Fill out your profile information to customize your Prosper202 VIP Perks experience. The information will be used to uniquely match you up with coupons, discounts, or exclusive offers.</span>
								<span id="perks-error" class="small error" style="display:none; position:absolute; right: 23px; margin-top: 39px;"><span class="fui-alert"></span> Whoops! Looks like you forget to answer some questions.</span>

								<form class="form-horizontal" role="form" id="survey-form">
									<?php $count_groups = [];
									foreach ($data['questions'] as $question) {

										if (empty($question['answer'])) {
											if (!array_key_exists($question['group_id'], $count_groups)) { ?>
												<?php foreach ($data['question_groups'] as $group) {
													if ($group['id'] == $question['group_id']) {
														echo "<h6>" . $group['title'] . "</h6>";
													}
												} ?>

												<div class="row form_seperator">
													<div class="col-xs-12"></div>
												</div>
											<?php }

											$count_groups[$question['group_id']] = true;

											$highlighted = false;

											$answer = false;

											if ($question['highlighted']) {
												$highlighted = true;
											}
											?>
											<div class="form-group">
												<label for="<?php echo $question['id']; ?>" class="col-sm-8 control-label"><?php echo $question['name']; ?> <?php if ($highlighted) echo '<span class="label label-important">New!</span>'; ?></label>
												<div class="col-sm-4">
													<label class="radio radio-inline">
														<input type="radio" name="<?php echo $question['id']; ?>" value="Yes" data-toggle="radio" required <?php if ($answer == 'Yes') echo "checked"; ?>>
														Yes
													</label>
													<label class="radio radio-inline">
														<input type="radio" name="<?php echo $question['id']; ?>" value="No" data-toggle="radio" <?php if ($answer == 'No') echo "checked"; ?>>
														No
													</label>
												</div>
											</div>

										<?php } ?>

									<?php } ?>

							</div>
							<div class="modal-footer">
								<img style="display:none;left: -25px; top: 12px;" id="perks-loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif">
								<a href="#" class="btn btn-link" id="survey-form-skip">Skip</a>
								<a href="#" class="btn btn-wide btn-p202" id="survey-form-submit">Submit answers</a>
							</div>
							</form>
						</div>
					</div>
				</div>
				<!-- End survey modal -->
			<?php } ?>
		<?php } //for publisher check
		?>
		<script type="text/javascript">
			$(document).ready(function() {
				navigator.sendBeacon("//<?php echo getTrackingDomain() . get_absolute_url(); ?>202-cronjobs/");
			});
		</script>
	</body>
<?php } ?>

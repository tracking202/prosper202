<?php
include_once(str_repeat("../", 1) . '202-config/connect.php');

AUTH::require_user();

$strProtocol = stripos((string) $_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';

// Get Started checklist progress. Each step is checked off once the user has
// done it, and the whole card is hidden once all three are complete (it's no
// longer relevant). Caching is disabled so the state reflects the user's
// actions immediately rather than up to the cache TTL later.
$gs_user_id = (int) ($_SESSION['user_own_id'] ?? $_SESSION['user_id'] ?? 0);
$gs_count = static function (string $sql): int {
    $row = memcache_mysql_fetch_assoc($sql, 0);
    return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
};
$gs_has_traffic  = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_ppc_accounts WHERE user_id=" . $gs_user_id . " AND ppc_account_deleted='0'") > 0;
$gs_has_campaign = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_aff_campaigns WHERE user_id=" . $gs_user_id . " AND aff_campaign_deleted='0'") > 0;
$gs_has_tracker  = $gs_user_id > 0 && $gs_count("SELECT COUNT(*) AS c FROM 202_trackers WHERE user_id=" . $gs_user_id) > 0;
$gs_all_done = $gs_has_traffic && $gs_has_campaign && $gs_has_tracker;

template_top();  ?>

<div class="row home">
	<div class="col-xs-7">
		<div class="row">
			<?php if (!$gs_all_done) {
				// Render a completed step as a struck-through, checked-off item; a
				// pending step keeps its actionable link.
				$gs_step = static function (bool $done, string $href, string $label, string $tail): void {
					if ($done) {
						echo '<li style="color:#999;"><span class="glyphicon glyphicon-ok" style="color:#5cb85c;" aria-hidden="true"></span> <s>' . $label . '</s> ' . $tail . '</li>';
					} else {
						echo '<li><a href="' . $href . '">' . $label . '</a> ' . $tail . '</li>';
					}
				};
			?>
			<div class="col-xs-12" id="p202-getting-started" style="display:none;">
				<h6 class="h6-home">Get Started <span class="glyphicon glyphicon-flag home-icons"></span>
					<a href="#" id="p202-gs-dismiss" class="pull-right" style="text-decoration:none;color:#999;" title="Dismiss">&times;</a>
				</h6>
				<div style="padding: 5px 0 12px;">
					<small>Three steps to your first tracking link:</small>
					<ol style="margin-top: 6px;">
						<?php $gs_step($gs_has_traffic, get_absolute_url() . 'tracking202/setup/ppc_accounts.php', 'Add a traffic source', '&mdash; where your clicks come from'); ?>
						<?php $gs_step($gs_has_campaign, get_absolute_url() . 'tracking202/setup/aff_campaigns.php', 'Create a campaign', '&mdash; the offer you promote'); ?>
						<?php $gs_step($gs_has_tracker, get_absolute_url() . 'tracking202/setup/get_trackers.php', 'Generate a tracking link', 'and put it in your traffic source'); ?>
					</ol>
					<small>Prefer hands-free? Ask Claude to <strong>&ldquo;onboard Prosper202&rdquo;</strong> and it will set this up for you.</small>
				</div>
			</div>
			<script>
			(function () {
				try {
					var card = document.getElementById('p202-getting-started');
					if (!card) return;
					if (localStorage.getItem('p202_getting_started_dismissed') !== '1') {
						card.style.display = '';
					}
					var x = document.getElementById('p202-gs-dismiss');
					if (x) {
						x.addEventListener('click', function (e) {
							e.preventDefault();
							localStorage.setItem('p202_getting_started_dismissed', '1');
							card.style.display = 'none';
						});
					}
				} catch (err) {}
			})();
			</script>
			<?php } ?>
			<?php if (isset($_SESSION['user_pref_ad_settings']) && $_SESSION['user_pref_ad_settings'] != 'hide_all') { ?>
				<div class="col-xs-12">
					<h6 class="h6-home">Special Offers <span class="glyphicon glyphicon-tags home-icons"></span></h6>
					<iframe class="advertise" src="<?php echo TRACKING202_ADS_URL; ?>/prosper202-home/?t202aid=<?php echo $_SESSION['user_cirrus_link']; ?>" scrolling="no" frameborder="0"></iframe>

				</div>
			<?php } ?>

		</div>
	</div>

	<div class="col-xs-5 pull-right">
		<div class="row">
			<div class="col-xs-12 apps">
				<h6 class="h6-home">My Applications <span class="glyphicon glyphicon-folder-open home-icons"></span></h6>
				<div class="row">
					<div class="col-xs-2">
						<a href="<?php echo get_absolute_url(); ?>tracking202/"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/shield.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="<?php echo get_absolute_url(); ?>tracking202/">Prosper202 ClickServer</a><br /><span>Advanced conversion tracking & optimization software.</span>
					</div>
				</div>
				<div class="row app-row">
					<div class="col-xs-2">
						<a href="<?php echo get_absolute_url(); ?>202-tv/"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/video.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="<?php echo get_absolute_url(); ?>202-tv/">TV202</a><br /><span>Exclusive Marketing Interviews & Tutorials.</span>
					</div>
				</div>
				<div class="row app-row">
					<div class="col-xs-2">
						<a href="<?php echo get_absolute_url(); ?>202-Mobile"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/responsive.svg" style="margin-left: 8px;"></a>
					</div>
					<div class="col-xs-10">
						<a href="<?php echo get_absolute_url(); ?>202-Mobile">Mobile202</a><br /><span>View your stats with mobile version of Prosper202</span>
					</div>
				</div>
				<div class="row app-row">
					<div class="col-xs-2">
						<a href="<?php echo get_absolute_url(); ?>202-resources/"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/basket.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="<?php echo get_absolute_url(); ?>202-resources/">Resources202</a><br /><span>Discover more applications to help you sell.</span>
					</div>
				</div>

			</div>
		</div>

		<div class="row">
			<div class="col-xs-12 apps">
				<h6 class="h6-home">Extra Resources <span class="glyphicon glyphicon-info-sign home-icons"></span></h6>

				<div class="row">
					<div class="col-xs-2">
						<a href="http://blog.tracking202.com/" target="_blank"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/news.svg" style="width: 48px;"></a>
					</div>
					<div class="col-xs-10">
						<a href="http://blog.tracking202.com/" target="_blank">Blog</a> - <a href="https://twitter.tracking202.com/" target="_blank">Twitter</a> - <a href="http://newsletter.tracking202.com" target="_blank">Newsletter</a><br /><span>Connect with us to get the latest updates.</span>
					</div>
				</div>

				<div class="row app-row">
					<div class="col-xs-2">
						<a href="http://support.tracking202.com/" target="_blank"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/support.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="http://support.tracking202.com/" target="_blank">Community Support</a><br /><span>Talk with other users, and get help.</span>
					</div>
				</div>

				<div class="row app-row">
					<div class="col-xs-2">
						<a href="http://developers.tracking202.com" target="_blank"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/settings.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="http://developers.tracking202.com" target="_blank">Developers</a><br /><span>Do cool things with the Tracking202 APIs.</span>
					</div>
				</div>

				<div class="row app-row">
					<div class="col-xs-2">
						<a href="http://meetup.tracking202.com" target="_blank"><img src="<?php echo get_absolute_url(); ?>202-img/new/icons/shirt.svg"></a>
					</div>
					<div class="col-xs-10">
						<a href="http://meetup.tracking202.com" target="_blank">Meetup202</a><br /><span>Marketing Meetup Groups around the World.</span>
					</div>
				</div>



			</div>

		</div>
	</div>
</div>
<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo base64_encode($strProtocol . getTrackingDomain() . get_absolute_url()); ?>">

<?php template_bottom(); ?>
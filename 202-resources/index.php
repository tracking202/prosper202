<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

template_top('App Store');  ?>

<script type="text/javascript">
	//mark all offers as being seen
	new Ajax.Request('/202-account/ajax/mark-all-offers.php', {
		onSuccess: function() {

				new Ajax.Request('/202-account/ajax/check-for-offers.php', {
					onSuccess: function() { 
		
						new Ajax.Updater('new_offers', '/202-account/ajax/new-offers.php');
					}
				});
	      }
	});
</script>



<div style="padding: 30px 0px;">
	<h2>Resources</h2>
	<p>Here you will find a wide variety of tools &amp; services to help you become a better internet marketer.  This list is updated frequently, check back often for new updates.</p>
	<script type='text/javascript'>
	var cleer_aff_id = 11;
	var cleer_use_default_css = 1;
	
	var cleer_is_ssl = ('https:' == document.location.protocol);
	var cleer_asset_host = cleer_is_ssl ? 'https://s3.cleervoyance.com' : 'http://s3.cleervoyance.com';
	document.write(unescape("%3Cscript src='" + cleer_asset_host + "/network/showOffers.js' type='text/javascript'%3E%3C/script%3E"));
	</script>
</div>

<? template_bottom(); ?>
<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top('Affiliate Campaigns Setup',NULL,NULL,NULL);

?>

<div id="info">
    <h2>Stats202 Sync</h2>
	Click the sync button to download your stats from stats202.
	<br/><br/>
	
	<div id="stats202-download-status"></div>
	<script type="text/javascript">
		downloadStatus();
		new PeriodicalExecuter(downloadStatus, 5); 	
	</script>
		
<?php template_bottom(); ?>
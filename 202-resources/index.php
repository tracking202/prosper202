<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

template_top('App Store');  ?>

<div style="padding: 30px 0px;">
	<h2>Resources</h2>
	<p>Here you will find a wide variety of tools &amp; services to help you become a better internet marketer.  This list is updated frequently, check back often for new updates.</p>
<?php 
			$snoopy = new Snoopy;
			$snoopy->agent="Mozilla/5.0 Resource202-Bot v1.7";
			$snoopy->fetch('http://ads.tracking202.com/resources/');
			print $snoopy->results;
			
?>	
</div>

<? template_bottom(); ?>
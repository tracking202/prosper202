<?php
header('Content-type: application/javascript');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT');
header("Pragma: no-cache");
include_once(substr(dirname( __FILE__ ), 0,-7) . '/202-config/functions.php');
?>

$(document).ready(function() {
	$.get("<?php echo get_absolute_url();?>202-account/ajax/alerts.php", function(data) {
		  $( "#tracking202_alerts" ).html(data);
		});

		$.get("<?php echo get_absolute_url();?>202-account/ajax/tweets.php", function(data) {
		  $( "#tracking202_tweets" ).html(data);
		});

		$.get("<?php echo get_absolute_url();?>202-account/ajax/posts.php", function(data) {
		  $( "#tracking202_posts" ).html(data);
		});

		$.get("<?php echo get_absolute_url();?>202-account/ajax/meetups.php", function(data) {
		  $( "#tracking202_meetups" ).html(data);
		});

		$.get("<?php echo get_absolute_url();?>202-account/ajax/sponsors.php", function(data) {
		  $( "#tracking202_sponsors" ).html(data);
		});
		
		$.ajax({
		  url: "<?php echo get_absolute_url();?>202-account/ajax/system-checks.php",
		});
});
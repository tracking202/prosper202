<?php
declare(strict_types=1);
include_once(substr(dirname( __FILE__ ), 0,-20) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Analyze Your Custom Variables',NULL,NULL,NULL); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Analyze Your Custom Variables</h6>
	</div>
</div>

<?php display_calendar(get_absolute_url().'tracking202/ajax/sort_variables.php', true, true, true, true, true, true); ?>

<script type="text/javascript">
   loadContent('<?php echo get_absolute_url();?>tracking202/ajax/sort_variables.php',null);
</script>

<?php  template_bottom();

<?php include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('Breakdown Overview',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Breakdown Overview</h6>
		<small>The breakdown overview allows you to see your stats per day, per hour, or an interval that you set.</small>
	</div>
</div>

<?php display_calendar(get_absolute_url().'tracking202/ajax/sort_breakdown.php', true, true, true, false, true, true); ?>    

<script type="text/javascript">
   loadContent('<?php echo get_absolute_url();?>tracking202/ajax/sort_breakdown.php',null);
</script>

<?php template_bottom();
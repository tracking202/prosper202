<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('Group Overview',NULL,NULL,NULL);   ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Group Overview Screen</h6>
		<small>The group overview screen gives you a quick glance at all of your traffic across all dimensions.</small>
	</div>
</div>

<?php display_calendar('/tracking202/ajax/group_overview.php', true, true, true, false, true, true, true, true);    ?>

<script type="text/javascript">
	loadContent('/tracking202/ajax/group_overview.php',null);
</script>

<?php template_bottom();
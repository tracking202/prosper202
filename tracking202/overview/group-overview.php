<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('Group Overview',NULL,NULL,NULL);   ?>

<div id="info" style="clear:both;">
	<h2>Group Overview Screen</h2>
	The group overview screen gives you a quick glance at all of your traffic across all dimensions.
</div>

<? display_calendar('/tracking202/ajax/group_overview.php', true, true, true, false, true, true, true, true);    ?>

<script type="text/javascript">
	loadContent('/tracking202/ajax/group_overview.php',null);
</script>

<? template_bottom();
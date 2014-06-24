<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('Account Overview',NULL,NULL,NULL);   ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Account Overview Screen</h6>
		<small>The account overview screen gives you a quick glance at how all of your campaigns are currently performing.</small>
	</div>
</div>

<?php display_calendar('/tracking202/ajax/account_overview.php', true, false, true, false, true, true);    ?>

	<script type="text/javascript">
		 loadContent('/tracking202/ajax/account_overview.php',null);
	</script>

<?php template_bottom();
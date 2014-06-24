<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Landing Page Code',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-5">
		<h6>Get Landing Code (Optional)</h6>
	</div>
	<div class="col-xs-12">
		<small>You only need to use this step if you are using a landing page setup, if you are using direct linking, ignore this step! If you using a landing page please click on the type of landing page you wish to get your code for.</small>
	</div>
</div>		

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-4 col-xs-offset-1">
		<a href="/tracking202/setup/get_simple_landing_code.php" class="btn btn-info btn-block">Simple Landing Page</a>
	</div>
	<div class="col-xs-4 col-xs-offset-2">
		<a href="/tracking202/setup/get_adv_landing_code.php" class="btn btn-info btn-block">Advanced Landing Page</a>
	</div>
</div>



		
<?php template_bottom($server_row);
<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Landing Page Code',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Get Landing Code (Optional)</h2>
	You only need to use this step if you are using a landing page setup, if you are using direct linking, ignore this step!<br/>
	If you using a landing page please click on the type of landing page you wish to get your code for.
</div>

<table cellspacing="40" style="margin: 0px auto; font-size: 20px;">
	
	<tr>
		<td><a href="/tracking202/setup/get_simple_landing_code.php">Simple Landing Page</a></td>
		<td>or</td>
		<td><a href="/tracking202/setup/get_adv_landing_code.php">Advanced Landing Page</a></td>
	</tr>
</table>



		
<? template_bottom($server_row);
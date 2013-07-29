<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);


template_top('Affiliate Accounts');  

include_once('../top.php');   ?>
	 

<?php //build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$query = http_build_query($get);

//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getStatAccounts?$query";
#echo "<p>$url</p>";

//grab the url
$data = getUrl($url);

//parse out the array
$xmlToArray    = new XmlToArray($data); 
$getStatAccounts = $xmlToArray->createArray(); 

//check for api errors
checkForApiErrors($getStatAccounts);   ?>



<table style="margin: 0px auto;">
	<tr>
		<td style="padding: 0px 0px 20px;">
			<?php if ($_GET['delete']) echo "<div class='success'><div><h3>Your submission was successful</h3>You have deleted an account.</div></div>"; ?>
			<?php if ($_GET['success']) echo "<div class='success'><div><h3>Your submission was successful</h3>You have modified or created a new account</div></div>"; ?>				
			<h3 class="green">My Affiliate Accounts</h3><p>Here is where you can add all of your affiliate accounts. 
			&nbsp; To add a new account <a href="new">click here</a>.
		</td>
	</tr>
	<tr>
		<td >
			<table cellpadding="0" cellspacing="0" class="setup-table">
				<tr>      
					<th>Nickname</th>
					<th>Network</th>
					<th>Username</th>
					<th colspan="2"><a href="/stats202/setup/new/">Add Account</a></th>
				</tr>
			
			<?
			
			$getStatAccounts = $getStatAccounts['getStatAccounts'];
			$statAccounts = @$getStatAccounts['statAccounts'][0]['statAccount'];
			for ($x = 0; $x < count($statAccounts); $x++) { 
				 
					$html = array_map('htmlentities', $statAccounts[$x]);
					echo '<tr onmouseover="lightUpRow(this);" onmouseout="dimDownRow(this);">';  
						echo "<td><strong>{$html['statAccountNickName']}</strong></td>";
						echo "<td>{$html['networkName']}</td>";
						echo "<td>{$html['statAccountUser']}</td>";
						echo "<td><a href='/stats202/setup/new/?action=edit&statAccountId={$html['statAccountId']}'>Edit</a></td>";
						echo "<td><a href='/stats202/setup/new/?action=delete&statAccountId={$html['statAccountId']}'>Remove</a></td>";
					echo "</tr>";
				} ?>
			</table>
		</td>
	</tr>
</table>

<?php template_bottom();
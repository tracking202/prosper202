<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

if ($_GET['deleteAlert']) { 
	
	$get = array( 	'apiKey' => $_SESSION['user_api_key'],
					'alertId' => $_GET['alertId'] );
	$url = TRACKING202_API_URL . '/alerts202/deleteAlert?' . http_build_query($get);
	$xml = getUrl( $url );
	header('location: ?deleteSuccess=1');
}


if ($_GET['addAlert']) {
	
	if (isset($_GET['alertValue'])) { 
	
		$get = array( 	'apiKey' => $_SESSION['user_api_key'],
						'alertType' => $_GET['alertType'],
						'alertValue' => $_GET['alertValue'] );
		$url = TRACKING202_API_URL . '/alerts202/addAlert?' . http_build_query($get);
		$addAlert = getUrl( $url );
		$addAlert = convertXmlIntoArray($addAlert);
		
		
		$errors = $addAlert['errors']['error'];
		if (!$errors) 	header('location: ?addSuccess=1');
		else 			$html = array_map('htmlentities', $_GET);
	}
}

//now grab all of my alerts





template_top('Offer Alerts');  ?>

<table cellspacing="0" cellpadding="0" border="0" style="margin: 50px auto 0px;">
	<tbody>
		<tr>
			<td style="padding-right: 50px;">
				<table border="0">
					<tbody>
						<tr>
							<td>
								<h3>Welcome to Alerts202</h3>
								Alerts202 are email updates of the latest relevant offers based on your choice of query or topic.
								<br/>
								<br/>
								Some handy uses of Alerts202 include:
								<ul>
								<li>monitoring the newest hot offers</li>
								<li>keeping current on an offer or a niche</li>
								<li>keeping tabs on your favorite offers</li>
								</ul>
								Create an alert with the form on the right.
								<br/>
								<br/>
								You can manage your existing alerts below.
							</td>
						</tr>
					</tbody>
				</table>
			</td>
			<td></td>
			<td align="right">
			
				<?php if ($errors) { 
					echo "<div class='warning'><div><h3>There were errors with your submission</h3>Please look at the errors below</div></div>";
					for ($x = 0; $x < count($errors); $x++) { 
						$html = array_merge($html, array_map('htmlentities', $errors[$x]) );
						echo "<div class='error'>ErrorCode: {$html['errorCode']}<br/>";
						echo "ErrorMessage: {$html['errorMessage']}</div>";
					}
				}	 ?>
				
				<form style="margin: 0px; display: block;" method="get">
					<input type="hidden" value="1" name="addAlert"/>
						<table width="320" cellspacing="0" cellpadding="0" border="0" style="border: 1px solid rgb(0, 99, 158);">
							<tbody>
								<tr>
								<td bgcolor="#e0e5ff" style="padding: 5px;" colspan="2">
									<b>Create an Offer Alert</b>
									-
									<a onclick="document.getElementById('s-pop2').style.display='';" style="font-size: 0.8em;" href="#">[advanced options]</a>
									<div id="s-pop2" style="display: none;"></div>
								</td>
							</tr>
							<tr>
								<td style="padding: 10px 7px;" colspan="2">Enter the search term you wish to monitor.</td>
							</tr>
							<tr>
								<td style="padding: 5px 8px;">Search terms:</td>
								<td>
								<input type="text" value="" maxlength="256" size="20" name="alertValue"/>
								</td>
							</tr>
							<tr>
								<td style="padding: 5px 8px;">Type:</td>
								<td>
									<select name="alertType">
										<option value="offer">Offer Alert</option>
									</select>
								</td>
							</tr>
							<tr>
								<td align="center" style="padding: 10px 0px;" colspan="2">
									<input type="submit" value="Create Alert"/>
								</td>
							</tr>
						</tbody>
					</table>
				</form>
			</td>
		</tr>
		<tr>
			<td colspan="3" style="padding-top: 30px;">
			
				<?php if ($_GET['deleteSuccess']) echo "<div class='success'><div><h3>Your have successfully deleted an alert.</h3></div></div>"; ?>
				<?php if ($_GET['addSuccess']) echo "<div class='success'><div><h3>Your have successfully created an alert.</h3></div></div>"; ?>
				
				<table width="320" cellspacing="0" cellpadding="5" border="0"  class="setup-table" style="width: 100%;">
					<tbody>
						<tr>
							<td bgcolor="#e0e5ff" style="padding: 5px;" colspan="5"><strong>My Offer Alerts</strong></td>
						</tr>
						</tr>
						<tr>
							<th style="text-align: left; background: rgb(222,222,222);">Search Terms</th>
							<th style="text-align: left; background: rgb(222,222,222);">How Often</th>
							<th style="text-align: left; background: rgb(222,222,222);">Feeds</th>
							<th style="background: rgb(222,222,222);"/>
						</tr>
						
						<?	$get = array( 	'apiKey' => $_SESSION['user_api_key'],
											'alertType' => 'offer' );
							$url = TRACKING202_API_URL . '/alerts202/getAlerts?' . http_build_query($get);
							
							$getAlertsXml = getUrl( $url );
							$getAlerts = convertXmlIntoArray($getAlertsXml);
							if ($getAlerts['getAlerts']) $alerts = @$getAlerts['getAlerts']['alerts'][0]['alert'];
							
							for ($x = 0; $x < count($alerts); $x++) { 
								
								$html = array_map('htmlentities', $alerts[$x]);
								
								echo '<tr id="t202pro-row" onmouseover="lightUpRow(this);" onmouseout="dimDownRow(this);">';  
									echo "<td >{$html['alertValue']}</td>";
									echo "<td>Daily</td>";
									echo "<td><a href='".TRACKING202_RSS_URL."/offers?q={$html['alertValue']}'>RSS Feed</a></td>";
									echo "<td class='center'><a href='#' onclick='if (confirm(\"Are you sure you wish to delete this alert?\")) { window.location=\"?deleteAlert=1&alertId={$html['alertId']}\"; }'>[delete]</a></td>";
								echo "</tr>";
							}
	
							if (!$alerts) echo "<tr><td colspan='4' class='center'>You currently have no offer alerts setup.</td></tr>"; ?>
					</tbody>
				</table>
			</td>
		</tr>
	</tbody>
</table>

<?php template_bottom();
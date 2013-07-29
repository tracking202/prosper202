<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);


if ($_GET['deletePostBack']) { 
	
	$get = array( 	'apiKey' => $_SESSION['user_api_key'],
					'stats202AppKey' => $_SESSION['user_stats202_app_key'],
					'postBackId' => $_GET['postBackId'] );
	$url = TRACKING202_API_URL . '/stats202/deletePostBack?' . http_build_query($get);
	$xml = getUrl( $url );
	header('location: ?deleteSuccess=1');
}




if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
		
	$get = array();
	$get['apiKey'] = $_SESSION['user_api_key'];
	$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
	$get['postBackUrl'] = $_POST['postBackUrl'];	
	$query = http_build_query($get);
	
	$url = TRACKING202_API_URL . "/stats202/addPostBack?$query";
	$xml = getUrl($url);
	$addPostBack = convertXmlIntoArray($xml);
	$errors = $addPostBack['errors']['error'];

	if (!$errors)		header('location: ?addSuccess=1');
	else 			$html = array_map('htmlentities', $_POST);
}



//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$query = http_build_query($get);

//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getPostBacks?$query";
#echo "<p>$url</p>";

//grab the url
$data = getUrl($url);

//parse out the array
$xmlToArray    = new XmlToArray($data); 
$getPostBacks = $xmlToArray->createArray(); 



template_top('Postback URLs'); 

include_once('../top.php');

//check for api errors
checkForApiErrors($getPostBacks);  ?>


<?php if ($_GET['delete']) echo "<div class='success'><div><h3>Your submission was successful</h3>You have deleted a postback url.</div></div>"; ?>
<?php if ($success) echo "<div class='success'><div><h3>Your submission was successful</h3>You have modified or created a new postback url</div></div>"; ?>
<?php if ($errors) { 
	echo "<div class='warning'><div><h3>There were errors with your submission</h3>Please look at the errors below</div></div>";
	for ($x = 0; $x < count($errors); $x++) { 
		$html = array_merge($html, array_map('htmlentities', $errors[$x]) );
		echo "<div class='error'>ErrorCode: {$html['errorCode']}<br/>";
		echo "ErrorMessage: {$html['errorMessage']}</div>";
	}
} ?>





<table>
	<tr>
		<td colspan="2" style="padding: 10px 0px 30px;">
			<h3 class="green">Global Postback Url</h3><br/>
			<p>A global Postback URL allows Stats202 to communicate with your servers when you generate a conversion on any of your affiliate networks to keep your external tracking resources up to date. We've developed template tags that will dynamically place information in the URL to your tracking system.</p>
		</td>
	</tr>
	<tr valign="top">
		<td rowspan="2">
			<h3 class="green">Tag Reference</h3><br/>
			<p><strong>{subid}</strong><br/>The subid that converted.</p>
			<p><strong>{amount}</strong><br/>The amount the subid generated.</p>
			<p><strong>{actions}</strong><br/>The number of actions the subid generated.</p>
		</td>
		<td>
			<h3 class="green">Create Postback URL</h3>
			<p style="margin: 8px 0px;">Enter in your postback URL below.  An example one would look like this: http://mydomain.com/index.php?subid={subid}&amount={amount}&actions={actions}</p>
			<form method="post">
				<table cellpadding="0" cellspacing="0" class="setup-table" style="width: 100%;">
					<tr>
						<th colspan="2">Create New Postback URL</td>
					</tr>
					<tr>
					     <td><strong>Postback URL</strong></td>
					     <td><input type="text" style="width:400px" name="postBackUrl" value="<?php echo$html['postBackUrl']; ?>"/></td>
					</tr>
					<tr>
						<td/>
						<td><input type="submit" Value="Add New Postback URL"/></td>
					</tr>
				</table>
			</form>
		</td>
	</tr>
	<tr>
		<td  style="padding-top: 30px">
		
			<?php if ($_GET['deleteSuccess']) echo "<div class='success'><div><h3>Your have successfully deleted a postback url.</h3></div></div>"; ?>
			<?php if ($_GET['addSuccess']) echo "<div class='success'><div><h3>Your have successfully created a postback url.</h3></div></div>"; ?>
				
			<h3 class="green">My Postback URLs</h3>
			<p style="margin: 8px 0px;">Here are all of your postback urls.</p>
			
			<table cellpadding="0" cellspacing="0" class="setup-table" style="width: 100%;">
				<tr>      
					<th>My Postback Urls</th>
					<th>Status</th>
					<th/>
		        	</tr>
		        	<tr>
					<?  $getPostBacks = $getPostBacks['getPostBacks'];
					if ($getPostBacks['postBacks'])	$postBacks = $getPostBacks['postBacks'][0]['postBack'];
					
					for ($x = 0; $x < count($postBacks); $x++) {
				
						$html = array_map('htmlentities', $postBacks[$x]);
						
						echo "<tr onmouseover=\"lightUpRow(this);\" onmouseout=\"dimDownRow(this);\">";
							echo "<td><a href='{$html['postBackUrl']}'>{$html['postBackUrl']}</a></td>";
							echo "<td class='center'>{$html['postBackStatus']}</td>";
							echo "<td class='center'><a href='#' onclick='if (confirm(\"Are you sure you wish to delete this postback url?\")) { window.location=\"?deletePostBack=1&postBackId={$html['postBackId']}\"; }'>[delete]</a></td>";
						echo "</tr>"; 
						
					}  ?>
				</tr>
			</table>
		</td>
	</tr>
</table>



<?php template_bottom(); ?>
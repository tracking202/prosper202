<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

if ($_SESSION['update_needed'] == true) { ?>
	<table class="alert">
		<tr>
			<td>A new version of Prosper202 is available! <a href="http://prosper202.com/apps/download/">Please update now</a>.</td>
		</tr> 
	</table>
<? } ?>
<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


function about_revenue_upload() { 

	echo '<div id="info">
		    <h2>Upload Revenue Report</h2>
			This area allows you to upload the revenue reports from your affiliate networks.  You can now upload the exact sale amount that each subid generated, unlike before were T202 asummed the flat-payout on each item, you can now upload the exact revenue that was generated per subid.  This is specifcally helpfull if you are receiving a commission of a percentage based or if you constantly get more than one lead for each subid.
		</div>
		
		<style> table.upload-table { margin: 0px auto; background: rgb(244,244,244); border: 1px solid rgb(222,222,222); }</style>
';
}


$upload_dir = dirname(__FILE__) . '/reports/';

switch ($_GET['case']) { 

	case 1:
		
		#else it worked ok, save the csv to file, and then ask them what fields are what
		$file = $upload_dir . $_GET['file'];
		if (!file_exists($file)) {
			template_top('Upload Revenue Reports',NULL,NULL,NULL); 
			about_revenue_upload();
			echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td><div class="error">This file does not exist that you are trying to import<br/>or you have already succesfully uploaded it.</div></td></tr></table>';
			template_bottom();
			die();
		}
		
		
		template_top('Upload Revenue Reports',NULL,NULL,NULL); 
		about_revenue_upload();
			echo '<form enctype="multipart/form-data" action="/tracking202/update/upload.php" method="get">';
		echo '<input type="hidden" name="case" value="2"/>';
		echo '<input type="hidden" name="file" value="'.$_GET['file'].'"/>';
		echo '<table cellspacing="1" cellpadding="4" class="upload-table">';
			echo '<tr>';
				echo '<th>Column Name</th>';
				echo '<th style="padding-left: 20px;">Subid Column</th>';
				echo '<th style="padding-left: 20px;">Commission Column</th>';
			echo '<tr/>'; 
			
		$handle = fopen($file, 'rb'); 	
		$row = @fgetcsv($handle, 100000, ",");
		for ($x = 0; $x < count($row); $x++) { 
			$html = array_map('htmlentities', $row);
			echo '<tr>';	
				echo '<td>'.$html[$x].'</td>';
				echo '<td style="padding-left: 20px;"><input type="radio" name="click_id" value="'.$x.'"/></td>';
				echo '<td style="padding-left: 20px;"><input type="radio" name="click_payout" value="'.$x.'"/></td>';
			echo '</tr>';
		}
		echo '<tr><td/><td/><td style="padding: 10px 30px; text-align: right;"><input type="submit" value="Next &raquo;"/></td>';
		echo '</table>';
		echo '</form>';
		template_bottom();
		break;
		 
	case 2:
		
		if ( (!is_numeric($_GET['click_id'])) or (!is_numeric($_GET['click_payout'])) ) { 
			
			$file = $_GET['file'];
			template_top('Upload Revenue Reports',NULL,NULL,NULL); 
			about_revenue_upload();
			echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td><div class="error">You forgot to check the subid and the commission column, <a href="/tracking202/update/upload.php?case=1&file='.$file.'">please try again</a></div></td></tr></table>';
			template_bottom();
			die();
			
		}
		
		$file = $upload_dir . $_GET['file'];
		if (!file_exists($file)) {
			template_top('Upload Revenue Reports',NULL,NULL,NULL); 
			about_revenue_upload();
			echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td><div class="error">This file does not exist that you are trying to import<br/>or you have already succesfully uploaded it.</div></td></tr></table>';
			template_bottom();
			die();
		}
		
		$click_payouts = array();
		
		$handle = fopen($file, 'rb'); 
		while ($row = @fgetcsv($handle, 100000, ",")) {
			
			#store all the subid values and payouts
			$click_id = $row[ $_GET['click_id'] ];
			$click_payout = $row[ $_GET['click_payout'] ];
			$click_payout = str_replace('$','', $click_payout);
			
			if (is_numeric($click_id)) { 
			
				if (!$click_payouts[$click_id]) 	$click_payouts[$click_id] = $click_payout;
				else 							$click_payouts[$click_id] = $click_payout + $click_payouts[$click_id];
				
				#now upload each row into prosper202 and update the subids accordingly
				$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$mysql['click_id'] = mysql_real_escape_string($click_id);
				$mysql['click_payout'] = mysql_real_escape_string($click_payouts[$click_id]);
				$mysql['click_update_time'] = time();
				$mysql['click_update_type'] = 'upload';
				
				$update_sql = "UPDATE 202_clicks SET click_lead='1', `click_filtered`='0', `click_payout`='".$mysql['click_payout']."' WHERE click_id='" . $mysql['click_id'] ."' AND user_id='".$mysql['user_id']."'";
				$update_result = _mysql_query($update_sql);
		
				$update_sql = "
					UPDATE 202_clicks_spy
					SET
						click_lead='1',
						`click_filtered`='0',
						`click_payout`='".$mysql['click_payout']."'
					WHERE
						click_id='" . $mysql['click_id'] ."'
						AND user_id='".$mysql['user_id']."'
				";
				$update_result = _mysql_query($update_sql);
			}
		}
		
		#update is now complete, delete the .csv
		unlink ($file);

		
		template_top('Upload Revenue Reports',NULL,NULL,NULL); 
		about_revenue_upload();
			echo '<table cellspacing="1" cellpadding="4" class="upload-table">
					<tr>
						<td colspan="2">
							<div class="success"><div><h3>Your report has been uploaded successfully</h3></div></div>
						</td>
					</tr>
					<tr>
						<th colspan="2">The subids have been marked and set accordingly:</th>
					</tr>
					<tr>
						<th style="text-align: right;">SUBID</th>
						<th style="text-align: left;">COMMISSION</th>
					</tr>';
			foreach( $click_payouts as $key => $row ) {
				printf("<tr>
							<td style='text-align: right;'>%s</td>
							<td style='text-align: left;'>$%s</td>
					     </tr>", $key,  $row);
			}  
			echo '</table>'; 
		template_bottom();
		
		break;
		
	default:
		
		if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
			
			
			//get file extension, checks to see if the image file, if not, do not allow to upload the file
			$pext = getFileExtension($_FILES['csv']['name']);
			$pext = strtolower($pext); 
			if (($pext != "txt") and ($pext !="csv")) $error = true;
			
			
			//open the tmp file, that was uploaded, the csv
			$tmp_name = $_FILES['csv']['tmp_name'];
			$handle = fopen($tmp_name, "rb");
			
			//this counter, will help us determine the first row of the array
			$row = @fgetcsv($handle, 100000, ",");
			
			#if there was no row detected, an error occured on this uploaded
			if (!$row) $error = true;
			
			if (!$error) { 
			
				#now write the csv to the reports folder
				$handle = fopen($tmp_name, "rb");
				$data = fread($handle, 100000);
				$file = rand(0,100) . time() . rand(0,100) .'.csv';
				$newHandle = fopen($upload_dir . $file, 'w');
				fwrite($newHandle, $data);
				fclose($newHandle);
				
				header('location: /tracking202/update/upload.php?case=1&file='.$file); die();
			}
		}
		
		template_top('Upload Revenue Reports',NULL,NULL,NULL); 
		about_revenue_upload();
			
		//check to see if the directory is writable
		if ( !is_writable(  $upload_dir )) {
			
			echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td>'. "<div class='error'>Sorry, I can't write to the directory: ". $upload_dir  ." <br/>In order to upload Revenue reports we need to be able to write to this directory, you'll need to modify the permissions.</div></td></tr></table>";
			template_bottom(); die();
		} ?>
		 
		<div class="info">
		
			<form enctype="multipart/form-data" action="/tracking202/update/upload.php" method="post">
				<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />  
				
				<?  if ($error) echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td><div class="error">There were errors with your submission.<br/>The csv you tried to upload failed, it was empty, or it did not end with the extension .csv or .txt</div></td></tr></table><br/>'; ?>
				
				<table cellspacing="1" cellpadding="10" class="upload-table">
					<tr>
						<th>Upload Commission Report</th>
						<td><input type="file" class="csv-file" name="csv" /></td>
					</tr>
					<tr>
						<th/>
						<td><input class="csv-submit" type="submit" value="Upload Report"></td>
					</tr>
				</table>
			</form>
			
		</div> 
		<? template_bottom();
		break;
}

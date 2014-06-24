<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


function about_revenue_upload() { 

	echo '<div class="row">
			<div class="col-xs-12">
				<h6>Upload Revenue Report</h6>
				<small>This area allows you to upload the revenue reports from your affiliate networks.  You can now upload the exact sale amount that each subid generated, unlike before were T202 asummed the flat-payout on each item, you can now upload the exact revenue that was generated per subid.  This is specifcally helpfull if you are receiving a commission of a percentage based or if you constantly get more than one lead for each subid.</small>
			</div>
		</div>

		<div class="row form_seperator" style="margin-bottom:15px; margin-top:15px;">
			<div class="col-xs-12"></div>
		</div>
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
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import<br/>or you have already succesfully uploaded it.</small></div>';
			template_bottom();
			die();
		}
		
		
		template_top('Upload Revenue Reports',NULL,NULL,NULL); 
		about_revenue_upload();
			echo '<div class="row">
					<div class="col-xs-12">
						<form enctype="multipart/form-data" action="/tracking202/update/upload.php" method="get">';
					echo '<input type="hidden" name="case" value="2"/>';
					echo '<input type="hidden" name="file" value="'.$_GET['file'].'"/>';
				echo '<table class="table table-bordered" id="stats-table">';
				echo '<tr>';
					echo '<th>Column Name</th>';
					echo '<th>Subid Column</th>';
					echo '<th>Commission Column</th>';
				echo '<tr/>';
			 
			
		$handle = fopen($file, 'rb'); 	
		$row = @fgetcsv($handle, 100000, ",");
		for ($x = 0; $x < count($row); $x++) { 
			$html = array_map('htmlentities', $row);
			echo '<tr>';	
				echo '<td>'.$html[$x].'</td>';
				echo '<td><label class="radio" style="display: inline;"><input type="radio" data-toggle="radio" name="click_id" value="'.$x.'"/></label</td>';
				echo '<td><label class="radio" style="display: inline;"><input type="radio" data-toggle="radio" name="click_payout" value="'.$x.'"/></label></td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '</div></div>';
		echo '<div class="row"><div class="col-xs-12">';
		echo '<div class="col-xs-5 col-xs-offset-7">
				<button class="btn btn-p202 btn-block" type="submit">Next <span class="fui-arrow-right pull-right"></span></button>
			  </div>';
		echo '</form>';
		echo '</div></div>';
		template_bottom();
		break;
		 
	case 2:
		
		if ( (!is_numeric($_GET['click_id'])) or (!is_numeric($_GET['click_payout'])) ) { 
			
			$file = $_GET['file'];
			template_top('Upload Revenue Reports',NULL,NULL,NULL); 
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>You forgot to check the subid and the commission column, <a href="/tracking202/update/upload.php?case=1&file='.$file.'">please try again</a></small></div>';
			template_bottom();
			die();
			
		}
		
		$file = $upload_dir . $_GET['file'];
		if (!file_exists($file)) {
			template_top('Upload Revenue Reports',NULL,NULL,NULL); 
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import or you have already succesfully uploaded it.</small></div>';
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
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['click_id'] = $db->real_escape_string($click_id);
				$mysql['click_payout'] = $db->real_escape_string($click_payouts[$click_id]);
				$mysql['click_update_time'] = time();
				$mysql['click_update_type'] = 'upload';
				
				$update_sql = "UPDATE 202_clicks SET click_lead='1', `click_filtered`='0', `click_payout`='".$mysql['click_payout']."' WHERE click_id='" . $mysql['click_id'] ."' AND user_id='".$mysql['user_id']."'";
				$update_result = _mysqli_query($update_sql);
		
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
				$update_result = _mysqli_query($update_sql);
			}
		}
		
		#update is now complete, delete the .csv
		unlink ($file);

		
		template_top('Upload Revenue Reports',NULL,NULL,NULL); 
		about_revenue_upload();
			echo '<div class="row">
					<div class="col-xs-12">
					<div class="success"><small><span class="fui-check-inverted"></span>Your report has been uploaded successfully</small></div><br/>
					<small>The subids have been marked and set accordingly:</small>
					
					<table class="table table-bordered" id="stats-table">
					<tr>
						<th>SUBID</th>
						<th>COMMISSION</th>
					</tr>';
			foreach( $click_payouts as $key => $row ) {
				printf("<tr>
							<td>%s</td>
							<td>$%s</td>
					     </tr>", $key,  $row);
			}  
			echo '</table>';
			echo '</div></div>';  
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
		 
		<div class="row">
			<div class="col-xs-12">
				<form enctype="multipart/form-data" action="/tracking202/update/upload.php" method="post" class="form-horizontal" role="form">
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" /> 
					<div class="col-xs-3">
						<label for="csv">Upload Commission Report:</label>
					</div>

					<div class="col-xs-8" style="margin-left: 20px;">
						<div class="form-group">
				          <div class="fileinput fileinput-new" data-provides="fileinput">
								<span class="btn btn-default btn-embossed btn-file">					  	
								<span class="fileinput-new"><span class="fui-upload"></span>&nbsp;&nbsp;Attach File</span>
								<span class="fileinput-exists"><span class="fui-gear"></span>&nbsp;&nbsp;Change</span>
								<input type="file" name="csv" id="csv">
								</span>
								<span class="fileinput-filename"></span>
								<a href="#" class="close fileinput-exists" data-dismiss="fileinput" style="float: none">&times;</a>
						  </div>
			          </div>
					</div>

					
			          <div class="col-xs-5">
						<button class="btn btn-sm btn-p202 btn-block" type="submit">Upload Report</button>
					</div>
			          
				</form>
			</div>
		</div>
		<script src="/202-js/flatui-fileinput.js"></script>
		<?php template_bottom();
		break;
}

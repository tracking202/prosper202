<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 


AUTH::require_user();

//set no time limit on this
set_time_limit(0); 
ini_set("memory_limit","50M");
ini_set('max_execution_time', 60*10); //10 minutes

template_top('Export202 - Conversion Results',NULL,NULL,NULL); 

include_once('../form.php');


$export_session_id_public = $_GET['id'];
$mysql['export_session_id_public'] = mysql_real_escape_string($export_session_id_public);
$export_session_sql = "SELECT export_session_id FROM 202_export_sessions WHERE export_session_id_public='".$mysql['export_session_id_public']."'";
$export_session_result = _mysql_query($export_session_sql);
$export_session_row = mysql_fetch_assoc($export_session_result);

if (!$export_session_row) { $error = 1; } 

if (!$error) {

//open the file CSV, we will be using
$export_session_id = $export_session_row['export_session_id'];
$mysql['export_session_id'] = mysql_real_escape_string($export_session_id);

?>
	<table class="csv-table">
 	<tr>
 		<td colspan="2" class="csv-data">
 			<h2>Yahoo Search Marketing</h2>
 			
 			 <div>
	 			 <p><strong>Special Instructions:</strong>  Please follow these steps to convert this into the correct Yahoo SEM file format. </p>
	 			 <p>Copy and paste the text into excel, and save the file as type 'Unicode Text' with the desired name and a CSV extension. 
	 			 When Excel prompts you to keep the workbook in the Unicode Text format, click Yes. 
	 			 Then you now have the right format to import into Yahoo SEM. </p>
	 			 <p>If you do not have a Yahoo Gold account ($500/month for 3 months in a row), which allows you to manually import files.
	 			 You can still do it, simply login to your yahoo account, hit customer support, and they allow you to attach a .csv file to upload into your support ticket.  Then they will upload the .csv for you.</p>
 			</div>
	 	</td>
 	</tr>
	<tr>
 		<td class="csv-data">
 			
 			<textarea class="csv" onClick="this.select();" wrap="off"><?
			
			//first line output the headers
			 echo 'Campaign Name' . "\t"; 
			 echo 'Ad Group Name' ."\t";
			 echo 'Component Type' ."\t";
			 echo 'Component Status' ."\t";
			 echo 'Display Status' ."\t";
			 echo 'Keyword' ."\t";
			 echo 'Keyword Alt Text' ."\t";
			 echo 'Keyword Custom URL' ."\t"; 
			 echo 'Sponsored Search Bid (USD)' ."\t"; 
			 echo 'Sponsored Search Bid Limit (USD)' ."\t"; 
			 echo 'Sponsored Search Min Bid (USD)' ."\t"; 
			 echo 'Sponsored Search Status' ."\t"; 
			 echo 'Match Type' ."\t"; 
			 echo 'Content Match Bid (USD)' ."\t"; 
			 echo 'Content Match Bid Limit (USD)' ."\t"; 
			 echo 'Content Match Min Bid (USD)' ."\t"; 
			 echo 'Content Match Status' ."\t"; 
			 echo 'Ad Name' ."\t"; 
			 echo 'Ad Title' ."\t"; 
			 echo 'Ad Short Description' ."\t"; 
			 echo 'Ad Long Description' ."\t"; 
			 echo 'Display URL' ."\t"; 
			 echo 'Destination URL' ."\t"; 
			 echo 'Watch List' ."\t";  
			 echo 'Campaign ID' ."\t"; 
			 echo 'Campaign Description' ."\t"; 
			 echo 'Campaign Start Date' ."\t"; 
			 echo 'Campaign End Date' ."\t"; 
			 echo 'Ad Group ID' ."\t"; 
			 echo 'Ad Group: Optimize Ad Display' ."\t"; 
			 echo 'Ad ID' ."\t"; 
			 echo 'Keyword ID' . "\t";
			 echo 'Checksum' . "\t";
			 echo 'Error Message' ."\t";
			echo "\n";
			
			//ok now add the campaigns
			$export_campaign_sql = "SELECT * FROM 202_export_campaigns WHERE export_session_id='".$mysql['export_session_id']."'";
			$export_campaign_result = _mysql_query($export_campaign_sql); // or record_mysql_error($export_campaign_sql);
			while ($export_campaign_row = mysql_fetch_assoc($export_campaign_result)) { 
			
				ob_flush();
				flush();
				
				$export_campaign_id = $export_campaign_row['export_campaign_id'];
				$mysql['export_campaign_id'] = mysql_real_escape_string($export_campaign_id);
				
				$export_campaign_name = $export_campaign_row['export_campaign_name'];
				$export_campaign_status = $export_campaign_row['export_campaign_status'];
				$component_type = 'Campaign';
				$search = 'On';
				$match_type = 'Advanced';
				$content = 'On';
				$watch_list = 'Off';
				
				if ($export_campaign_status == 1) { 
					$export_campaign_status = 'On';
				} else {
					$export_campaign_status = 'Off';	
				}
				
				$html['export_campaign_name'] = htmlentities($export_campaign_name);
				$html['component_type'] = htmlentities($component_type);
				$html['export_campaign_status'] = htmlentities($export_campaign_status);
				$html['search'] = htmlentities($search);
				$html['match_type'] = htmlentities($match_type);
				$html['content'] = htmlentities($content);
				$html['watch_list'] = htmlentities($watch_list);
			
				
				echo $html['export_campaign_name'] . "\t";
				echo "\t";
				echo $html['component_type'] . "\t";
				echo $html['export_campaign_status'] . "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo $html['search'] ."\t";
				echo $html['match_type'] . "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo $html['content'] . "\t"; 
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo "\t";
				echo $html['watch_list'];
				echo "\n";
				
					
				//ok now add the ad groups
				$export_adgroup_sql = "SELECT * FROM 202_export_adgroups WHERE export_campaign_id='".$mysql['export_campaign_id']."'";
				$export_adgroup_result = _mysql_query($export_adgroup_sql); // or record_mysql_error($export_adgroup_sql);
				while ($export_adgroup_row = mysql_fetch_assoc($export_adgroup_result)) { 
					
					ob_flush();
					flush(); 
			
					$export_adgroup_id = $export_adgroup_row['export_adgroup_id'];
					$mysql['export_adgroup_id'] = mysql_real_escape_string($export_adgroup_id);
					
					$export_adgroup_name = $export_adgroup_row['export_adgroup_name'];
					$export_adgroup_max_search_cpc = $export_adgroup_row['export_adgroup_max_search_cpc'];
					$export_adgroup_max_content_cpc = $export_adgroup_row['export_adgroup_max_content_cpc'];
					$export_adgroup_search = $export_adgroup_row['export_adgroup_search'];
					$export_adgroup_content = $export_adgroup_row['export_adgroup_content'];
					$export_adgroup_status = $export_adgroup_row['export_adgroup_status'];
					
					$component_type = 'Ad Group';
					$match_type = 'Advanced';
					$watch_list = 'Off';
					$optimize_ads = 'On';
			
					if ($export_adgroup_status == 1) { 
						$export_adgroup_status = 'On';
					} else {
						$export_adgroup_status = 'Off';	
					}
					
					if ($export_adgroup_search == 1) { 
						$export_adgroup_search = 'On';
					} else {
						$export_adgroup_search = 'Off';	
					}
					
					if ($export_adgroup_content == 1) { 
						$export_adgroup_content = 'On';
					} else {
						$export_adgroup_content = 'Off';	
					}
					
					if ($export_adgroup_max_search_cpc == 0) {
						$export_adgroup_max_search_cpc = '';	
					}
						
					if ($export_adgroup_max_content_cpc == 0) {
						$export_adgroup_max_content_cpc = '';	
					}
					
					
					$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
					$html['export_adgroup_max_search_cpc'] = htmlentities($export_adgroup_max_search_cpc);
					$html['export_adgroup_max_content_cpc'] = htmlentities($export_adgroup_max_content_cpc);
					$html['export_adgroup_search'] = htmlentities($export_adgroup_search);
					$html['export_adgroup_content'] = htmlentities($export_adgroup_content);
					$html['export_adgroup_status'] = htmlentities($export_adgroup_status);
					$html['component_type'] = htmlentities($component_type);
					$html['match_type'] = htmlentities($match_type);
					$html['watch_list'] = htmlentities($watch_list);
					$html['optimize_ads'] = htmlentities($optimize_ads);
					
						echo $html['export_campaign_name'] . "\t";
						echo $html['export_adgroup_name'] . "\t";
						echo $html['component_type'] . "\t";
						echo $html['export_adgroup_status'] . "\t"; 
						echo "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo $html['export_adgroup_max_search_cpc'] . "\t";
						echo '' . "\t";
						echo '' . "\t"; 
						echo $html['export_adgroup_search'] . "\t";
						echo $html['match_type'] . "\t";
						echo $html['export_adgroup_max_content_cpc'] . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo $html['export_adgroup_content'] . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo $html['watch_list'] . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo $html['optimize_ads'];
					echo "\n";
					
				
					
					//ok now lets put together each textad for this excell
					$export_textad_sql = "SELECT * FROM 202_export_textads WHERE export_adgroup_id='".$mysql['export_adgroup_id']."'";
					$export_textad_result = _mysql_query($export_textad_sql); // or record_mysql_error($export_textad_sql);
					while ($export_textad_row = mysql_fetch_assoc($export_textad_result)) { 
						
						ob_flush();
						flush();
						
						//if this is a full description, do the crazy magic to separate it into 2 lines
						if (!empty($export_textad_row['export_textad_description_full'])) {
							$export_textad_description_full = $export_textad_row['export_textad_description_full'];
						//else if this is a gogle campaign, it should already be split into two lines
						} else {
							$export_textad_description_full = $export_textad_row['export_textad_description_line1'] . ' ' . $export_textad_row['export_textad_description_line2'];
						}
						
						$export_textad_id = $export_textad_row['export_textad_id'];
						$export_textad_name = $export_adgroup_name . ' ' . $export_textad_id;
						
						$export_textad_title = $export_textad_row['export_textad_title'];
						$export_textad_display_url = $export_textad_row['export_textad_display_url'];
						$export_textad_destination_url = $export_textad_row['export_textad_destination_url'];
						$export_textad_status = $export_textad_row['export_textad_status'];
				
						$component_type = 'Ad';
						
						
						if ($export_textad_status == 1) { 
							$export_textad_status = 'On';
						} else {
							$export_textad_status = 'Off';	
						}
						
						
						$html['component_type'] = htmlentities($component_type);
						$html['export_textad_name'] = htmlentities($export_textad_name);
						$html['export_textad_title'] = htmlentities($export_textad_title);
						$html['export_textad_description_full'] = htmlentities($export_textad_description_full);
						$html['export_textad_display_url'] = htmlentities($export_textad_display_url);
						$html['export_textad_destination_url'] = htmlentities($export_textad_destination_url);
						$html['export_textad_status'] = htmlentities($export_textad_status);
				
						echo $html['export_campaign_name'] . "\t";
						echo $html['export_adgroup_name'] . "\t";
						echo $html['component_type'] . "\t";
						echo $html['export_textad_status'] . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo '' . "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo "\t";
						echo $html['export_textad_name'] . "\t";
						echo $html['export_textad_title'] . "\t";
						echo $html['export_textad_description_full'] . "\t";
						echo "\t";
						echo $html['export_textad_display_url'] . "\t";
						echo $html['export_textad_destination_url'];
						echo "\n";
						
					}
					
					
				
				
					//ok grab all of the negative keywords that are campaign global, and assign them to each adgroup
					$export_keyword_sql = "SELECT * FROM 202_export_keywords WHERE export_adgroup_id='".$mysql['export_adgroup_id']."'";
					$export_keyword_result = _mysql_query($export_keyword_sql); // or record_mysql_error($export_keyword_sql);
					while ($export_keyword_row = mysql_fetch_assoc($export_keyword_result)) { 
						
						ob_flush();
						flush();
						
							//$export_campaign_name = $export_keyword_row['export_campaign_name'];
							$export_keyword_status = $export_keyword_row['export_keyword_status'];
							$export_keyword = $export_keyword_row['export_keyword'];
							$export_keyword_destination_url = $export_keyword_row['export_keyword_destination_url'];
							$export_keyword_max_cpc = $export_keyword_row['export_keyword_max_cpc'];
							$export_keyword_watchlist = 'Off';
							
							//if negative keyword
							if ($export_keyword_row['export_keyword_match'] == 'negative') { 
								
								$component_type = 'Ad Group Excluded Word';
								$export_keyword_status = 'On';
								$export_keyword_max_cpc ='';
								$export_keyword_match='';
							
							//else if real keyword
							} else {
			
								$component_type = 'Keyword';
								if ($export_keyword_status == 1) { 
									$export_keyword_status = 'On';
								} else {
									$export_keyword_status = 'Off';	
								}
								
								if ($export_keyword_max_cpc == 0) {
									$export_keyword_max_cpc = 'Default';	
								}
								
								if ($export_keyword_match == 'exact') { 
									$export_keyword_match = 'Simple';	
								} else {
									$export_keyword_match = 'Advanced';	
								}
							}
							
							
							
							
							$html['export_keyword'] = htmlentities($export_keyword);
							$html['export_keyword_match'] = htmlentities($export_keyword_match);
							$html['export_keyword_max_cpc'] = htmlentities($export_keyword_max_cpc);
							$html['export_keyword_destination_url'] = htmlentities($export_keyword_destination_url);
							$html['export_keyword_status'] = htmlentities($export_keyword_status);
							$html['export_keyword_watchlist'] = htmlentities($export_keyword_watchlist);
							$html['component_type'] = htmlentities($component_type);
						
							
							 echo $html['export_campaign_name'] . "\t";
							 echo $html['export_adgroup_name'] . "\t";
							 echo $html['component_type'] . "\t";
							 echo $html['export_keyword_status'] . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword'] . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_destination_url'] . "\t";
							 echo $html['export_keyword_max_cpc'] . "\t";
							 echo '' . "\t";
							echo '' . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_match'] . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_watchlist'];
							 echo "\n";
			
					}	
					
					
					
					//ok now add all of the negative keywords associated with the campaign
					$export_keyword_sql = "SELECT 	* 
											FROM 		202_export_keywords 
											LEFT JOIN 	202_export_campaigns USING (export_campaign_id) 
											LEFT JOIN 	202_export_adgroups ON (202_export_keywords.export_adgroup_id = 202_export_adgroups.export_adgroup_id)  
											WHERE 	202_export_keywords.export_session_id='".$mysql['export_session_id']."' 
											AND 		202_export_keywords.export_adgroup_id=0 
											AND 		export_keyword_match='negative'"; 
					$export_keyword_result = _mysql_query($export_keyword_sql); // or record_mysql_error($export_keyword_sql);
					while ($export_keyword_row = mysql_fetch_assoc($export_keyword_result)) { 
				
						ob_flush();
						flush();
						
						$export_campaign_name = $export_keyword_row['export_campaign_name'];
						$export_adgroup_name = $export_keyword_row['export_adgroup_name'];
						
							$export_campaign_name = $export_keyword_row['export_campaign_name'];
							$export_keyword_status = $export_keyword_row['export_keyword_status'];
							$export_keyword = $export_keyword_row['export_keyword'];
							$export_keyword_destination_url = $export_keyword_row['export_keyword_destination_url'];
							$export_keyword_max_cpc = $export_keyword_row['export_keyword_max_cpc'];
							$export_keyword_watchlist = 'Off';
							
							//if negative keyword
							if ($export_keyword_row['export_keyword_match'] == 'negative') { 
								
								$component_type = 'Ad Group Excluded Word';
								$export_keyword_status = 'On';
								$export_keyword_max_cpc ='';
								$export_keyword_match='';
							
							//else if real keyword
							} else {
			
								$component_type = 'Keyword';
								if ($export_keyword_status == 1) { 
									$export_keyword_status = 'On';
								} else {
									$export_keyword_status = 'Off';	
								}
								
								if ($export_keyword_max_cpc == 0) {
									$export_keyword_max_cpc = 'Default';	
								}
								
								if ($export_keyword_match == 'exact') { 
									$export_keyword_match = 'Simple';	
								} else {
									$export_keyword_match = 'Advanced';	
								}
							}
							
							
							
							
							$html['export_keyword'] = htmlentities($export_keyword);
							$html['export_keyword_match'] = htmlentities($export_keyword_match);
							$html['export_keyword_max_cpc'] = htmlentities($export_keyword_max_cpc);
							$html['export_keyword_destination_url'] = htmlentities($export_keyword_destination_url);
							$html['export_keyword_status'] = htmlentities($export_keyword_status);
							$html['export_keyword_watchlist'] = htmlentities($export_keyword_watchlist);
							$html['component_type'] = htmlentities($component_type);
						
							
							 echo $html['export_campaign_name'] . "\t";
							 echo $html['export_adgroup_name'] . "\t";
							 echo $html['component_type'] . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_status'] . "\t";
							 echo $html['export_keyword'] . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_destination_url'] . "\t";
							 echo $html['export_keyword_max_cpc'] . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							echo '' . "\t";
							 echo $html['export_keyword_match'] . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo '' . "\t";
							 echo $html['export_keyword_watchlist'];
							 echo "\n";
						
							
					}
				
				}
			} ?>
			</textarea>
		</td>
	</tr> 
</table>
 
<?

/* MSSSSSSNNNN */

?>
<table class="csv-table">
 	<tr>
 		<td colspan="2">
 			<h2>MSN Adcenter</h2>
 			
 			<div>
	 			 <p><strong>Special Instructions:</strong>  Please follow these steps to convert this into the correct MSN Adcenter  file format. </p>
	 			 <p>Copy this text and paste it into excel, then save the file as an .XLS file. You should now be able to import this file by using the <a href="http://prosper.tracking202.com/blog/download-msn-adcenter-desktop-client">MSN AdCenter Desktop Client</a>.</p>
 			</div>
 			
 		</td>
 	</tr>
	<tr>
 		<td class="csv-data">
 			<textarea class="csv" onClick="this.select();" wrap="off"><?
			
			//first line output the headers
			 echo 'Campaign' . "\t";
			 echo 'Ad Group	Keyword' . "\t";
			 echo 'Keyword Matching' . "\t";
			 echo 'Current Maximum CPC' . "\t";
			 echo 'Keyword Destination URL' . "\t";
			 echo 'Headline	Description Line 1' . "\t";
			 echo 'Description Line 2' . "\t";
			 echo 'Display URL' . "\t";
			 echo 'Destination URL';
			echo "\n";
			
			//now export the adgroups
			$export_adgroup_sql = "SELECT * FROM 202_export_adgroups 
									LEFT JOIN 202_export_campaigns USING (export_campaign_id) 
									WHERE 202_export_adgroups.export_session_id='".$mysql['export_session_id']."'";
			$export_adgroup_result = _mysql_query($export_adgroup_sql); // or record_mysql_error($export_adgroup_sql);
			while ($export_adgroup_row = mysql_fetch_assoc($export_adgroup_result)) { 
			
				ob_flush();
				flush();
				
				$export_campaign_name = $export_adgroup_row['export_campaign_name'];
				$export_adgroup_name = $export_adgroup_row['export_adgroup_name'];
			
				$html['export_campaign_name'] = htmlentities($export_campaign_name);
				$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
			
				echo $html['export_campaign_name'] . "\t";
				echo $html['export_adgroup_name'] . "\t";
				echo "\n";
				
				$line = array(
					$export_campaign_name,
					$export_adgroup_name
				);
				//fputcsv(//$csv_file, $line);
			}
			
			
			
			//ok now lets put together each textad for this excell
				$export_textad_sql = "SELECT * FROM 202_export_textads 
				LEFT JOIN 202_export_campaigns USING (export_campaign_id) 
				LEFT JOIN 	202_export_adgroups ON (202_export_textads.export_adgroup_id = 202_export_adgroups.export_adgroup_id)  
				WHERE 202_export_textads.export_session_id='".$mysql['export_session_id']."'";
				$export_textad_result = _mysql_query($export_textad_sql); // or record_mysql_error($export_textad_sql);
				while ($export_textad_row = mysql_fetch_assoc($export_textad_result)) { 
			
					ob_flush();
					flush();	
					
					//if this is a full description, do the crazy magic to separate it into 2 lines
					if (!empty($export_textad_row['export_textad_description_full'])) {
						$export_textad_description_full = $export_textad_row['export_textad_description_full'];
					//else if this is a gogle campaign, it should already be split into two lines
					} else {
						$export_textad_description_line1 = $export_textad_row['export_textad_description_line1'];
						$export_textad_description_line2 = $export_textad_row['export_textad_description_line2'];
						
						$export_textad_description_full = $export_textad_description_line1 . ' ' . $export_textad_description_line2;
					}
					
					$export_campaign_name = $export_textad_row['export_campaign_name'];
					$export_adgroup_name = $export_textad_row['export_adgroup_name'];
					$export_textad_name = $export_textad_row['export_textad_name'];
					$export_textad_title = $export_textad_row['export_textad_title'];
					$export_textad_display_url = $export_textad_row['export_textad_display_url'];
					$export_textad_destination_url = $export_textad_row['export_textad_destination_url'];
					$export_textad_status = $export_textad_row['export_textad_status'];
			
					
					$html['export_campaign_name'] = htmlentities($export_campaign_name);
					$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
					$html['export_textad_title'] = htmlentities($export_textad_title);
					$html['export_textad_description_full'] = htmlentities($export_textad_description_full);
					$html['export_textad_display_url'] = htmlentities($export_textad_display_url);
					$html['export_textad_destination_url'] = htmlentities($export_textad_destination_url);
					
					echo $html['export_campaign_name'] . "\t";
					echo $html['export_adgroup_name'] . "\t";
					echo "\t";
					echo "\t";
					echo "\t";
					echo "\t";
					echo $html['export_textad_title'] . "\t";
					echo $html['export_textad_description_full'] . "\t";
					echo "\t";
					echo $html['export_textad_display_url'] . "\t";
					echo $html['export_textad_destination_url'] . "\t";
					echo "\n";
					
					$line = array(
						$export_campaign_name,
						$export_adgroup_name,
						'',
						'',
						'',
						'',
						$export_textad_title,
						$export_textad_description_full,
						'',
						$export_textad_display_url,
					 	$export_textad_destination_url
					);
					//fputcsv(//$csv_file, $line);
					
				}
			
			
				//ok now add all of the negative keywords associated with each individual adgroup
				$export_keyword_sql = "SELECT * FROM 202_export_keywords LEFT JOIN 202_export_campaigns USING (export_campaign_id) LEFT JOIN 	202_export_adgroups ON (202_export_keywords.export_adgroup_id = 202_export_adgroups.export_adgroup_id)    WHERE 202_export_keywords.export_session_id='".$mysql['export_session_id']."' AND 202_export_adgroups.export_adgroup_id!=0 AND export_keyword_match!='negative'"; 
				$export_keyword_result = _mysql_query($export_keyword_sql); // or record_mysql_error($export_keyword_sql);
				while ($export_keyword_row = mysql_fetch_assoc($export_keyword_result)) { 
					
					ob_flush();
					flush();
					
						//set each adgroup stuff
						$export_adgroup_name = $export_keyword_row['export_adgroup_name'];
						$export_adgroup_max_search_cpc = $export_keyword_row['export_adgroup_max_search_cpc'];
						$export_adgroup_content = $export_keyword_row['export_adgroup_content'];
						
						$export_campaign_name = $export_keyword_row['export_campaign_name'];
						$export_keyword = $export_keyword_row['export_keyword'];
						$export_keyword_match = $export_keyword_row['export_keyword_match'];
						$export_keyword_destination_url = $export_keyword_row['export_keyword_destination_url'];
						$export_keyword_status = $export_keyword_row['export_keyword_status'];
						$export_keyword_max_cpc = $export_keyword_row['export_keyword_max_cpc'];
						
						if ($export_keyword_match == 'simple') {
							$export_keyword_match = 'exact';
						} else {
							$export_keyword_match = 'broad';
						}
						
						if ($export_keyword_status == 1) { 
							$export_keyword_status = 'Active';
						} else {
							$export_keyword_status = 'Paused';	
						}
						
						//if no indiivdual max cpc set per keyword, set it to the adgroup default
						if ($export_keyword_max_cpc == 0) {
							$export_keyword_max_cpc = $export_adgroup_max_search_cpc;
						}
						
						$html['export_campaign_name'] = htmlentities($export_campaign_name);
						$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
						$html['export_keyword'] = htmlentities($export_keyword);
						$html['export_keyword_match'] = htmlentities($export_keyword_match);
						$html['export_keyword_max_cpc'] = htmlentities($export_keyword_max_cpc);
						$html['export_keyword_destination_url'] = htmlentities($export_keyword_destination_url);
						$html['export_keyword_status'] = htmlentities($export_keyword_status);
					
						//add keyword for search
						 echo $html['export_campaign_name'] . "\t";
						 echo $html['export_adgroup_name'] . "\t";
						 echo $html['export_keyword'] . "\t";
						 echo $html['export_keyword_match'] . "\t";
						 echo $html['export_keyword_max_cpc'] . "\t";
						 echo $html['export_keyword_destination_url'] . "\t";
						 echo "\n";
						 
						 $line = array(
							 $export_campaign_name,
							 $export_adgroup_name,
							 $export_keyword,
							 $export_keyword_match, 
							 $export_keyword_max_cpc,
							 $export_keyword_destination_url
						);
						
						//if content is enabled also turn the kw on for content
						if ($export_adgroup_content == 1) {
							
							$export_keyword_match = 'content';
							$html['export_keyword_match'] = htmlentities($export_keyword_match);
							
							//add keyword for search
							 echo $html['export_campaign_name'] . "\t";
							 echo $html['export_adgroup_name'] . "\t";
							 echo $html['export_keyword'] . "\t";
							 echo $html['export_keyword_match'] . "\t";
							 echo $html['export_keyword_max_cpc'] . "\t";
							 echo $html['export_keyword_destination_url'] . "\t";
							 echo "\n";
							 
							 $line = array(
								 $export_campaign_name,
								 $export_adgroup_name,
								 $export_keyword,
								 $export_keyword_match,
								 $export_keyword_max_cpc,
								 $export_keyword_destination_url
							);
						}
					}  ?>			
			</textarea>
		</td>
	</tr>
</table>



<table class="csv-table">
 	<tr>
 		<td colspan="2">
 			<h2>Google Adwords - Ad Groups</h2>
 			
 			<div>
	 			 <p><strong>Special Instructions:</strong>  Please follow these steps to upload this into Google Adwords  file format. </p>
	 			 <p>Open <a href="http://www.google.com/intl/en/adwordseditor/">Adwords Editor</a>, open the "Ad Groups" tab, click "Add/Update Mutiple Ad Groups", select, "My ad group information below includes a column for campaign names" and then copy and paste the data below in the textarea. Finally, check the box that says "Keyword CPC", and then  you should be able to hit next/go, and it will do it for you. </p>
	 		</div>
 		</td>
 	</tr>
	<tr>
 		<td class="csv-data">
		 		
		 <?	 /* GOOOOOGGGGLLLEEE */  ?><textarea class="csv" onClick="this.select();" wrap="off"><?
		
		//ok now add the ad groups
		$export_adgroup_sql = "SELECT * FROM 202_export_adgroups LEFT JOIN 202_export_campaigns USING (export_campaign_id) WHERE 202_export_adgroups.export_session_id='".$mysql['export_session_id']."'";
		$export_adgroup_result = _mysql_query($export_adgroup_sql); // or record_mysql_error($export_adgroup_sql);
		while ($export_adgroup_row = mysql_fetch_assoc($export_adgroup_result)) { 
		
			ob_flush();
			flush();
			
			$export_campaign_name = $export_adgroup_row['export_campaign_name'];
			$export_adgroup_name = $export_adgroup_row['export_adgroup_name'];
			$export_adgroup_max_search_cpc = $export_adgroup_row['export_adgroup_max_search_cpc'];
			$export_adgroup_max_content_cpc = $export_adgroup_row['export_adgroup_max_content_cpc'];
			$export_adgroup_status = $export_adgroup_row['export_adgroup_status'];
		
			if ($export_adgroup_status == 1) { 
				$export_adgroup_status = 'Active';
			} else {
				$export_adgroup_status = 'Paused';	
			}
			
			if ($export_adgroup_max_search_cpc == 0) {
				$export_adgroup_max_search_cpc = '';	
			}
				
			if ($export_adgroup_max_content_cpc == 0) {
				$export_adgroup_max_content_cpc = '';	
			}
			
			
			$html['export_campaign_name'] = htmlentities($export_campaign_name);
			$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
			$html['export_adgroup_max_search_cpc'] = htmlentities($export_adgroup_max_search_cpc);
			$html['export_adgroup_max_content_cpc'] = htmlentities($export_adgroup_max_content_cpc);
			$html['export_adgroup_status'] = htmlentities($export_adgroup_status);
			
				echo $html['export_campaign_name'] . "\t";
				echo $html['export_adgroup_name'] . "\t";
				echo $html['export_adgroup_max_search_cpc'] . "\t";
				echo $html['export_adgroup_max_content_cpc'] . "\t";
				echo '' . "\t";
				echo '' . "\t";
				echo '' . "\t";
				echo $html['export_adgroup_status'];
			echo "\n";
			
			 $line = array(
			 $export_campaign_name,
				$export_campaign_name,
				$export_adgroup_name,
				$export_adgroup_max_search_cpc,
				$export_adgroup_max_content_cpc,
				'',
				$export_adgroup_status
			);
			
		} ?>			
		</textarea>
		</td>
	</tr>
</table>



<table class="csv-table">
 	<tr>
 		<td colspan="2">
 			<h2>Google Adwords - Text Ads</h2>
 			<div>
	 			 <p><strong>Special Instructions:</strong>  Please follow these steps to upload this into Google Adwords  file format. </p>
	 			 <p>Open <a href="http://www.google.com/intl/en/adwordseditor/">Adwords Editor</a>, open the "Text Ads" tab, click "Add/Update Mutiple Text Ads", select, "My text ad information below includes a column for campaign names" and then copy and paste the data below in the textarea. Finally, check the box that says "Keyword CPC", and then you should be able to hit next/go, and it will do it for you. </p>
	 		</div>
 		</td>
 	</tr>
	<tr>
 		<td class="csv-data">
 		

 

<textarea class="csv" onClick="this.select();" wrap="off"><?

 
//$csv_file = fopen('/var/www/'.$_SERVER['SERVER_NAME'].'/tmp/'.$export_session_id_public.'-google-textads.csv', 'w');

 
//ok now lets put together each textad for this excell
	$export_textad_sql = "SELECT * FROM 202_export_textads 
	LEFT JOIN 202_export_campaigns USING (export_campaign_id) 
	LEFT JOIN 	202_export_adgroups ON (202_export_textads.export_adgroup_id = 202_export_adgroups.export_adgroup_id)  
	WHERE 202_export_textads.export_session_id='".$mysql['export_session_id']."'";
	$export_textad_result = _mysql_query($export_textad_sql); // or record_mysql_error($export_textad_sql);
	while ($export_textad_row = mysql_fetch_assoc($export_textad_result)) { 

		ob_flush();
		flush();
		
		//if this is a full description, do the crazy magic to separate it into 2 lines
		if (!empty($export_textad_row['export_textad_description_full'])) {
			
			$export_textad_description_full = $export_textad_row['export_textad_description_full'];

			//ok if this is a full textad, we need to strip it into 2 different lines
			$export_textad_description_full = preg_replace('/\{keyword\:(.*?)\}/si',  '{keyword:$1}', $export_textad_description_full); 		
			
			//replace keyword: [space] to, keyword:[nospace]
			$export_textad_description_full = str_replace('{keyword: ', '{keyword:',$export_textad_description_full);
			
			
			$q = 0;
			while (preg_match('/{keyword:(.*?)}/s',$export_textad_description_full) == true) {
				
				//this extracts the keyword out
				$str = $export_textad_description_full;
				$keyword = substr( $str, strpos( $str, "keyword:" ) + 8, strpos( $str, "}" ) - ( strpos( $str, "keyword:" ) + 8) ) ;

				//store the correct kw
				$keywords[$q]['keyword'] = $keyword;
				
				//store a replacement that has same number of characters
				$len = strlen($keyword);
				$keyword_hash= '';
				for($x = 0; $x < $len; $x++) { 
				
					if ($q == 0) { $enc = 'ü'; }
					if ($q == 1) { $enc = '?'; }
					if ($q == 2) { $enc = '¼'; }
					if ($q == 3) { $enc = 'È'; }
					if ($q == 4) { $enc = '?'; }
					if ($q == 5) { $enc = '?'; }
					if ($q == 6) { $enc = '?'; }
					if ($q == 7) { $enc = 'À'; }
					if ($q == 8) { $enc = 'Ë'; }
					if ($q == 9) { $enc = 'ç'; }
					
					$keyword_hash = $keyword_hash . $enc; 
				}
					
				$keywords[$q]['keyword_hash'] = $keyword_hash;
				
				$export_textad_description_full = str_replace('{keyword:'.$keyword.'}',$keyword_hash,$export_textad_description_full);
				
				$q++;
			}
			
			$export_textad_description_line1 = cutText($export_textad_description_full, 35);
			$line1_len = strlen($export_textad_description_line1);	
			$export_textad_description_line2 = substr($export_textad_description_full,$line1_len);
			
			//now add the dynamic key=>words back in
			for ($x=0; $x < $q; $x++) {
				
				$keyword = $keywords[$x]['keyword'];
				$keyword_hash = $keywords[$x]['keyword_hash'];
			
				$export_textad_description_line1 = str_replace($keyword_hash,'{keyword: '.$keyword.'}',$export_textad_description_line1);
				$export_textad_description_line2 = str_replace($keyword_hash,'{keyword: '.$keyword.'}',$export_textad_description_line2);
				
			}
			
			$export_textad_description_line1 = $export_textad_row['export_textad_description_full'];
		//else if this is a gogle campaign, it should already be split into two lines
		} else {
			$export_textad_description_line1 = $export_textad_row['export_textad_description_line1'];
			$export_textad_description_line2 = $export_textad_row['export_textad_description_line2'];
		}
		
		$export_campaign_name = $export_textad_row['export_campaign_name'];
		$export_adgroup_name = $export_textad_row['export_adgroup_name'];
		$export_textad_name = $export_textad_row['export_textad_name'];
		$export_textad_title = $export_textad_row['export_textad_title'];
		$export_textad_display_url = $export_textad_row['export_textad_display_url'];
		$export_textad_destination_url = $export_textad_row['export_textad_destination_url'];
		$export_textad_status = $export_textad_row['export_textad_status'];

		if ($export_textad_status == 1) { 
			$export_textad_status = 'Active';
		} else {
			$export_textad_status = 'Paused';	
		}
		
		
		$html['export_campaign_name'] = htmlentities($export_campaign_name);
		$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
		$html['export_textad_title'] = htmlentities($export_textad_title);
		$html['export_textad_description_line1'] = htmlentities($export_textad_description_line1);
		$html['export_textad_description_line2'] = htmlentities($export_textad_description_line2);
		$html['export_textad_display_url'] = htmlentities($export_textad_display_url);
		$html['export_textad_destination_url'] = htmlentities($export_textad_destination_url);
		$html['export_textad_status'] = htmlentities($export_textad_status);

		echo $html['export_campaign_name'] . "\t";
		echo $html['export_adgroup_name'] . "\t";
		echo $html['export_textad_title'] . "\t";
		echo $html['export_textad_description_line1'] . "\t";
		echo $html['export_textad_description_line2'] . "\t";
		echo $html['export_textad_display_url'] . "\t";
		echo $html['export_textad_destination_url'] . "\t";
		echo $html['export_textad_status'];
		echo "\n";
		
		$line = array(
			$export_campaign_name,
			$export_adgroup_name,
			$export_textad_title,
			$export_textad_description_line1,
			$export_textad_description_line2,
			$export_textad_display_url,
			$export_textad_destination_url,
			$export_textad_status
		);
		//fputcsv(//$csv_file, $line);
	}

		?>			</textarea>
		</td>
		<? /*<td class="csv-export">
			<? printf('<a href="http://%s/tmp/%s-google-textads.csv"><img src="http://%s/images/excel-48.gif"/></a>',$_SERVER['SERVER_NAME'], $export_session_id_public,$_SERVER['STATIC_SERVER_NAME']); ?>
		</td>*/ ?>
	</tr>
</table>

<table class="csv-table">
 	<tr>
 		<td colspan="2">
 			<h2>Google Adwords - Keywords</h2>
 			<div>
	 			 <p><strong>Special Instructions:</strong>  Please follow these steps to upload this into Google Adwords  file format. </p>
	 			 <p>Open <a href="http://www.google.com/intl/en/adwordseditor/">Adwords Editor</a>, open the "Keywords" tab, click "Add/Update Mutiple Keywords", select, "My keyword information below includes a column for campaign names" and then copy and paste the data below in the textarea. Then you should be able to hit next/go, and it will do it for you. </p>
	 		</div>
 		</td>
 	</tr>
	<tr>
 		<td class="csv-data">
 		
<textarea class="csv" onClick="this.select();" wrap="off"><?
	
	//$csv_file = fopen('/var/www/'.$_SERVER['SERVER_NAME'].'/tmp/'.$export_session_id_public.'-google-keywords.csv', 'w');

	
	//ok grab all of the negative keywords that are campaign global, and assign them to each adgroup
	$export_keyword_sql = "SELECT * FROM 202_export_keywords 
							LEFT JOIN 202_export_campaigns USING (export_campaign_id) 
							WHERE 202_export_keywords.export_session_id='".$mysql['export_session_id']."' AND 202_export_keywords.export_adgroup_id=0"; 
	$export_keyword_result = _mysql_query($export_keyword_sql); // or record_mysql_error($export_keyword_sql);
	while ($export_keyword_row = mysql_fetch_assoc($export_keyword_result)) { 
	
		ob_flush();
		flush();
	
		//now grab every adgroup for this campaign
		$mysql['export_campaign_id'] = mysql_real_escape_string($export_keyword_row['export_campaign_id']);
		$export_adgroup_sql = "SELECT * FROM 202_export_adgroups 
								WHERE export_campaign_id='".$mysql['export_campaign_id']."'";
		$export_adgroup_result = _mysql_query($export_adgroup_sql); // or record_mysql_error($export_adgroup_sql);
		while ($export_adgroup_row = mysql_fetch_assoc($export_adgroup_result)) { 
			
			ob_flush();
			flush();
		
			//set each adgroup name
			$export_adgroup_name = $export_adgroup_row['export_adgroup_name'];
			
			$export_campaign_name = $export_keyword_row['export_campaign_name'];
			$export_keyword = $export_keyword_row['export_keyword'];
			$export_keyword_match = $export_keyword_row['export_keyword_match'];
			$export_keyword_destination_url = $export_keyword_row['export_keyword_destination_url'];
			$export_keyword_status = $export_keyword_row['export_keyword_status'];
			$export_keyword_max_cpc = $export_keyword_row['export_keyword_max_cpc'];
			
			if ($export_keyword_match == 'negative') {
				$export_keyword_match = 'Negative Broad';
			}
			
			if ($export_keyword_status == 1) { 
				$export_keyword_status = 'Active';
			} else {
				$export_keyword_status = 'Paused';	
			}
			
			if ($export_keyword_max_cpc == 0) {
				$export_keyword_max_cpc = '';	
			}
			
			$html['export_campaign_name'] = htmlentities($export_campaign_name);
			$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
			$html['export_keyword'] = htmlentities($export_keyword);
			$html['export_keyword_match'] = htmlentities($export_keyword_match);
			$html['export_keyword_max_cpc'] = htmlentities($export_keyword_max_cpc);
			$html['export_keyword_destination_url'] = htmlentities($export_keyword_destination_url);
			$html['export_keyword_status'] = htmlentities($export_keyword_status);
		
			
			 echo $html['export_campaign_name'] . "\t";
			 echo $html['export_adgroup_name'] . "\t";
			 echo $html['export_keyword'] . "\t";
			 echo $html['export_keyword_match'] . "\t";
			 echo $html['export_keyword_max_cpc'] . "\t";
			 echo $html['export_keyword_destination_url'] . "\t";
			 echo $html['export_keyword_status'];
			echo "\n";
			
			$line = array(
				$export_campaign_name,
				$export_adgroup_name,
				$export_keyword,
				$export_keyword_match,
				$export_keyword_max_cpc,
				$export_keyword_destination_url,
				$export_keyword_status
			);
			//fputcsv(//$csv_file, $line);
		}
			
	}
	 
	
	
	//ok now add all of the negative keywords associated with each individual adgroup
	$export_keyword_sql = "SELECT * FROM 202_export_keywords 
							LEFT JOIN 202_export_campaigns USING (export_campaign_id) 
							LEFT JOIN 	202_export_adgroups ON (202_export_keywords.export_adgroup_id = 202_export_adgroups.export_adgroup_id)  
							WHERE 202_export_keywords.export_session_id='".$mysql['export_session_id']."' AND 202_export_keywords.export_adgroup_id!=0"; 
	$export_keyword_result = _mysql_query($export_keyword_sql); // or record_mysql_error($export_keyword_sql);
	while ($export_keyword_row = mysql_fetch_assoc($export_keyword_result)) { 

		ob_flush();
		flush(); 
		
		$export_campaign_name = $export_keyword_row['export_campaign_name'];
		$export_adgroup_name = $export_keyword_row['export_adgroup_name'];
		$export_keyword = $export_keyword_row['export_keyword'];
		$export_keyword_match = $export_keyword_row['export_keyword_match'];
		$export_keyword_destination_url = $export_keyword_row['export_keyword_destination_url'];
		$export_keyword_status = $export_keyword_row['export_keyword_status'];
		$export_keyword_max_cpc = $export_keyword_row['export_keyword_max_cpc'];
		
		if ($export_keyword_match == 'negative') {
			$export_keyword_match = 'Negative Broad';
		}
		
		if ($export_keyword_status == 1) { 
			$export_keyword_status = 'Active';
		} else {
			$export_keyword_status = 'Paused';	
		}
		
		if ($export_keyword_max_cpc == 0) {
			$export_keyword_max_cpc = '';	
		}
		
		$html['export_campaign_name'] = htmlentities($export_campaign_name);
		$html['export_adgroup_name'] = htmlentities($export_adgroup_name);
		$html['export_keyword'] = htmlentities($export_keyword);
		$html['export_keyword_match'] = htmlentities($export_keyword_match);
		$html['export_keyword_max_cpc'] = htmlentities($export_keyword_max_cpc);
		$html['export_keyword_destination_url'] = htmlentities($export_keyword_destination_url);
		$html['export_keyword_status'] = htmlentities($export_keyword_status);
		
		 echo $html['export_campaign_name'] . "\t";
		 echo $html['export_adgroup_name'] . "\t";
		 echo $html['export_keyword'] . "\t";
		 echo $html['export_keyword_match'] . "\t";
		 echo $html['export_keyword_max_cpc'] . "\t";
		 echo $html['export_keyword_destination_url'] . "\t";
		 echo $html['export_keyword_status'];
		 echo "\n";
		 
		 $line = array(
			$export_campaign_name,
			$export_adgroup_name,
			$export_keyword,
			$export_keyword_match,
			$export_keyword_max_cpc,
			$export_keyword_destination_url,
			$export_keyword_status
		);
		//fputcsv(//$csv_file, $line);
	}
	
		?>			</textarea>
		</td>
		<? /*<td class="csv-export">
			<? printf('<a href="http://%s/tmp/%s-google-keywords.csv" onClick="saveFile(this);"><img src="http://%s/images/excel-48.gif"/></a>',$_SERVER['SERVER_NAME'], $export_session_id_public,$_SERVER['STATIC_SERVER_NAME']); ?>
		</td>*/ ?>
	</tr>
</table>

<?
}

template_bottom(); 
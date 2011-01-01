<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 



AUTH::require_user();

//set no time limit on this
set_time_limit(0);

ini_set('post_max_size', '50M');
ini_set('upload_max_filesize', '50M');
ini_set("memory_limit","50M");
ini_set('max_execution_time', 60*10); //10 minutes


if ($_SERVER['REQUEST_METHOD'] == 'POST') {  
	//if ($_POST['token'] != $_SESSION['token']){ $error['token'] = '<div class="error">You must use our forms to submit data.</div';  }
	
	if ($_POST['network'] == '') { $error['network'] = '<div class="error">Choose a network</div>'; }	
	if ($_FILES['csv']['error'] == 4) { $error['csv'] = '<div class="error">You must upload a  .csv file</div>'; }	
	
	$ext = getFileExtension($_FILES['csv']['name']);
	$ext = strtolower($ext);
	if ($ext != "csv") { $error['csv'] = '<div class="error">You must upload a  .csv file</div>'; }	
	
	//check filesize
	$post_max_size = ini_get('post_max_size');
	$post_max_size = str_replace('M','',$post_max_size);
	$post_max_size = $post_max_size * 1048576;
	if ($_FILES['csv']['size'] > $post_max_size) {  $error['size'] = '<div class="error">The file you tried to upload was to large, your max file size upload is: '.ini_get('post_max_size').'.  You may increase this value by changing the post_max_size variable in your php.ini</div>'; }	
	
	//if the file size is not equal to anything the file was to big and couldn't even set.
	if ($_FILES['csv']['size'] == '') {  $error['size'] = '<div class="error">The file you tried to upload was to large or you uploaded no file at all, your max file size upload is: '.ini_get('post_max_size').'. <br/> You may increase this value by changing the post_max_size variable in your php.ini</div>'; }	
	

	if (!$error) { 
		//if no error start a new export_session_id for reference
		$mysql['export_session_time'] = time();
		$mysql['export_session_ip'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
		
		$export_session_sql = "INSERT INTO 	202_export_sessions
							      SET 			export_session_time='".$mysql['export_session_time']."',
							      				export_session_ip='".$mysql['export_session_ip']."'";
		$export_session_result = _mysql_query($export_session_sql);
		$export_session_id = mysql_insert_id();
		$mysql['export_session_id'] = mysql_real_escape_string($export_session_id);
		
		
		//create a public session_id now
		$alphanum = "abdefghijklmnopqrstuvwxyz1234567890";     
		$export_session_id_public = substr(str_shuffle($alphanum), 0, 5) . $export_session_id . substr(str_shuffle($alphanum), 0, 5); 
		$mysql['export_session_id_public'] = mysql_real_escape_string($export_session_id_public);
		
		//update rowIm
		$export_session_sql = "UPDATE 202_export_sessions SET export_session_id_public='".$mysql['export_session_id_public']."' WHERE export_session_id='".$mysql['export_session_id']."'";
		$export_session_result = _mysql_query($export_session_sql);
		
		
		//open the tmp file, that was uploaded, the csv
		$tmp_name = $_FILES['csv']['tmp_name'];
		$handle = fopen($tmp_name, "rb"); 
		
		//this counter, will help us determine the first row of the array
		$x = 0;
		
		
		//determine what delimiter to use
		switch ($_POST['network']) {
			case "msn":
				$delimiter = ",";
				break;
			case "yahoo":
			case "google":
				$delimiter = "\t";
				break;
		}
		
		//reformat the csv in an easy to read array format
		$row = array();
		$headerRow = '';
		$start = false;
		while (($tempRow = fgetcsv($handle, 0, $delimiter)) !== FALSE) { 
			
			#print_r_html($tempRow);
			$ouputRow = ''; //clear output row everytime
			
			if (!$start) { $headerRow = $tempRow; }
			else {	
				for ($z = 0; $z < count($tempRow); $z++) {
					$colName = $headerRow[$z];
					$colName = cleanString($colName);
					$ouputRow[$colName] = cleanString($tempRow[$z]); 
				}
				array_push($row, $ouputRow);
			}
			$start = true; 
		}		
		
		#print_r_html($row);
		#die();
		 
		
		for ($x = 0; $x < count($row); $x++) { 	
			#ob_flush();
			#flush();

			if (!$error) {   
				
				//Yahoo Search Marketing Yahoo, ignore the columns that say, 'update: yes' because they are junk
				if ($_POST['network'] == 'msn') { 
					
					//if campaign
					if ($row[$x]['Type'] == 'Campaign') { 
					
						$export_campaign_name = $row[$x]['Campaign'];
						$export_campaign_status = $row[$x]['Status'];
						
						if ($export_campaign_status == 'Active') 	$export_campaign_status = 1;
						else 										$export_campaign_status = 0;	
						
						$mysql['export_campaign_name'] = mysql_real_escape_string($export_campaign_name);
						$mysql['export_campaign_status'] = mysql_real_escape_string($export_campaign_status);
					
						$export_campaign_sql = "INSERT INTO  202_export_campaigns
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_name='".$mysql['export_campaign_name']."',
										  		    				 export_campaign_status='".$mysql['export_campaign_status']."'";
						$export_campaign_result = _mysql_query($export_campaign_sql); 
						$export_campaign_id = mysql_insert_id();
	
						$mysql['export_campaign_id'] = mysql_real_escape_string($export_campaign_id);		
					}
					
	

					//if adgroup
					if ($row[$x]['Type'] == 'AdGroup') { 
					
						$export_adgroup_name = $row[$x]['Ad group'];
						$export_adgroup_status = $row[$x]['Status'];
						$export_adgroup_max_search_cpc = $row[$x]['Content Bid'];
						$export_adgroup_max_content_cpc = $row[$x]['Search Bid'];
						$export_adgroup_search = $row[$x]['Search network status'];
						$export_adgroup_content = $row[$x]['Content network status'];
						
						if ($export_adgroup_status == 'Paused') 	$export_adgroup_status = 0;
						else 										$export_adgroup_status = 1;	
						
						if ($export_adgroup_search == 'on') 	$export_adgroup_search = 1;
						else 									$export_adgroup_search = 0;	
						
						if ($export_adgroup_content == 'on') 	$export_adgroup_content = 1;
						else 									$export_adgroup_content = 0;	
						
						$mysql['export_adgroup_name'] = mysql_real_escape_string($export_adgroup_name);
						$mysql['export_adgroup_max_search_cpc'] = mysql_real_escape_string($export_adgroup_max_search_cpc);
						$mysql['export_adgroup_max_content_cpc'] = mysql_real_escape_string($export_adgroup_max_content_cpc);
						$mysql['export_adgroup_status'] = mysql_real_escape_string($export_adgroup_status);
						$mysql['export_adgroup_search'] = mysql_real_escape_string($export_adgroup_search);
						$mysql['export_adgroup_content'] = mysql_real_escape_string($export_adgroup_content);
						
						$export_adgroup_sql = "  INSERT INTO  202_export_adgroups
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_name='".$mysql['export_adgroup_name']."',
										  		    				 export_adgroup_max_search_cpc='".$mysql['export_adgroup_max_search_cpc']."',
										  		    				 export_adgroup_max_content_cpc='".$mysql['export_adgroup_max_content_cpc']."',
										  		    				 export_adgroup_status='".$mysql['export_adgroup_status']."',
										  		    				 export_adgroup_search='".$mysql['export_adgroup_search']."',
										  		    				 export_adgroup_content='".$mysql['export_adgroup_content']."'";
						$export_adgroup_result = _mysql_query($export_adgroup_sql); 
						$export_adgroup_id = mysql_insert_id();
	
						$mysql['export_adgroup_id'] = mysql_real_escape_string($export_adgroup_id);	 	 	
					}
					
					
					//if textad
					if ($row[$x]['Type'] == 'TextAd') { 
					
						$export_textad_status = $row[$x]['Status'];
						$export_textad_title = $row[$x]['Ad title'];
						$export_textad_description_full = $row[$x]['Ad text'];
						$export_textad_display_url = $row[$x]['Display URL'];
						$export_textad_destination_url = $row[$x]['Destination URL'];
						
						if ($export_textad_status == 'Active') 	$export_textad_status = 1;
						else 									$export_textad_status = 0;	
						
						$mysql['export_textad_title'] = mysql_real_escape_string($export_textad_title);
						$mysql['export_textad_description_full'] = mysql_real_escape_string($export_textad_description_full);
						$mysql['export_textad_display_url'] = mysql_real_escape_string($export_textad_display_url);
						$mysql['export_textad_destination_url'] = mysql_real_escape_string($export_textad_destination_url);
						$mysql['export_textad_status'] = mysql_real_escape_string($export_textad_status);
	
						$export_textad_sql = "    INSERT INTO  202_export_textads
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
										  		    				 export_textad_title='".$mysql['export_textad_title']."',
										  		    				 export_textad_description_full='".$mysql['export_textad_description_full']."',
										  		    				 export_textad_display_url='".$mysql['export_textad_display_url']."',
										  		    				 export_textad_destination_url='".$mysql['export_textad_destination_url']."',
										  		    				 export_textad_status='".$mysql['export_textad_status']."'";
						$export_textad_result = _mysql_query($export_textad_sql); 
						$export_textad_id = mysql_insert_id();
	
						$mysql['export_textad_id'] = mysql_real_escape_string($export_textad_id);	
					}
					
					
					//if keyword
					if ( ($row[$x]['Type'] == 'Keyword') and ($row[$x]['Match type'] != 'Content') ) {
						
						$export_keyword_status = $row[$x]['Status'];
						$export_keyword = $row[$x]['Keyword'];
						$export_keyword_destination_url = $row[$x]['Destination URL {param1}'];
						$export_keyword_max_cpc = $row[$x]['Bid amount'];
						$export_keyword_match = $row[$x]['Match type'];
						
						if ($export_keyword_status == 'Active') 						$export_keyword_status = 1;
						else 															$export_keyword_status = 0;	
						
						$mysql['export_keyword_max_cpc'] = mysql_real_escape_string($export_keyword_max_cpc);
						$mysql['export_keyword'] = mysql_real_escape_string($export_keyword);
						$mysql['export_keyword_match'] = mysql_real_escape_string($export_keyword_match);
						$mysql['export_keyword_destination_url'] = mysql_real_escape_string($export_keyword_destination_url);
						$mysql['export_keyword_status'] = mysql_real_escape_string($export_keyword_status);
	
						$export_keyword_sql = "   INSERT INTO   	 202_export_keywords
											  		    SET			 export_session_id='".$mysql['export_session_id']."',
											  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
											  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
											  		    				 export_keyword_max_cpc='".$mysql['export_keyword_max_cpc']."',
											  		    				 export_keyword='".$mysql['export_keyword']."',
											  		    				 export_keyword_match='".$mysql['export_keyword_match']."',
											  		    				 export_keyword_destination_url='".$mysql['export_keyword_destination_url']."',
											  		    				 export_keyword_status='".$mysql['export_keyword_status']."'";
						$export_keyword_result = _mysql_query($export_keyword_sql); 
						$export_keyword_id = mysql_insert_id();
						
						$mysql['export_keyword_id'] = mysql_real_escape_string($export_keyword_id);	
					}
				}

				//Yahoo Search Marketing Yahoo
				if ($_POST['network'] == 'yahoo') { 
					
					//if campaign
					if ($row[$x]['Component Type'] == 'Campaign') { 
					
						$export_campaign_name = $row[$x]['Campaign Name'];
						$export_campaign_status = $row[$x]['Component Status'];
						
						if ($export_campaign_status == 'On') 	$export_campaign_status = 1;
						else 									$export_campaign_status = 0;	
						
						$mysql['export_campaign_name'] = mysql_real_escape_string($export_campaign_name);
						$mysql['export_campaign_status'] = mysql_real_escape_string($export_campaign_status);
					
						$export_campaign_sql = "INSERT INTO  202_export_campaigns
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_name='".$mysql['export_campaign_name']."',
										  		    				 export_campaign_status='".$mysql['export_campaign_status']."'";
						$export_campaign_result = _mysql_query($export_campaign_sql); 
						$export_campaign_id = mysql_insert_id();
	
						$mysql['export_campaign_id'] = mysql_real_escape_string($export_campaign_id);		
					}
					
	

					//if adgroup
					if ($row[$x]['Component Type'] == 'Ad Group') { 
					
						$export_adgroup_name = $row[$x]['Ad Group Name'];
						$export_adgroup_status = $row[$x]['Component Status'];
						$export_adgroup_max_search_cpc = $row[$x]['Sponsored Search Bid (USD)'];
						$export_adgroup_max_content_cpc = $row[$x]['Content Match Bid (USD)'];
						$export_adgroup_search = $row[$x]['Sponsored Search Status'];
						$export_adgroup_content = $row[$x]['Content Match Status'];
						
						if ($export_adgroup_status == 'On') 	$export_adgroup_status = 1;
						else 									$export_adgroup_status =0;	
						
						if ($export_adgroup_search == 'On') 	$export_adgroup_search = 1;
						else 									$export_adgroup_search = 0;	
						
						if ($export_adgroup_content == 'On') 	$export_adgroup_content = 1;
						else 									$export_adgroup_content = 0;	
						
						$mysql['export_adgroup_name'] = mysql_real_escape_string($export_adgroup_name);
						$mysql['export_adgroup_max_search_cpc'] = mysql_real_escape_string($export_adgroup_max_search_cpc);
						$mysql['export_adgroup_max_content_cpc'] = mysql_real_escape_string($export_adgroup_max_content_cpc);
						$mysql['export_adgroup_status'] = mysql_real_escape_string($export_adgroup_status);
						$mysql['export_adgroup_search'] = mysql_real_escape_string($export_adgroup_search);
						$mysql['export_adgroup_content'] = mysql_real_escape_string($export_adgroup_content);
						
						$export_adgroup_sql = "  INSERT INTO  202_export_adgroups
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_name='".$mysql['export_adgroup_name']."',
										  		    				 export_adgroup_max_search_cpc='".$mysql['export_adgroup_max_search_cpc']."',
										  		    				 export_adgroup_max_content_cpc='".$mysql['export_adgroup_max_content_cpc']."',
										  		    				 export_adgroup_status='".$mysql['export_adgroup_status']."',
										  		    				 export_adgroup_search='".$mysql['export_adgroup_search']."',
										  		    				 export_adgroup_content='".$mysql['export_adgroup_content']."'";
						$export_adgroup_result = _mysql_query($export_adgroup_sql); 
						$export_adgroup_id = mysql_insert_id();
	
						$mysql['export_adgroup_id'] = mysql_real_escape_string($export_adgroup_id);	 	 	
					}
					
					
					//if textad
					if ($row[$x]['Component Type'] == 'Ad') { 
					
						$export_textad_status = $row[$x]['Component Status'];
						$export_textad_title = $row[$x]['Ad Title'];
						$export_textad_description_full = $row[$x]['Ad Short Description'];
						$export_textad_display_url = $row[$x]['Display URL'];
						$export_textad_destination_url = $row[$x]['Destination URL'];
						
						if ($export_textad_status == 'On') 	$export_textad_status = 1;
						else 								$export_textad_status =0;	
						
						$mysql['export_textad_title'] = mysql_real_escape_string($export_textad_title);
						$mysql['export_textad_description_full'] = mysql_real_escape_string($export_textad_description_full);
						$mysql['export_textad_display_url'] = mysql_real_escape_string($export_textad_display_url);
						$mysql['export_textad_destination_url'] = mysql_real_escape_string($export_textad_destination_url);
						$mysql['export_textad_status'] = mysql_real_escape_string($export_textad_status);
	
						$export_textad_sql = "    INSERT INTO  202_export_textads
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
										  		    				 export_textad_title='".$mysql['export_textad_title']."',
										  		    				 export_textad_description_full='".$mysql['export_textad_description_full']."',
										  		    				 export_textad_display_url='".$mysql['export_textad_display_url']."',
										  		    				 export_textad_destination_url='".$mysql['export_textad_destination_url']."',
										  		    				 export_textad_status='".$mysql['export_textad_status']."'";
						$export_textad_result = _mysql_query($export_textad_sql); 
						$export_textad_id = mysql_insert_id();
	
						$mysql['export_textad_id'] = mysql_real_escape_string($export_textad_id);	
					}
					
					
					//if keyword
					if ( ($row[$x]['Component Type'] == 'Keyword') or ($row[$x]['Component Type'] == 'Ad Group Excluded Word') ) {
						
						$export_keyword_status = $row[$x]['Component Status'];
						$export_keyword = $row[$x]['Keyword'];
						$export_keyword_destination_url = $row[$x]['Keyword Custom URL'];
						$export_keyword_max_cpc = $row[$x]['Sponsored Search Bid (USD)'];
						$export_keyword_match = $row[$x]['Match Type'];
						
						if ($export_keyword_max_cpc == 'Default') 					$export_keyword_max_cpc = 0;	
						
						if ($export_keyword_match == 'Advanced') 					$export_keyword_match = 'broad';	
						elseif ($export_keyword_match == 'Simple') 					$export_keyword_match = 'exact';		
						elseif ($row[$x]['Component Type'] == 'Ad Group Excluded Word') 	$export_keyword_match='negative';	
			
						if ($export_keyword_status == 'On') 							$export_keyword_status = 1;
						else 															$export_keyword_status = 0;	
						
						$mysql['export_keyword_max_cpc'] = mysql_real_escape_string($export_keyword_max_cpc);
						$mysql['export_keyword'] = mysql_real_escape_string($export_keyword);
						$mysql['export_keyword_match'] = mysql_real_escape_string($export_keyword_match);
						$mysql['export_keyword_destination_url'] = mysql_real_escape_string($export_keyword_destination_url);
						$mysql['export_keyword_status'] = mysql_real_escape_string($export_keyword_status);
	
						$export_keyword_sql = "   INSERT INTO   	 202_export_keywords
											  		    SET			 export_session_id='".$mysql['export_session_id']."',
											  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
											  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
											  		    				 export_keyword_max_cpc='".$mysql['export_keyword_max_cpc']."',
											  		    				 export_keyword='".$mysql['export_keyword']."',
											  		    				 export_keyword_match='".$mysql['export_keyword_match']."',
											  		    				 export_keyword_destination_url='".$mysql['export_keyword_destination_url']."',
											  		    				 export_keyword_status='".$mysql['export_keyword_status']."'";
						$export_keyword_result = _mysql_query($export_keyword_sql); 
						$export_keyword_id = mysql_insert_id();
						
						$mysql['export_keyword_id'] = mysql_real_escape_string($export_keyword_id);	
					}
				}
				
				
				//Google Adwords Upload
				if ($_POST['network'] == 'google') { 

					//if this is a campaign
					if ($row[$x]['Campaign Status'] and !$row[$x]['AdGroup Status']) { 
						
						$export_campaign_name = $row[$x]['Campaign'];
						$export_campaign_daily_budget = $row[$x]['Campaign Daily Budget'];
						$export_campaign_status = $row[$x]['Campaign Status'];
						
						if ($export_campaign_status == 'Paused') 	$export_campaign_status = 0;
						else 										$export_campaign_status =1;	

						$mysql['export_campaign_name'] = mysql_real_escape_string($export_campaign_name);
						$mysql['export_campaign_daily_budget'] = mysql_real_escape_string($export_campaign_daily_budget);
						$mysql['export_campaign_status'] = mysql_real_escape_string($export_campaign_status);
					
						$export_campaign_sql = "INSERT INTO  202_export_campaigns
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_name='".$mysql['export_campaign_name']."',
										  		    				 export_campaign_daily_budget='".$mysql['export_campaign_daily_budget']."',
										  		    				 export_campaign_status='".$mysql['export_campaign_status']."'";
						$export_campaign_result = _mysql_query($export_campaign_sql);  
						$export_campaign_id = mysql_insert_id();
	
						$mysql['export_campaign_id'] = mysql_real_escape_string($export_campaign_id);		 	
					}
					
					
					
					//if adgroup
					if ($row[$x]['AdGroup Status'] and !$row[$x]['Creative Status'] and !$row[$x]['Keyword Status']) { 
						
						$export_adgroup_name = $row[$x]['Ad Group'];
						$export_adgroup_max_search_cpc = $row[$x]['Max CPC'];
						$export_adgroup_max_content_cpc = $row[$x]['Max Content CPC'];
						$export_adgroup_status = $row[$x]['AdGroup Status'];
						
						if ($export_adgroup_status == 'Active') 	$export_adgroup_status = 1;
						else 										$export_adgroup_status =0;	
						
						//is search enabled?
						if ((is_numeric($export_adgroup_max_search_cpc)) and  ($export_adgroup_max_search_cpc > 0)) 	$export_adgroup_search = 1;
						else 																									$export_adgroup_search =0;	
						
						//is content enabled?
						if ((is_numeric($export_adgroup_max_content_cpc)) and ($export_adgroup_max_content_cpc > 0)) 	$export_adgroup_content = 1;
						else 																									$export_adgroup_content =0;	

						
						$mysql['export_adgroup_name'] = mysql_real_escape_string($export_adgroup_name);
						$mysql['export_adgroup_max_search_cpc'] = mysql_real_escape_string($export_adgroup_max_search_cpc);
						$mysql['export_adgroup_max_content_cpc'] = mysql_real_escape_string($export_adgroup_max_content_cpc);
						$mysql['export_adgroup_status'] = mysql_real_escape_string($export_adgroup_status);
						$mysql['export_adgroup_search'] = mysql_real_escape_string($export_adgroup_search);
						$mysql['export_adgroup_content'] = mysql_real_escape_string($export_adgroup_content);
						
						
						$export_adgroup_sql = "   INSERT INTO  202_export_adgroups
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_name='".$mysql['export_adgroup_name']."',
										  		    				 export_adgroup_max_search_cpc='".$mysql['export_adgroup_max_search_cpc']."',
										  		    				 export_adgroup_max_content_cpc='".$mysql['export_adgroup_max_content_cpc']."',
										  		    				 export_adgroup_status='".$mysql['export_adgroup_status']."',
										  		    				 export_adgroup_search='".$mysql['export_adgroup_search']."',
										  		    				 export_adgroup_content='".$mysql['export_adgroup_content']."'";
						$export_adgroup_result = _mysql_query($export_adgroup_sql); 
						$export_adgroup_id = mysql_insert_id();

						$mysql['export_adgroup_id'] = mysql_real_escape_string($export_adgroup_id);		 	
					}
					
					if ($row[$x]['Creative Status']) { 
					
						$export_textad_name = $row[$x]['Headline'];
						$export_textad_title = $row[$x]['Headline'];
						$export_textad_description_line1 = $row[$x]['Description Line 1'];
						$export_textad_description_line2 = $row[$x]['Description Line 2'];
						$export_textad_display_url = $row[$x]['Display URL'];
						$export_textad_destination_url = $row[$x]['Destination URL'];
						$export_textad_status = $row[$x]['Creative Status'];
						
						if ($export_textad_status == 'Paused') 	$export_textad_status = 0;
						else 									$export_textad_status =1;	
						
						$mysql['export_textad_name'] = mysql_real_escape_string($export_textad_name);
						$mysql['export_textad_title'] = mysql_real_escape_string($export_textad_title);
						$mysql['export_textad_description_full'] = mysql_real_escape_string($export_textad_description_full);
						$mysql['export_textad_description_line1'] = mysql_real_escape_string($export_textad_description_line1);
						$mysql['export_textad_description_line2'] = mysql_real_escape_string($export_textad_description_line2);
						$mysql['export_textad_display_url'] = mysql_real_escape_string($export_textad_display_url);
						$mysql['export_textad_destination_url'] = mysql_real_escape_string($export_textad_destination_url);
						$mysql['export_textad_status'] = mysql_real_escape_string($export_textad_status);
	
						$export_textad_sql = "   INSERT INTO  202_export_textads
										  		    SET			 export_session_id='".$mysql['export_session_id']."',
										  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
										  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
										  		    				 export_textad_name='".$mysql['export_textad_name']."',
										  		    				 export_textad_title='".$mysql['export_textad_title']."',
										  		    				 export_textad_description_full='".$mysql['export_textad_description_full']."',
										  		    				 export_textad_description_line1='".$mysql['export_textad_description_line1']."',
										  		    				 export_textad_description_line2='".$mysql['export_textad_description_line2']."',
										  		    				 export_textad_display_url='".$mysql['export_textad_display_url']."',
										  		    				 export_textad_destination_url='".$mysql['export_textad_destination_url']."',
										  		    				 export_textad_status='".$mysql['export_textad_status']."'"; 
						$export_textad_result = _mysql_query($export_textad_sql); 
						$export_textad_id = mysql_insert_id();
	
						$mysql['export_textad_id'] = mysql_real_escape_string($export_textad_id);	
					}
					
					
					//if keyword
					if ($row[$x]['Keyword Status'] or ($row[$x]['Keyword Type'] == 'Negative Broad') or ($row[$x]['Keyword Type']== 'Campaign Negative Broad')) {
					
						$export_keyword_max_cpc = $row[$x]['Max CPC'];
						$export_keyword = $row[$x]['Keyword'];
						$export_keyword_match = $row[$x]['Keyword Type'];
						$export_keyword_destination_url = $row[$x]['Destination URL'];
						$export_keyword_status = $row[$x]['Keyword Status'];
						
						if (($export_keyword_match == 'Negative Broad') or ($export_keyword_match == 'Campaign Negative Broad')) {
							
							//if the keyword is set to the campaign leve, remove the adgroup_id, so it sets to just the campaign
							if ($export_keyword_match == 'Campaign Negative Broad') {
								$export_adgroup_id = 0;
								$mysql['export_adgroup_id'] = mysql_real_escape_string($export_adgroup_id);
							}
							
							$export_keyword_match = 'negative';	
						}
						
						if ($export_keyword_status == 'Paused') 	$export_keyword_status = 0;
						else 										$export_keyword_status =1;	
						
						$mysql['export_keyword_max_cpc'] = mysql_real_escape_string($export_keyword_max_cpc);
						$mysql['export_keyword'] = mysql_real_escape_string($export_keyword);
						$mysql['export_keyword_match'] = mysql_real_escape_string($export_keyword_match);
						$mysql['export_keyword_destination_url'] = mysql_real_escape_string($export_keyword_destination_url);
						$mysql['export_keyword_status'] = mysql_real_escape_string($export_keyword_status);
	
						$export_keyword_sql = "   INSERT INTO   	 202_export_keywords 
											  		    SET			 export_session_id='".$mysql['export_session_id']."',
											  		    				 export_campaign_id='".$mysql['export_campaign_id']."',
											  		    				 export_adgroup_id='".$mysql['export_adgroup_id']."',
											  		    				 export_keyword_max_cpc='".$mysql['export_keyword_max_cpc']."',
											  		    				 export_keyword='".$mysql['export_keyword']."',
											  		    				 export_keyword_match='".$mysql['export_keyword_match']."',
											  		    				 export_keyword_destination_url='".$mysql['export_keyword_destination_url']."',
											  		    				 export_keyword_status='".$mysql['export_keyword_status']."'";
						$export_keyword_result = _mysql_query($export_keyword_sql); 
						$export_keyword_id = mysql_insert_id();
						
						$mysql['export_keyword_id'] = mysql_real_escape_string($export_keyword_id);	
	
					}
				}
			}
			
			fclose($handle); 
			
			if (!$error) { 
				header('location: /export202/export/?id='.$export_session_id_public);
			}
		}
	}
} 


template_top('Export202'); 

include_once('form.php');

template_bottom();
<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
	
//set the timezone for this user.
	AUTH::set_timezone($_SESSION['user_timezone']);
	
//grab the users date range preferences
	$time = grab_timeframe(); 
	$mysql['to'] = mysql_real_escape_string($time['to']);
	$mysql['from'] = mysql_real_escape_string($time['from']); 
	
	
//show real or filtered clicks
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT * FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysql_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);	
	
	$html['user_pref_group_1'] = htmlentities($user_row['user_pref_group_1'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_2'] = htmlentities($user_row['user_pref_group_2'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_3'] = htmlentities($user_row['user_pref_group_3'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_4'] = htmlentities($user_row['user_pref_group_4'], ENT_QUOTES, 'UTF-8');
	
	if ($user_row['user_cpc_or_cpv'] == 'cpv') {
		$cpv = true;
	} else {
		$cpv = false;
	}
	
	$summary_form = new ReportSummaryForm();
	$summary_form->setDetails(array($user_row['user_pref_group_1'],$user_row['user_pref_group_2'],$user_row['user_pref_group_3'],$user_row['user_pref_group_4']));
	$summary_form->setDetailsSort(array(ReportBasicForm::SORT_NAME));
	$summary_form->setDisplayType(array(ReportBasicForm::DISPLAY_TYPE_TABLE));
	$summary_form->setStartTime($mysql['from']);
	$summary_form->setEndTime($mysql['to']);

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);

	$info_result = _mysql_query($summary_form->getQuery($mysql['user_id'],$user_row));
	
	while ($row = mysql_fetch_assoc($info_result)) {
		$summary_form->addReportData($row);
	}

	header("Content-type: application/octet-stream");
	header("Content-Disposition: attachment; filename=\"GroupOverviewReport" . date("mdy") . ".csv\"");
	header("Expires: 0");
	header("Pragma: no-cache");
	ReportBasicForm::echoCell("Group Overview Report for " . date("m/d/Y", strtotime($summary_form->getStartDate())) . " to " . date("m/d/Y", strtotime($summary_form->getEndDate())));
	ReportBasicForm::echoRow();
	ReportBasicForm::echoCell($summary_form->getRanOn());
	ReportBasicForm::echoRow();
	ReportBasicForm::echoRow();

	if (count($summary_form->getReportData()->getChildArrayBySort()) > 0) {
		$summary_form->getExportRowHeaderHtml();
		
		/* @var $summary_form_detail_1 Form */
		foreach ($summary_form->getReportData()->getChildArrayBySort() as $summary_form_detail_1) {
			if (count($summary_form_detail_1->getChildArrayBySort()) > 0) {
				/* @var $summary_form_detail_2 Form */
				foreach ($summary_form_detail_1->getChildArrayBySort() as $key => $summary_form_detail_2) {
					if (count($summary_form_detail_2->getChildArrayBySort()) > 0) {
						/* @var $summary_form_detail_3 Form */
						foreach ($summary_form_detail_2->getChildArrayBySort() as $summary_form_detail_3) {
							if (count($summary_form_detail_3->getChildArrayBySort()) > 0) {
								/* @var $summary_form_detail_4 Form */
								foreach ($summary_form_detail_3->getChildArrayBySort() as $summary_form_detail_4) {
									if (count($summary_form_detail_4->getChildArrayBySort()) > 0) {
										/* @var $summary_form_detail_5 Form */
										foreach ($summary_form_detail_4->getChildArrayBySort() as $summary_form_detail_5) {
											$summary_form->getExportRowHtml($summary_form_detail_5);
										}
									} else {
										$summary_form->getExportRowHtml($summary_form_detail_4);
									}
								}
							} else {
								$summary_form->getExportRowHtml($summary_form_detail_3);
							}
						}
					} else {
						$summary_form->getExportRowHtml($summary_form_detail_2);
					}
				}
			} else {
				$summary_form->getExportRowHtml($summary_form_detail_1);
			}
		}
	} else {
		ReportBasicForm::echoCell("no data for this selected date range");
		ReportBasicForm::echoRow();
	}

?>


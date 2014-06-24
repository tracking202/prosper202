<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
AUTH::require_user('toolbar');
AUTH::set_timezone($_SESSION['user_timezone']);
include_once('202-ministats.php');
?>



<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<script type='text/javascript' src='http://partner.googleadservices.com/gampad/google_service.js'>
</script>
<script type='text/javascript'>
GS_googleAddAdSenseService("ca-pub-9868787942961354");
GS_googleEnableAllServices();
</script>
<script type='text/javascript'>
GA_googleAddSlot("ca-pub-9868787942961354", "T202Bar_Sponsors_250x60");
</script>
<script type='text/javascript'>
GA_googleFetchAds();
</script>
<title>Mini Account Overview</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="description" />
<meta name="keywords" content="keywords" />
<meta name="copyright" content="Prosper202, Inc" />
<meta name="author" content="Prosper202, Inc" />
<meta name="MSSmartTagsPreventParsing" content="TRUE" />
<meta name="viewport" content = "width=device-width ,  user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta http-equiv="refresh" content="10">
<meta name="robots" content="noindex, nofollow" />

<!-- Loading Bootstrap -->
<link href="/202-css/css/bootstrap.min.css" rel="stylesheet">
<!-- Loading Flat UI -->
<link href="/202-css/css/flat-ui.css" rel="stylesheet">
<!-- Loading Custom CSS -->
<link href="/202-css/custom.css" rel="stylesheet">

<script src="http://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
<script src="/202-js/bootstrap.min.js"></script>


</head>
<body onload="setTimeout(function() { window.scrollTo(0, 1) }, 1);" id="ministats" class="ministats">
	<div class="container" style="width: 100%;">
	<div class="main_wrapper">
		<center><img src="/202-img/prosper202.png"></center>
		<div class="main">
			<div class="row">
	  		<div class="col-xs-12">
	  			<center><small>202 Mini Account Overview</small></center>
	  			<table class="table table-bordered" id="stats-table" style="margin-top:10px;">
				<thead>
				</thead>
				<tbody>
					<tr style="background-color: #f2fbfa;">
						<td>Clicks</td>
						<td><strong><?php echo $html['total_clicks']; ?></strong></td>
					</tr>
					<tr>
						<td>Leads</td>
						<td><strong><?php echo $html['total_leads']; ?></strong></td>
					</tr>
					<tr style="background-color: #f2fbfa;">
						<td>S/U</td>
						<td><strong><?php echo $html['total_su_ratio']; ?></strong></td>
					</tr>
					<tr>
						<td>EPC</td>
						<td><strong><?php echo $html['total_epc']; ?></strong></td>
					</tr>
					<tr style="background-color: #f2fbfa;">
						<td>CPC</td>
						<td><strong><?php echo $html['total_avg_cpc']; ?></strong></td>
					</tr>
					<tr>
						<td>Income</td>
						<td><strong><?php echo $html['total_income']; ?></strong></td>
					</tr>
					<tr style="background-color: #f2fbfa;">
						<td>Cost</td>
						<td><strong>(<?php echo $html['total_cost']; ?>)</strong></td>
					</tr>
					<tr>
						<td>Net</td>
						<td><strong><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['total_net']; ?></span></strong></td>
					</tr>
					<tr style="background-color: #f2fbfa;">
						<td>ROI</td>
						<td><strong><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>">(<?php echo $html['total_roi']; ?>)</span></strong></td>
					</tr>
				<tbody>
					
				</table>
				<center><span class="infotext">Stats are updated every 10 seconds.</span></center><br/>

				<center><span class="infotext"><a href="/202-account">Main website</a></span></center>
	  		</div>
		</div>
		</div>
	</div>

</body>
</html>

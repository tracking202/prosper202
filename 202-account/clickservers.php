<?php


include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php');
include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/clickserver_api_management.php');

AUTH::require_user();

//get all of the user data
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "	SELECT 	`clickserver_api_key`
				 FROM   	`202_users` 
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
if ($user_row['clickserver_api_key']) {
	$clickservers = clickserver_api_domain_list($user_row['clickserver_api_key']);
}

template_top('ClickServer Management',NULL,NULL,NULL); 

?>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>Manage Your ClickServers</h6>
			</div>
			<div class="col-xs-8">
				<div id="response-body" style="text-align:right">
					<span id="response-text"></span>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
				A complete list of all domains activated with your Prosper202 ClickServer API Key. From here you can activate and de-activate domains.
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<div class="row">
			<div class="col-xs-12">
			    <table class="table table-bordered">
			        <thead>
			            <tr>
			              <th class="col-xs-10">Domains</th>
			              <th>Status</th>
			            </tr>
			        </thead>
			        <tbody>
			            <tr>
			               	<?php
			               	if ($user_row['clickserver_api_key']) {
		                        $row_id = 1;        
		                        foreach($clickservers as $clickserver){ ?>
		                          <tr>
		                            <td><span class="glyphicon glyphicon-link"></span><em><?php echo $clickserver['clickserver']['domain'];?></em></td>
		                            <td><input type="checkbox" clickserverid="<?php echo $clickserver['clickserver']['domain'];?>" data-toggle='switch' <?php if($clickserver['clickserver']['status'] == 1) echo 'checked'?>></td>
		                          </tr>
		                	<?php $row_id++; }}?>
			            </tr>
			        </tbody>
			    </table>
	        </div>
	        <div class="col-xs-12">
	        <?php
		        if ($user_row['clickserver_api_key']) { 
			        $license_date = clickserver_api_license($user_row['clickserver_api_key']);
			        $usage = ($license_date['domainsUsed'] / $license_date['domainsAvail']) * 100;
			    }
		    ?>
		    <div class="row">
		    	<div class="col-xs-2">
			        <span class="infotext">License used:</span>
			    </div>
			    <div class="col-xs-10">
			        <div class="progress" style="margin-top: 8px;">
			            <div class="progress-bar" id="license_usage_bar" style="width: <?php echo $usage;?>%; background: #1d950c; line-height: 1.1;font-size: 10px; margin-bottom: 10px;"><span id="usage"><?php echo $usage;?>%</span></div>
			        </div>
			    </div>
		    </div>
		        <span class="infotext">(<span id="domainsUsed"><?php echo $license_date['domainsUsed'];?></span> out of <?php echo $license_date['domainsAvail'];?> Activated Domains used)</span>
		    </div> 
	    </div>
	</div>
</div>

<script type="text/javascript">
$(document).ready(function() {
	var api_key = "<?php echo base64_encode($user_row['clickserver_api_key']);?>";
    var domainsUsed = <?php echo $license_date['domainsUsed'];?>;
    var domainsAvail = <?php echo $license_date['domainsAvail'];?>;

    $("input[type='checkbox']").change(function () {
    	var checkbox =  $(this);
        var clid = checkbox.attr('clickserverid');
        var method;

        if (checkbox.is(':checked')) {
            method = 'activate';
        } else {
            method = 'deactivate';
        }

        data = new Object();
        data['clickserver_id'] = clid;
        data['api_key'] = api_key;
        data['method'] = method;

        $.ajax({
	        url : "../202-config/clickserver_api_management.php",
	        type: "POST",
	        data: data,
	        cache: false,
	        success: function(data)
	            {

	                if(data == false) {
	                	$('#response-body').css("color", "#e74c3c");

	                    $('#response-text').html('<small><span class="fui-alert"> There was a problem changing your ClickServer. Please contact support!</span></span></small>');

	                	$(checkbox).bootstrapSwitch('toggleState');

	                } else {
	                    if("<?php echo $_SERVER['HTTP_HOST'];?>" == clid){
                            window.location.href = "../202-account/signout.php";
                        }

                        if (method == 'activate') {
                            ++domainsUsed;
                            $("#domainsUsed").text(domainsUsed);
                            $("#license_usage_bar").css("width", (domainsUsed / domainsAvail) * 100 + '%');
                            $("#usage").text((domainsUsed / domainsAvail) * 100 + '%');
                        } else {
                            --domainsUsed;
                            $("#domainsUsed").text(domainsUsed);
                            $("#license_usage_bar").css("width", (domainsUsed / domainsAvail) * 100 + '%');
                            $("#usage").text((domainsUsed / domainsAvail) * 100 + '%');
                        }

                        $('#response-body').css("color", "#1d950c");
                        $('#response-text').html('<small><span class="fui-check-inverted"> Your ClickServer changed successfully.</span></span></small>');
	                    
	                }
	            }
	    });
	});
});
</script>
<?php template_bottom();
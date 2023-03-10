<?php

//include functions
require_once(dirname( __FILE__ ) . '/functions.php');


//check to see if the sample config file exists
if (!file_exists(substr(dirname( __FILE__ ), 0,-10) . '/202-config-sample.php')) {
	_die('Sorry, I need a 202-config-sample.php file to work from. Please re-upload this file from your Prosper202 installation.');
}


//lets make a new config file
$configFile = file(substr(dirname( __FILE__ ), 0,-10).'/202-config-sample.php');


//check to see if the directory is writable
if ( !is_writable(substr(dirname( __FILE__ ), 0,-10) . '/')) {
	_die("Sorry, I can't write to the directory. You'll have to either change the permissions on your Prosper202 directory or create your 202-config.php manually.");
}


// Check if 202-config.php has been created
if (file_exists('../202-config.php')) {
	//_die("<p>The file '202-config.php' already exists. If you need to reset any of the configuration items in this file, please delete it first. You may try <a href='install.php'>installing now</a>.</p>");
    $re = '/(\$\w*) = \'(\w*)\';/i';
    
    $handle = fopen(substr(dirname( __FILE__ ), 0,-10) . '/202-config.php', 'r');
    $old = [];
    while(!feof($handle)) {
        $line = fgets($handle);
        preg_match($re, $line, $matches);
    switch ($matches[1]) {
            case '$dbname':
                $odbname=$matches[2];
                break;
            case '$dbuser':
                $odbuser=$matches[2];
                break;
            case '$dbpass':
                $odbpass=$matches[2];
                break;
            case '$dbhost':
                $odbhost=$matches[2];
                break;
           case '$dbhostro':
                $odbhostro=$matches[2];
                break;
            case '$mchost':
                $omchost=$matches[2];
                break;            
        }
    }
    fclose($file);
     
}



if (isset($_GET['step'])) {
	$step = $_GET['step'];
} else {
	$step = 0;
}


	
switch($step) {
	case 0:
		info_top();
?>
<div class="main col-xs-7 install">
<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<small>Welcome to Prosper202. Before getting started, we need some information about your database. You will need to know the following items before proceeding.</small>
	<br></br>
	<small><ul>
		<li>Database name</li>
		<li>Database username</li>
		<li>Database password</li>
		<li>Database host</li>
		<li>Reporting Database host (optional)</li>
		<li>Memcache host (optional)</li>
	</ul></small>
	<small><strong>If for any reason this automatic file creation doesn't work, don't worry. All this does is fill in the database information to a configuration file. You may also simply open <code>202-config-sample.php</code> in a text editor, fill in your information, and save it as <code>202-config.php</code>. </strong>
	<br></br>
	In all likelihood, these items were supplied to you by your ISP. If you do not have this information, then you will need to contact them before you can continue. 
	<br></br>
	If you&#8217;re all ready, <a href="setup-config.php?step=1" class="btn btn-xs btn-p202">let&#8217;s go!</a></p>
	</div>
	<?php

			info_bottom();

		break; 

		case 1:
		case 1.1:
			info_top();
		?>
	</p>
	<?php 
	if($step==1){
	    $msg="Enter your database connection details. If you're not sure about these, contact your host";
	    $action="setup-config.php?step=2";
	}else{
	    $msg="Your 202-config.php needs to be updated to a new format. Please review the settings we got from your old file and make changes as needed";
	    $action="setup-config.php?step=2.2";
	}
	
	?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<small><?php echo $msg;?></small>

	<form method="post" action="<?php echo $action;?>" class="form-horizontal" role="form" style="margin-top:10px;">
			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="dbname" class="col-xs-4 control-label" style="text-align:left"><strong>Database Name:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="dbname" name="dbname" value="<?php echo (isset($odbname) ? $odbname : 'prosper202');?>">
			      <span class="help-block" style="font-size: 10px;">The name of the database you want to run Prosper202 in.</span>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="dbuser" class="col-xs-4 control-label" style="text-align:left"><strong>User Name:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="dbuser" name="dbuser" value="<?php echo (isset($odbuser) ? $odbuser : 'username');?>">
			      <span class="help-block" style="font-size: 10px;">Your MySQL username</span>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="dbpass" class="col-xs-4 control-label" style="text-align:left"><strong>Password:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="dbpass" name="dbpass" value="<?php echo (isset($odbpass) ? $odbpass : 'password');?>">
			      <span class="help-block" style="font-size: 10px;">...and MySQL password.</span>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="dbhost" class="col-xs-4 control-label" style="text-align:left"><strong>Database Host:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="dbhost" name="dbhost" value="<?php echo (isset($odbhost) ? $odbhost : 'localhost');?>">
			      <span class="help-block" style="font-size: 10px;">99% chance you won't need to change this value.</span>
			    </div>
			</div>

            <div class="form-group" style="margin-bottom: 0px;">
			    <label for="dbhost" class="col-xs-4 control-label" style="text-align:left"><strong>Reporting Database:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="dbhostro" name="dbhostro" value="<?php echo (isset($odbhostro) ? $odbhostro : 'localhost');?>">
			      <span class="help-block" style="font-size: 10px;">If you have a dedicated db for running reports, enter it here. If not, leave as localhost. </span>
			    </div>
			</div>
			
			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="mchost" class="col-xs-4 control-label" style="text-align:left"><strong>Memcache Host:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="mchost" name="mchost" value="<?php echo (isset($omchost) ? $omchost : 'localhost');?>">
			      <span class="help-block" style="font-size: 10px;">If you don't know what this is, leave it alone.</span>
			    </div>
			</div>

			<div class="form-group">
			    <label for="prefix" class="col-xs-4 control-label" style="text-align:left"><strong>Table Prefix:</strong></label>
			    <div class="col-xs-8" style="margin-top: 5px;">
			      <input type="text" class="form-control input-sm" id="prefix" name="prefix" value="202_" readonly="true">
			      <span class="help-block" style="font-size: 10px;">The table prefix that will be used, this can not be changed.</span>
			    </div>
			</div>

			<button class="btn btn-sm btn-p202 btn-block" type="submit">Save database credentials<span class="fui-check-inverted pull-right"></span></button>
	</form>
</div>
<?php
		
		info_bottom();
	break;

	case 2:
	case 2.2:
	$dbname  = trim($_POST['dbname']);
	$dbuser   = trim($_POST['dbuser']);
	$dbpass = trim($_POST['dbpass']);
	$dbhost  = trim($_POST['dbhost']);
	$dbhostro  = trim($_POST['dbhostro']);
	$mchost  = trim($_POST['mchost']);

	//see if it can connect to the mysql host server
	$connect = @mysqli_connect($dbhost,$dbuser,$dbpass,$dbname);
	
	//if it could not connect, error
	if (!$connect) {
		_die("<h6>Error establishing a database connection</h6>
			<p><small>This either means that the username and password information in your <code>202-config.php</code> file is incorrect or we can't contact the database server at <code>$dbhost</code>. This could mean your host's database server is down.</small></p>
			<small>
			<ul> 
				<li>Are you sure you have the correct username and password?</li>
				<li>Are you sure that you have typed the correct hostname?</li>
				<li>Are you sure that you have typed the correct database name?</li>
				<li>Are you sure that the database server is running?</li>
			</ul>
			</small> 
			<p><small>If you're unsure what these terms mean you should probably contact your host. If you still need help, please visit the <a href='http://support.tracking202.com/how-to-set-up-and-use-prosper202-pro/installing-prosper202?utm_source=db-install-error'>Prosper202 Support Site</a>.</small> </p>
			<p><a href='setup-config.php?step=1' class='btn btn-sm btn-p202 btn-block'>Go back and enter your database credentials again!</a></p>
		");
	}
    //regex to find values in the config file
	$re = '/(\$\w*) = \'(\w*)\';/i';

	$handle = fopen(substr(dirname( __FILE__ ), 0,-10) . '/202-config.php', 'w');

	foreach ($configFile as $line_num => $line) {
	    preg_match($re, $line, $matches);
		switch ($matches[1]) {
			case '$dbname':
				fwrite($handle, str_replace("putyourdbnamehere", $dbname, $line));
				break;
			case '$dbuser':
				fwrite($handle, str_replace("'usernamehere'", "'$dbuser'", $line));
				break;
			case '$dbpass':
				fwrite($handle, str_replace("'yourpasswordhere'", "'$dbpass'", $line));
				break;
			case '$dbhost':
				fwrite($handle, str_replace("localhost", $dbhost, $line));
				break;
		    case '$dbhostro':
	       	    fwrite($handle, str_replace("localhost", $dbhostro, $line));
				break;				
			case '$mchost':
				fwrite($handle, str_replace("localhost", $mchost, $line));
				break;
			default:
				fwrite($handle, $line);
		}
	}
	fclose($handle);
	chmod(substr(dirname( __FILE__ ), 0,-10) . '/202-config.php', 0666);
	
	_die("<p>All right sparky! You've made it through this part of the installation. Prosper202 can now communicate with your database. If you are ready, go ahead and <a class='btn btn-xs btn-p202' href=\"install.php\">run the install!</a></p>");
	break;
}

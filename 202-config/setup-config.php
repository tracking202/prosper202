<?php

//include functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions.php');


//check to see if the sample config file exists
if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/202-config-sample.php')) {
	_die('Sorry, I need a 202-config-sample.php file to work from. Please re-upload this file from your Prosper202 installation.');
}


//lets make a new config file
$configFile = file($_SERVER['DOCUMENT_ROOT'].'/202-config-sample.php');


//check to see if the directory is writable
if ( !is_writable($_SERVER['DOCUMENT_ROOT'] . '/')) {
	_die("Sorry, I can't write to the directory. You'll have to either change the permissions on your Prosper202 directory or create your 202-config.php manually.");
}


// Check if 202-config.php has been created
if (file_exists('../202-config.php')) {
	_die("<p>The file '202-config.php' already exists. If you need to reset any of the configuration items in this file, please delete it first. You may try <a href='install.php'>installing now</a>.</p>");
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

<p>Welcome to Prosper202. Before getting started, we need some information on the database. You will need to know the following items before proceeding.</p>
<ol>
	<li>Database name</li>
	<li>Database username</li>
	<li>Database password</li>
	<li>Database host</li>
	<li>Memcache host (optional)</li>
</ol>
<p><strong>If for any reason this automatic file creation doesn't work, don't worry. All this does is fill in the database information to a configuration file. You may also simply open <code>202-config-sample.php</code> in a text editor, fill in your information, and save it as <code>202-config.php</code>. </strong></p>
<p>In all likelihood, these items were supplied to you by your ISP. If you do not have this information, then you will need to contact them before you can continue. If you&#8217;re all ready, <a href="setup-config.php?step=1">let&#8217;s go</a>! </p>
<?php

		info_bottom();

	break; 

	case 1:
		info_top();
	?>
</p>
<form method="post" action="setup-config.php?step=2" >
	<p>Below you should enter your database connection details. If you're not sure about these, contact your host. </p>
	<table class="config" cellspacing="0" cellpadding="5">
		<tr>
			<th >Database Name</th>
			<td><input name="dbname" type="text" size="25" value="prosper202" /></td>
			<td>The name of the database you want to run Prosper202 in. </td>
		</tr>
		<tr>
			<th>User Name</th>
			<td><input name="dbuser" type="text" size="25" value="username" /></td>
			<td>Your MySQL username</td>
		</tr>
		<tr>
			<th >Password</th>
			<td><input name="dbpass" type="text" size="25" value="password" /></td>
			<td>...and MySQL password.</td> 
		</tr>
		<tr>
			<th >Database Host</th>
			<td><input name="dbhost" type="text" size="25" value="localhost" /></td>
			<td>99% chance you won't need to change this value.</td>
		</tr>
		<tr>
		<th >Memcache Host</th>
			<td><input name="mchost" type="text" size="25" value="localhost" /></td>
			<td>If you don't know what this is, leave it alone.</td>
		</tr>
		<tr>
			<th >Table Prefix</th>
			<td><input name="prefix" type="text" id="prefix" value="202_" size="25" readonly="true" /></td>
			<td>The table prefix that will be used, this can not be changed.</td>
		</tr>
	</table>
	<h2 class="step">
	<input name="submit" type="submit" value="Submit" />
	</h2>
</form>
<?php
		
		info_bottom();
	break;

	case 2:
	$dbname  = trim($_POST['dbname']);
	$dbuser   = trim($_POST['dbuser']);
	$dbpass = trim($_POST['dbpass']);
	$dbhost  = trim($_POST['dbhost']);
	$mchost  = trim($_POST['mchost']);

	//see if it can conncet to the mysql host server
	$connect = @mysql_connect($dbhost, $dbuser, $dbpass);
	
	//if it could not connect, error
	if (!$connect) {
		_die("
			<h2>Error establishing a database connection</h2>
			<p>This either means that the username and password information in your <code>202-config.php</code> file is incorrect or we can't contact the database server at <code>$dbhost</code>. This could mean your host's database server is down.</p>
			<ul>
				<li>Are you sure you have the correct username and password?</li>
				<li>Are you sure that you have typed the correct hostname?</li>
				<li>Are you sure that the database server is running?</li>
			</ul>
			<p>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://Prosper202.com/forum'>Prosper202 Support Forums</a>.</p>
			");
	}
	
	//now see if it can conncet to the individual mysql database
	
		if (!@mysql_select_db($dbname, $connect)) {
			_die("
				<h2>Can&#8217;t select database</h2>
				<p>We were able to connect to the database server (which means your username and password is okay) but not able to select the <code>$dbname</code> database.</p>
				<ul>
				<li>Are you sure it exists?</li>
				<li>Does the user <code>$dbuser</code> have permission to use the <code>$dbname</code> database?</li>
				<li>On some systems the name of your database is prefixed with your username, so it would be like username_Prosper202. Could that be the problem?</li>
				</ul>
				<p>If you don't know how to setup a database you should <strong>contact your host</strong>. If all else fails you may find help at the <a href='http://Prosper202.com/forum'>Prosper202 Support Forums</a>.</p>");
		}
	

	$handle = fopen($_SERVER['DOCUMENT_ROOT'] . '/202-config.php', 'w');

	foreach ($configFile as $line_num => $line) {
		switch (substr($line,0,7)) {
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
			case '$mchost':
				fwrite($handle, str_replace("localhost", $mchost, $line));
				break;
			default:
				fwrite($handle, $line);
		}
	}
	fclose($handle);
	chmod($_SERVER['DOCUMENT_ROOT'] . '/202-config.php', 0666);
	
	_die("<p>All right sparky! You've made it through this part of the installation. Prosper202 can now communicate with your database. If you are ready, time now to <a href=\"install.php\">run the install!</a></p>");
	break;
}

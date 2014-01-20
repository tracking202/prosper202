<?php

//include mysql settings
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');


//check to see if this is already installed, if so don't do anything
	if (  upgrade_needed() == false) {
		
		_die("<h2>Already Upgraded</h2>
			   Your Prosper202 version $version is already upgraded." ); 	
	 
	}



if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
	
	if (UPGRADE::upgrade_databases() == true) { 
		$success = true;	
	} else {
		$error = true;	
	}
}




//only show install setup, if it, of course, isn't install already.

if ($success != true) {

	info_top(); ?>	
	
	<? if ($error == true) { ?>
	
		<h2 style="color: #900;">An error occured</h2>
		<span style="color: #900;">An unexpected error occured while you were trying to upgrade, please try again or if you keep encountering problems please contact <a href="http://getsatisfaction.com/tracking202">our support forum</a>.</span>
		<br/><br/>
	<? } ?>
	
	<h2>Upgrade to Prosper202 <? echo $version; ?></h2>
	<!--<span style="color: #900;">YOU ARE ABOUT TO UPGRADE YOUR PROSPER202 SOFTWARE. <strong>YOU NEED TO MAKE SURE YOU DELETE ALL OF THE PREVIOUS FILES BEFORE YOU UPLOADED THIS NEW VERSION</strong>.  THE ROOT WEB DIRECTORY SHOULD HAVE BEEN WIPED CLEAN BEFORE UPLOADING THESES FILES.  IF YOU SIMPLY UPLOADED THE FILES AND REPLACED THE EXISTING ONES, YOU NEED TO GO BACK REMOVE ALL OLD FILES, DELETE THEM, AND THEN UPLOAD THE NEW PROSPER202 FILES TO A CLEAN DOMAIN.  PLEASE FOLLOW THE INSTRUCTIONS EXACTLY <a href="http://prosper202.com/apps/docs/upgrading/">SHOWN HERE</a>.</span><br/><br/>
	<span style="color: #900;">IT IS EXTREMELY IMPORTANT THAT YOU DELETED ALL THE OLD FILES BEFORE UPLOADING AND INSTALLING THIS NEW VERSION. BECAUSE IF YOU DID NOT YOUR PROSPER202 INSTALL WILL HAVE A KNOWN SECURITY VULNERABILTLY.  MORE INFORMATION ON THIS CAN BE FOUND <a href="http://prosper202.com/blog/going-opensource-prosper-111-release-with-emergency-security-release">HERE</a>.</span><br/><br/>-->

	
	You are upgrading from version <? echo PROSPER202::mysql_version(); ?> to <? echo $version; ?>.  To continue with the upgrade press the button below to begin the update process.  Please do not click the button twice.  This could take a while depending on the last time you updated your software. 

		<form method="post">
			<table cellspacing="0" cellpadding="5" class="config" style="margin: 0px auto;">
				<tr>
					<td/>
					<td ><br/><input id="submit" type="submit" value="Upgrade Prosper202 &raquo;" style="font-size: 1.2em;"/></td>
				</tr>
			</table>
		</form>
	
	<?  info_bottom(); 
	
}


//if success is equal to true, and this campaign did complete
if ($success == true) {
	
	info_top(); ?>
	
	<h2>Success!</h2>
	<p>Prosper202 has been upgraded! Now you can <a href="/202-login.php">log in</a>.</p>
	
	<? info_bottom();
	
}

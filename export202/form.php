
<h4>What is Export202?</h4>
<p>Export202 is a free simple utilty developed by <a href="<? echo $config['t202Url']; ?>">Tracking202</a>, that allows anyone to duplicate a succesful PPC campaign from Yahoo SEM or Google Adwords, to other networks in seconds.  It allows you to import a single campaign from Yahoo or Google Adwords.  You can even import an entire account with several campaigns in it at once, and quickly export it to MSN, Google or Yahoo in seconds.  Take your profitable campaigns and replicate them to more PPC networks in just seconds, absolutely free.  </p>

<h4>To use this tool please follow theses steps:</h4>
<ul>
	<li>1. Export your campaign(s) from Google, Yahoo or MSN.
		<ul>
			<li>a. Adwords - to export your campaign(s), open up <a href="http://www.google.com/intl/en/adwordseditor/">Adwords Editor</a> and goto 'File -> Export to .CSV'</li>
			<li>b. Yahoo - to export your campaign, open up Yahoo Search Marketing, open the campaign you want to use, and hit the "Download Campaign" button.</li>
			<li>c. MSN - to export your campaign(s), open up your <a href="http://prosper.tracking202.com/blog/download-msn-adcenter-desktop-client">MSN AdCenter Desktop Client</a>, open the account you'd like to export, then goto the main menu button and hit 'Export -> Export to .CSV'</li>
		</ul>
	</li>
	<li>2. Once you've exported the file, please upload the file here</li>
	<li>3. Select the network, that this .csv was uploaded from.</li>
	<li>4. It will then output the instructions to now import these campaign(s) into the other PPC networks.</li>
</ul>

<br/>

<div class="csv-div">
	<form enctype="multipart/form-data" action="/export202/" method="post">
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />  
		<? if ($error) { ?>
			<div class="warning"><div><h3>There were errors with your submission.</h3></div></div>
		<? } ?>
		<table class="csv-table" cellspacing="10" cellpadding="15" style="width: 0%; " align="left">
			<tr>
				<th>Upload .CSV file</th>
				<td><input type="file" class="csv-file" name="csv" value="<? echo $_POST['csv']; ?>"/> <? echo $error['csv']; ?>
			</tr>
			<tr valign="top">
				<th>Uploaded File Is From</th>
				<td>
					<input type="radio" class="csv-radio" name="network" <? if ($_POST['network'] == 'google') { echo ' CHECKED '; } ?> value="google"> a <strong>Google Adwords</strong> account<br/>
					<input type="radio" class="csv-radio" name="network" <? if ($_POST['network'] == 'msn') { echo ' CHECKED '; } ?> value="msn"> an <strong>MSN AdCenter</strong> account <br/>
					<input type="radio"  class="csv-radio"name="network" <? if ($_POST['network'] == 'yahoo') { echo ' CHECKED '; } ?> value="yahoo"> a <strong>Yahoo Search Marketing</strong> account<br/>
					<? echo $error['network'] . $error['type']; ?>
				</td>
			<tr>
				<th/>
				<td><? echo $error['token']; ?><br/><input class="csv-submit" type="submit" value="Start Conversion"></td>
			</tr>	
		</table>
	</form>
</div>
<div style="clear: both;"></div>
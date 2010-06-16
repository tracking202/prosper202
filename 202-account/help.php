<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

template_top('Administration',NULL,NULL,NULL);  ?>


<hr/><h2 style="text-align: center;">Help Resources</h2>

<p style="text-align: center;">Here are some places you can find help regarding Tracking202 & Prosper202</p>

<table cellspacing="0" cellpadding="10" style="margin: 0px auto; padding-left: 100px;" >
	<tr>
		<th>Prosper202 Documentation:</th>
		<td><a href="http://prosper.tracking202.com/apps/docs/">http://prosper.tracking202.com/apps/docs/</a></td>
	 </tr>
	 <tr>
		<th>Tracking202 Videos:</th>
		<td><a href="http://tracking202.com/videos/">http://tracking202.com/videos/</a></td>
	</tr>
	<tr>
		<th>Tracking202 Tutorials:</th>
		<td><a href="http://tracking202.com/tutorials/">http://tracking202.com/tutorials/</a></td>
	</tr>
	<tr>
		<th>Tracking202 FAQ:</th>
		<td><a href="http://tracking202.com/faq/">http://tracking202.com/faq/</a></td>
	</tr>
	<tr>
		<th>Tracking202 Scripts:</th>
		<td><a href="http://prosper.tracking202.com/scripts/">http://prosper.tracking202.com/scripts/</a></td>
	</tr>
	<tr>
		<th>Support Forum:</th>
		<td><a href="http://prosper.tracking202.com/forum/">http://prosper.tracking202.com/forum/</a></td>
	</tr>
	<tr>
		<th>Prosper202 Blog:</th>
		<td><a href="http://prosper.tracking202.com/blog/">http://prosper.tracking202.com/blog/</a></td>
	 </tr>
	<tr>
		<th>How Subids Work:</th>
		<td><a href="http://subids.tracking202.com/">http://subids.tracking202.com/</a></td>
	 </tr>
	<tr>
		<th>Affiliate Marketing Interviews</th>
		<td><a href="http://tv202.com/">http://tv202.com/</a></td>
	 </tr>
	
</table>




<? template_bottom();
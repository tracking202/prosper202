<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();





//this deletes all this users cached data to the old result sets, we want new stuff because they just updated old clicks
	memcache_delete_user_keys();

template_top('Clear Subids',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Delete all subids for a specific campaign.</h2>
	If you accidentally uploaded all of your subids, instead of only the converted subids, you can delete them all here, and then reupload again! 
</div>

	<form id="clear_subids_form" method="post" >
		<table class="setup">
			<tr  id="tracker_aff_network" >
				<td class="left_caption">Affiliate Network</td>
				<td>
	                    <img id="aff_network_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
	                    <div id="aff_network_id_div"></div>
	                </td>
			</tr>                                             
			<tr id="tracker_aff_campaign">
				<td  class="left_caption">Campaign</td>
				<td>
					<img id="aff_campaign_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="aff_campaign_id_div"></div>
				</td> 
			</tr>
		</table>
	</form>
	<table style="margin: 5px auto;">
		<tr>
			<td>
				<button onclick="clear_subids(); ">Clear Subids</button>
			</td>
			<td><img id="clear_subids_loading" style="display: none;" src="/202-img/loader-small.gif"/></td>
		</tr>
	</table>           
	<div id="clear_subids" style="width: 500px; margin: 0px auto;"></div>
																					
	<!-- open up the ajax aff network -->
    <script type="text/javascript">
        load_aff_network_id(0);
	</script>
	
	<script type="text/javascript">

	function clear_subids() { 
		$('clear_subids_loading').style.display='inline';
		$('clear_subids').style.display='none';
		new Ajax.Updater('clear_subids', '../ajax/clear_subids.php', 
		{
			parameters: $('clear_subids_form').serialize(true),
			onSuccess: function() { 
				$('clear_subids_loading').style.display='none';
				$('clear_subids').style.display='block';   
			}
		});	
	}


</script>
		
<? template_bottom();
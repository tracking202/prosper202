<? AUTH::require_user(); ?>

<form id="offers_form" class="offers_form" onsubmit="return false;">
	<table class="offers_form_table" cellspacing='0' cellpadding='5'>
		<tr>
			<th>Search:</th>
			<td><input class="query" type="text" name="query" value="<? echo htmlentities($_SESSION['offers202_query']); ?>" ></td>
            
			<td><? include_once('ajax/getNetworks.php'); ?></td>
		
           	 	<td><input class="search" type="submit" onclick="setOffersPref();" value="Search Offers"/></td>
	            <td>
	            	<span class="s-help"><a href="#" onclick="document.getElementById('s-pop1').style.display='';">Need Help?</a></span>
	                	<span class="s-help"><a href="#" onclick="document.getElementById('s-pop2').style.display='';">Extra Search Features</a></span>
	                
	                <div id="s-pop1" style="display:none;">
	                    <div class="s-pop-close"><a href="#" onclick="document.getElementById('s-pop1').style.display='none';">Close</a></div>
	                    
	                    <div class="s-pop-content">
	                        <p style="margin-bottom: 20px;"><strong>What Is Offers202?</strong><br/>
						Offers202 allows you to find offers across various different affiliate networks.  To use it simple type in a search term or leave it blank and hit "Search Offers."  Our service will then grab all of the offers related to your search.  You can sort the columns by clicking on the column header names.  You can also search for offers by each particular affiliate network, simply select the network from the drop-down list to do this. </p>
						
					<p style="margin-bottom: 20px;"><strong>Offers202 API</strong><br/>
					This Offers202 service is using the Tracking202 202 API to pull the information.  There are several different APIs that Offers202 provides that developers can use to build apps on top.  This application with-in-side of Prosper202 is an example of what you can build with the Offers202 API.   If you would like to learn more about our API and become a Tracking202 developer please visit: <a href="http://developers.tracking202.com">developers.tracking202.com</a>.</p>
					
					<p><strong>Offers202 Support</strong><br/>
						 If you need any help with Offers202, please visit our <a href="http://prosper202.com/forum/">support forum</a>.</p>
						
	                	</div>
	                </div>
	                
	                <div id="s-pop2" style="display:none;">
	                    <div class="s-pop-close"><a href="#" onclick="document.getElementById('s-pop2').style.display='none';">Close</a></div>
	                    
	                    <div class="s-pop-content">
	                    		<p style="margin-bottom: 20px;"><strong>Phrase search ("")</strong><br>
						By putting double quotes around a set of words, you are telling Offers202 to consider the exact words in that exact order without any change. Offers202 already uses the order and the fact that the words are together as a very strong signal and will stray from it only for a good reason, so quotes are usually unnecessary. By insisting on phrase search you might be missing good results accidentally. For example, a search for <nobr>[ <span class="code">"Alexander Bell"</span> ]</nobr> (with quotes) will miss the pages that refer to Alexander <em>G.</em> Bell.</p>
						
						<p><strong>The OR operator</strong><br>
						Offers202's default behavior is to consider all the words in a search. If you want to specifically allow <em>either</em> one of several words, you can use the OR operator (note that you have to type 'OR' in ALL CAPS). For example, <nobr>[ <span class="code">San Francisco Giants 2004 OR 2005</span> ]</nobr> will give you results about either one of these years, whereas <nobr>[ <span class="code">San Francisco Giants 2004 2005</span> ]</nobr> (without the OR) will show pages that include both years on the same page. The symbol <strong>|</strong> can be substituted for OR. (The AND operator, by the way, is the default, so it is not needed.)</p>
					</ul>
	                	</div>
	                </div>
	            </td>
			<th><img id="offers_loading" src="/202-img/loader-small.gif" style="display: none;"/></th>
		</tr>
	</table>    
</form>


<div id="m-content"></div>
<script type="text/javascript">getOffers();</script>

<div style="padding: 10px 0px 0px; text-align: center; font-size: 10px;">
	<em>Results Powered By</em>
	<a href="http://offervault.com"><img style="margin-bottom: -10px;" src="/202-img/offervault.png"/></a>
</div>

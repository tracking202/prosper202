<?php

include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php');    

AUTH::require_user();

template_top('Help Resources',NULL,NULL,NULL);  ?>


<div class="row account">
	<div class="col-xs-12">
		<div class="row">

			<div class="col-xs-8">
				<h6>Help Resources</h6>

				<p><small>Here are some places you can find help regarding Tracking202 & Prosper202</small></p>
				
				<p><small><em>Prosper202 Forum:</em> <a href="http://prosper.tracking202.com/forum/" target="_blank">http://prosper.tracking202.com/forum/</a></small></p>
				
				<p><small><em>Prosper202 Documentation:</em> <a href="http://help.tracking202.com" target="_blank">http://help.tracking202.com</a></small></p>

				<p><small><em>Tracking202 Videos:</em> <a href="http://tracking202.com/videos/" target="_blank">http://tracking202.com/videos/</a></small></p>

				<p><small><em>Tracking202 Tutorials:</em> <a href="http://tracking202.com/tutorials/" target="_blank">http://tracking202.com/tutorials/</a></small></p>

				<p><small><em>Tracking202 FAQ:</em> <a href="http://tracking202.com/faq/" target="_blank">http://tracking202.com/faq/</a></small></p>

				<p><small><em>Tracking202 Scripts:</em> <a href="http://prosper.tracking202.com/scripts/" target="_blank">http://prosper.tracking202.com/scripts/</a></small></p>

				<p><small><em>Community Support:</em> <a href="http://support.tracking202.com/" target="_blank">http://support.tracking202.com/</a></small></p>

				<p><small><em>Prosper202 Blog:</em> <a href="http://prosper.tracking202.com/blog/" target="_blank">http://prosper.tracking202.com/blog/</a></small></p>

				<p><small><em>How Subids Work:</em> <a href="http://subids.tracking202.com/" target="_blank">http://subids.tracking202.com/</a></small></p>

				<p><small><em>202 Youtube</em> <a href="http://youtube.com/t202nana" target="_blank">http://youtube.com/t202nana</a></small></p>
			</div>

			<div class="col-xs-4">
				<div id='gsfn_search_widget'>
					<div class="panel panel-default">
					  <div class="panel-heading" style="font-size: 11px;"><a href="http://getsatisfaction.com/tracking202" target="_blank" class="widget_title">People-Powered Customer Service for Tracking202</a></div>
					  <div class="panel-body">
					    <div class='gsfn_content'>
	                        <form accept-charset='utf-8' class="form-horizontal" action='http://getsatisfaction.com/tracking202' id='gsfn_search_form' method='get' onsubmit='gsfn_search(this); return false;'>
		                        <div>
		                        <input name='style' type='hidden' value='' />
		                        <input name='limit' type='hidden' value='10' />
		                        <input name='utm_medium' type='hidden' value='widget_search' />
		                        <input name='utm_source' type='hidden' value='widget_tracking202' />
		                        <input name='callback' type='hidden' value='gsfnResultsCallback' />
		                        <input name='format' type='hidden' value='widget' />
		                        	<div class="form-group">
									    <div class="col-xs-12">
									    	<label style="font-size: 12px;" for="gsfn_search_query" class="control-label">Ask a question, share an idea, or report a problem.</label>
									    	<input type="text" class="form-control input-sm" id="gsfn_search_query" name="query">
										</div>
									</div>

									<div class="form-group">
									    <div class="col-xs-12">
											<button id="continue" class="btn btn-xs btn-info btn-block" type="submit">Search support</button>
										</div>
									</div>
		                        </div>
	                        </form>
                        <div id='gsfn_search_results' style='height: auto; font-size: 12px;'></div>
                        </div>
					  </div>
					</div>
                    <script src="http://getsatisfaction.com/tracking202/widgets/javascripts/4936d8d8e3/widgets.js" type="text/javascript"></script>
                </div>
                
                <div class="panel panel-default">
                <div class="panel-heading" style="font-size: 11px;"><a href="http://getsatisfaction.com/tracking202" target="_blank" class="widget_title">Active customer service discussions in Tracking202</a></div>
				  <div class="panel-body">
					  <div id='gsfn_list_widget'>
					    <div id='gsfn_content'style="font-size: 12px;">Loading...</div>
					  </div>
				  </div>
				</div>
				<script type="text/javascript">
                $(document).ready(function(){
                    $('#gsfn_list_widget a').attr('target', '_blank');
                });
                </script>
                <script src="http://getsatisfaction.com/tracking202/widgets/javascripts/4936d8d8e3/widgets.js" type="text/javascript"></script>
                <script src="http://getsatisfaction.com/tracking202/topics.widget?callback=gsfnTopicsCallback&amp;limit=5&amp;sort=last_active_at" type="text/javascript"></script>
			</div>

		</div>
	</div>
</div>
<?php template_bottom();
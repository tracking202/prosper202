<?php
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

template_top('Help Resources',NULL,NULL,NULL);  ?>


<div class="row account">
	<div class="col-xs-12">
		<div class="row">

			<div class="col-xs-8">
				<h6>Help Resources</h6>

				<p><small>Here are some places you can find help regarding Tracking202 & Prosper202</small></p>
				
				<p><small><em>Tracking202 Tutorials:</em> <a href="http://support.tracking202.com/" target="_blank">http://support.tracking202.com/</a></small></p>
				
				<p><small><em>202 Youtube</em> <a href="https://youtube.com/t202nana" target="_blank">https://youtube.com/t202nana</a></small></p>
				
				<p><small><em>Tracking202 Videos:</em> <a href="http://tracking202.com/videos/" target="_blank">http://tracking202.com/videos/</a></small></p>

				<p><small><em>Prosper202 Blog:</em> <a href="http://prosper.tracking202.com/blog/" target="_blank">http://prosper.tracking202.com/blog/</a></small></p>
				
			</div>

			<div class="col-xs-4">
				
			</div>

		</div>
	</div>
</div>
<?php template_bottom();
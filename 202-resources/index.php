<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

template_top('Prosper202 ClickServer App Store');  ?>

<div class="row home">
  <div class="col-xs-12">
  	<h6>Resources</h6>
	<small>A wide variety of tools &amp; services to help you become a better internet marketer.  This list is updated frequently, check back often for new updates.</small>
  </div>
</div>
  <br/>
	<?php
			//Initiate curl
			$ch = curl_init();
			// Disable SSL verification
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			// Will return the response, if false it print the response
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// Set the url
			curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/feed/resources/');
			// Execute
			$result=curl_exec($ch);
			curl_close($ch);

			$data = json_decode($result, true);

		foreach ($data['deals'] as $deal) { ?>
				<h6><?php echo $deal['title'];?></h6>
				<div class="row">
					<div class="col-xs-2">
						<img src="<?php echo $deal['deal-img'];?>" class="img-rounded img-responsive">
					</div>
					<div class="col-xs-10">
						<small><?php echo $deal['deal-description'];?></small><br></br>
						<a href="<?php echo $deal['deal-url'];?>" target="_blank"><?php echo $deal['deal-coupon'];?></a>
					</div>
				</div>

			<div class="row form_seperator" style="margin-top:15px;">
				<div class="col-xs-12"></div>
			</div>
		<?php } ?>
<?php template_bottom(); ?>
<?php 
include_once(str_repeat("../", 1).'202-config/connect.php');
 
AUTH::require_user();

$strProtocol = stripos($_SERVER['SERVER_PROTOCOL'],'https') === true ? 'https://' : 'http://';

template_top();  ?>

<div class="row home">
  <div class="col-xs-7">
  	<div class="row">
  	<?php if($_SESSION['user_pref_ad_settings']!='hide_all'){?>
	  <div class="col-xs-12">
	  	<h6 class="h6-home">Special Offers <span class="glyphicon glyphicon-tags home-icons"></span></h6>
	  	<iframe class="advertise" src="<?php echo TRACKING202_ADS_URL; ?>/prosper202-home/?t202aid=<?php echo $_SESSION['user_cirrus_link']; ?>" scrolling="no" frameborder="0"></iframe>
		
	  </div>
      <?php }?>
	  <div class="col-xs-12" style="min-height: 306px;">
		<h6 class="h6-home">Tracking202 News <span class="glyphicon glyphicon-comment home-icons"></span></h6>
			<div id="tracking202_posts"><img src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display: block;"/></div>
	  </div>

	</div>
  </div>

  <div class="col-xs-5">
  <div class="row">
  	<div class="col-xs-12 apps">
  		<h6 class="h6-home">My Applications <span class="glyphicon glyphicon-folder-open home-icons"></span></h6>
  			<div class="row">
  				<div class="col-xs-2">
  					<a href="<?php echo get_absolute_url();?>tracking202/"><img src="<?php echo get_absolute_url();?>202-img/new/icons/shield.svg"></a>
  				</div>
  				<div class="col-xs-10">
  					<a href="<?php echo get_absolute_url();?>tracking202/">Prosper202 ClickServer</a><br/><span>Advanced conversion tracking & optimization software.</span>
  				</div>
  			</div>
  			 <div class="row app-row">
  			<div class="col-xs-2">
  				<a href="<?php echo get_absolute_url();?>202-tv/"><img src="<?php echo get_absolute_url();?>202-img/new/icons/video.svg"></a>
  			</div>
  			<div class="col-xs-10">
  				<a href="<?php echo get_absolute_url();?>202-tv/">TV202</a><br/><span>Exclusive Marketing Interviews & Tutorials.</span>
  			</div>
  		</div>
  			<div class="row app-row">
  				<div class="col-xs-2">
  					<a href="<?php echo get_absolute_url();?>202-Mobile"><img src="<?php echo get_absolute_url();?>202-img/new/icons/responsive.svg" style="margin-left: 8px;"></a>
  				</div>
  				<div class="col-xs-10">
  					<a href="<?php echo get_absolute_url();?>202-Mobile">Mobile202</a><br/><span>View your stats with mobile version of Prosper202</span>
  				</div>
  			</div>
  			<div class="row app-row">
  				<div class="col-xs-2">
  					<a href="<?php echo get_absolute_url();?>202-resources/"><img src="<?php echo get_absolute_url();?>202-img/new/icons/basket.svg"></a>
  				</div>
  				<div class="col-xs-10">
  					<a href="<?php echo get_absolute_url();?>202-resources/">Resources202</a><br/><span>Discover more applications to help you sell.</span>
  				</div>
  			</div>

  	</div>
  </div>

  <div class="row">
  	<div class="col-xs-12 apps">
  		<h6 class="h6-home">Extra Resources <span class="glyphicon glyphicon-info-sign home-icons"></span></h6>

  		<div class="row">
  			<div class="col-xs-2">
  				<a href="http://blog.tracking202.com/" target="_blank"><img src="<?php echo get_absolute_url();?>202-img/new/icons/news.svg" style="width: 48px;"></a>
  			</div>
  			<div class="col-xs-10">
  				<a href="http://blog.tracking202.com/" target="_blank">Blog</a> - <a href="https://twitter.tracking202.com/" target="_blank">Twitter</a> - <a href="http://newsletter.tracking202.com" target="_blank">Newsletter</a><br/><span>Connect with us to get the latest updates.</span>
  			</div>
  		</div>

  		<div class="row app-row">
  			<div class="col-xs-2">
  				<a href="http://support.tracking202.com/" target="_blank"><img src="<?php echo get_absolute_url();?>202-img/new/icons/support.svg"></a>
  			</div>
  			<div class="col-xs-10">
  				<a href="http://support.tracking202.com/" target="_blank">Community Support</a><br/><span>Talk with other users, and get help.</span>
  			</div>
  		</div>

  		<div class="row app-row">
  			<div class="col-xs-2">
  				<a href="http://developers.tracking202.com" target="_blank"><img src="<?php echo get_absolute_url();?>202-img/new/icons/settings.svg"></a>
  			</div>
  			<div class="col-xs-10">
  				<a href="http://developers.tracking202.com" target="_blank">Developers</a><br/><span>Do cool things with the Tracking202 APIs.</span>
  			</div>
  		</div>

  		<div class="row app-row">
  			<div class="col-xs-2">
  				<a href="http://meetup.tracking202.com" target="_blank"><img src="<?php echo get_absolute_url();?>202-img/new/icons/shirt.svg"></a>
  			</div>
  			<div class="col-xs-10">
  				<a href="http://meetup.tracking202.com" target="_blank">Meetup202</a><br/><span>Marketing Meetup Groups around the World.</span>
  			</div>
  		</div>



  	</div>

  	<div class="col-xs-12">
  		<h6 class="h6-home">Partners <span class="glyphicon glyphicon-thumbs-up home-icons"></span></h6>
  		<div id="tracking202_sponsors"><img src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display: block;"/></div>
  	</div>
  </div>
  </div>
</div>
<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo base64_encode($strProtocol . getTrackingDomain() . get_absolute_url());?>">

<?php template_bottom(); ?>
<?php include_once(dirname( __FILE__ ) . '/202-config/connect.php'); ?>

<?php info_top(); ?>
<div class="row">
<div class="main col-xs-6">
	<center><img src="202-img/prosper202.png"></center>
	<h6 style="text-align: center;">The page you requested was not found.</h6>
	<center><span class="infotext">You may have clicked an expired link or mistyped the web address you were looking for.</span></center>
	
		<ul>
		  <li><a href="<?php echo get_absolute_url();?>">Return home</a></li>
		  <li><a href="javascript:history.back();">Go back to the previous page</a></li>
		</ul>
</div>
</div>
<?php info_bottom(); ?>
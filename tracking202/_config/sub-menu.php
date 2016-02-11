<div class="row">
  <div class="col-xs-12" id="sub-menu">
    <ul class="breadcrumb">
            <?php if ($navigation[2] == 'setup') { ?>
              <li <?php if ($navigation[3] == 'ppc_accounts.php' or !$navigation[3]) { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/ppc_accounts.php">#1 Traffic Sources</a></li>
              <li <?php if ($navigation[3] == 'aff_networks.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/aff_networks.php">#2 Categories</a></li>
              <li <?php if ($navigation[3] == 'aff_campaigns.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/aff_campaigns.php">#3 Campaigns</a></li>
              <li <?php if ($navigation[3] == 'landing_pages.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/landing_pages.php">#4 Landing Pages</a></li>
              <li <?php if ($navigation[3] == 'text_ads.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/text_ads.php">#5 Text Ads</a></li>
              <li <?php if ($navigation[3] == 'rotator.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/rotator.php">#6 Redirector</a></li> 
              <li <?php switch($navigation[3]) { case "get_landing_code.php":  case "get_simple_landing_code.php":  case "get_adv_landing_code.php": echo 'class="active"'; break; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/get_landing_code.php">#7 Get LP Code</a></li> 
              <li <?php if ($navigation[3] == 'get_trackers.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/get_trackers.php">#8 Get Links</a></li> 
              <li <?php if ($navigation[3] == 'get_postback.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/setup/get_postback.php">#9 Get Postback/Pixel</a></li> 
              
            <?php } ?>

            <?php if (($navigation[1] == 'account' and !$navigation[2]) or ($navigation[2] == 'overview')) { ?>
              <li <?php if ($navigation[3] == 'campaign.php' or !$navigation[3]) { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/overview">Campaign Overview</a></li>
              <li <?php if ($navigation[3] == 'breakdown.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/overview/breakdown.php">Breakdown Analysis</a></li>
              <li <?php if ($navigation[3] == 'day-parting.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/overview/day-parting.php">Day Parting</a></li>
              <li <?php if ($navigation[3] == 'week-parting.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/overview/week-parting.php">Week Parting</a></li> 
              <li <?php if ($navigation[3] == 'group-overview.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/overview/group-overview.php">Group Overview</a></li>
          <?php } ?>
          
          <?php if ($navigation[2] == 'analyze') { ?>
              <li <?php if ($navigation[3] == 'keywords.php' or !$navigation[3]) { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/keywords.php">Keywords</a></li>
              <li <?php if ($navigation[3] == 'text_ads.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/text_ads.php">Text Ads</a></li>
              <li <?php if ($navigation[3] == 'referers.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/referers.php">Referers</a></li>
              <li <?php if ($navigation[3] == 'ips.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/ips.php">IPs</a></li>
              <li <?php if ($navigation[3] == 'countries.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/countries.php">Countries</a></li>
              <li <?php if ($navigation[3] == 'regions.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/regions.php">Regions</a></li>
              <li <?php if ($navigation[3] == 'cities.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/cities.php">Cities</a></li>
              <li <?php if ($navigation[3] == 'isp.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/isp.php">ISP/Carrier</a></li>
              <li <?php if ($navigation[3] == 'landing_pages.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/landing_pages.php">Landing Pages</a></li>
              <li <?php if ($navigation[3] == 'devices.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/devices.php">Devices</a></li>
              <li <?php if ($navigation[3] == 'browsers.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/browsers.php">Browsers</a></li>
              <li <?php if ($navigation[3] == 'platforms.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/platforms.php">Platforms</a></li>
              <li <?php if ($navigation[3] == 'variables.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/variables.php">Custom Variables</a></li>
          <?php } ?>
          
          
          <?php if ($navigation[2] == 'update') { ?>
              <li <?php if ($navigation[3] == 'subids.php' or !$navigation[3]) { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/update/subids.php">Update Subids</a></li>
              <li <?php if ($navigation[3] == 'cpc.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/update/cpc.php">Update CPC</a></li>
              <li <?php if ($navigation[3] == 'clear-subids.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/update/clear-subids.php">Reset Campaign Subids</a></li>
              <?php if($userObj->hasPermission("delete_individual_subids")) { ?><li <?php if ($navigation[3] == 'delete-subids.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/update/delete-subids.php">Delete Subids</a></li><?php } ?>
              <li <?php if ($navigation[3] == 'upload.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/update/upload.php">Upload Revenue Reports</span></a></li>
          <?php } ?>
    </ul>
  </div>
</div>  
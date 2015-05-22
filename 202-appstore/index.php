<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top('Prosper202 ClickServer App Store',NULL,NULL,NULL); 
	
?>

<div class="row demo-tiles upgradeToProContainer">
        <div class="upgradeToProOverlay" style="height:505px; width: 981px; margin-top:-15px;">
          <div class="upgradeToProOverlayBackground"></div>
          <a href="http://click202.com/tracking202/redirect/dl.php?t202id=8151295&t202kw=appstore" target="_blank" class="btn btn-lg btn-p202 upgradeToProOverlayButton" style="margin-top: 230px; margin-left:344px;" id="upgradeAppStore">This is a Prosper202 Pro Feature: Upgrade Now To Access!</a>
        </div>
        <div class="col-xs-12">
          <h4><img src="/202-img/new/icons/building.svg" alt="ribbon" class="tile-hot-ribbon"> Prosper202 App Store - 1-Click Install Apps &amp; Services</h4>
        </div>		
        <div class="col-xs-3">
          <div class="tile">
            <img src="/202-img/new/icons/rocket.svg" class="tile-image big-illustration">
            <h3 class="tile-title">Prosper202 Pro: DataEngine</h3>
            <p></p>
            <a class="btn btn-primary btn-large btn-block" href="">Installed <span class="fui-check"></span><br>$210 A Month</a>
            </div>
        </div>
				
        <div class="col-xs-3">
          <div class="tile">
            <img src="/202-img/new/icons/support.svg" class="tile-image big-illustration">
            <h3 class="tile-title">Prosper202 Pro: Priority Support</h3>
            <p></p>
            <a class="btn btn-primary btn-large btn-block" href="">Installed <span class="fui-check"></span><br>$19.00 A Month</a>
            </div>
        </div>
				
        <div class="col-xs-3">
          <div class="tile">
            <img src="/202-img/new/icons/graph.svg" class="tile-image big-illustration">
            <h3 class="tile-title">Prosper202 Pro: Adwords Sync</h3>
            <p></p>
            <a class="btn btn-primary btn-large btn-block" href="">Installed <span class="fui-check"></span><br>$175 A Month</a>
            </div>
        </div>
				
        <div class="col-xs-3">
          <div class="tile">
            <img src="/202-img/new/icons/goal.svg" class="tile-image big-illustration">
            <h3 class="tile-title">Prosper202 Pro: Retargeting Xtreme</h3>
            <p></p>
              <a class="btn btn-warning btn-large btn-block" href="">Coming Soon... <span class="fui-time"></span><br>$175 A Month</a>
          </div>
        </div>
		</div>		
<?php template_bottom(); ?>
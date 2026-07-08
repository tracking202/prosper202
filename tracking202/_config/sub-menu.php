<?php if ($navigation[2] == 'setup') { ?>
<!-- Setup Navigation - Button Style -->
<style>
.setup-nav-container {
    margin-bottom: 20px;
}

.setup-nav-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.setup-nav-item {
    margin: 0;
}

.setup-nav-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 14px;
    background: #fff;
    border: 1px solid #e7e8ea;
    border-radius: 8px;
    color: #374151;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
    white-space: nowrap;
    text-align: center;
}

.setup-nav-link:hover {
    background: #fafbfc;
    border-color: #c9cdd3;
    color: #1f2328;
    text-decoration: none;
    transform: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.setup-nav-link:focus {
    outline: none;
    border-color: #2f6fdd;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.setup-nav-link.active {
    background: #2f6fdd;
    border-color: transparent;
    color: #fff;
    box-shadow: 0 2px 8px rgba(47,111,221,0.25);
}

.setup-nav-link.active:hover {
    background: #2f6fdd;
    color: #fff;
    transform: none;
    box-shadow: 0 4px 12px rgba(47,111,221,0.35);
}

.setup-nav-link .glyphicon {
    font-size: 12px;
    opacity: 0.85;
    /* Bootstrap's base .glyphicon adds position:relative; top:1px, which pushes
       every nav icon 1px below the label's optical center. Cancel it and tighten
       the line box so the icons sit centered on the text. */
    top: 0;
    line-height: 1;
}

/* The Glyphicons "console" glyph (Get LP Code) is drawn ~1px lower inside its
   em-box than the other icons, so it looks dropped relative to the row. Nudge it
   up so the icon row optically aligns. */
.setup-nav-link .glyphicon-console {
    top: -1px;
}

.setup-nav-link.active .glyphicon {
    opacity: 1;
}

.setup-nav-text {
    line-height: 1.2;
}

/* ===========================================
   SETUP PAGE UI COMPONENT LAYER
   One place to control shared setup look & feel
   =========================================== */

.main .setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: #2f6fdd;
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(47, 111, 221, 0.2);
}

.main .setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
}

.main .setup-page-header__icon .glyphicon {
    font-size: 24px;
    color: #fff;
}

.main .setup-page-header__text {
    flex: 1;
}

.main .setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 600;
    color: #fff;
}

.main .setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
}

.main .setup-side-panel.panel.panel-default {
    border: 1px solid #e7e8ea;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.main .setup-side-panel.panel.panel-default > .panel-heading {
    background: #fafbfc;
    border-bottom: 1px solid #e7e8ea;
    color: #1f2328;
    font-weight: 600;
    padding: 16px 20px;
}

.main .setup-side-panel.panel.panel-default > .panel-body {
    padding: 20px;
}

.main .setup-side-panel.lp-panel {
    border: 1px solid #e7e8ea;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.main .setup-side-panel.lp-panel .lp-panel-body {
    padding: 20px;
}

.main .setup-side-panel.well {
    border: 1px solid #e7e8ea;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    padding: 16px;
}

.main .setup-side-panel .form-control {
    border: 1px solid #c9cdd3;
    border-radius: 8px;
    box-shadow: none;
    -webkit-box-shadow: none;
    transition: all 0.2s ease;
}

.main .setup-side-panel .form-control:focus {
    border-color: #2f6fdd;
    box-shadow: 0 0 0 3px rgba(47, 111, 221, 0.1);
    -webkit-box-shadow: 0 0 0 3px rgba(47, 111, 221, 0.1);
}

.main .setup-side-panel .fuzzy-search,
.main .setup-side-panel .search {
    min-height: 36px;
    padding: 8px 12px;
    height: 36px !important;
}

.main .form_seperator {
    border-bottom: 2px solid #e7e8ea;
    margin: 20px 0;
}

.main .setup-side-panel .list,
.main .setup-side-panel .setup-list,
.main .setup-side-panel ul {
    list-style: none;
    margin: 12px 0 0 0;
    padding: 0;
}

.main .setup-side-panel .list > li,
.main .setup-side-panel .setup-list > li,
.main .setup-side-panel ul > li {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 11px 12px;
    margin-bottom: 8px;
    border: 1px solid #e7e8ea;
    border-radius: 8px;
    background: #fff;
    transition: all 0.2s ease;
}

.main .setup-side-panel .list > li:hover,
.main .setup-side-panel .setup-list > li:hover,
.main .setup-side-panel ul > li:hover {
    border-color: #bcd4f6;
    background: #fafbfc;
}

.main .setup-side-panel ul ul {
    margin-top: 8px;
    margin-left: 12px;
    padding-left: 10px;
    border-left: 2px solid #e7e8ea;
}

.main .setup-side-panel ul ul > li {
    background: #fafbfc;
    border-color: #e7e8ea;
    margin-bottom: 6px;
    padding: 9px 10px;
}

.main .setup-side-panel ul ul ul > li {
    background: #ffffff;
}

.main .setup-side-panel .filter_network_name,
.main .setup-side-panel .filter_campaign_name,
.main .setup-side-panel .filter_adv_lp_name,
.main .setup-side-panel .filter_simple_lp_name,
.main .setup-side-panel .filter_text_ad_name,
.main .setup-side-panel .filter_rotator_name,
.main .setup-side-panel .filter_rule_name,
.main .setup-side-panel .filter_tracker_display_name,
.main .setup-side-panel .filter_source_name,
.main .setup-side-panel .filter_account_name,
.main .setup-side-panel .filter_model_name {
    font-weight: 600;
    color: #32383f;
    flex: 1;
    min-width: 120px;
}

.main .setup-side-panel .filter_tracker_meta {
    font-size: 12px;
    color: #6b7280;
    flex: 1 1 100%;
}

.main .setup-side-panel .list-action {
    color: #2f6fdd;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 9px;
    border: 1px solid transparent;
    border-radius: 6px;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.main .setup-side-panel .list-action:hover {
    color: #2861c4;
    background: #eaf2fc;
    border-color: #bcd4f6;
    text-decoration: none;
}

.main .setup-side-panel .list-action-danger {
    color: #c9372c;
}

.main .setup-side-panel .list-action-danger:hover {
    color: #b02a20;
    background: #fdecec;
    border-color: #fdecec;
}

.main .setup-side-panel .action-edit,
.main .setup-side-panel .action-remove,
.main .setup-side-panel .action-default {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 9px;
    border: 1px solid transparent;
    border-radius: 6px;
    text-decoration: none;
    background: #fff;
    line-height: 1.4;
}

.main .setup-side-panel .action-edit {
    color: #2f6fdd;
}

.main .setup-side-panel .action-edit:hover {
    color: #2861c4;
    background: #eaf2fc;
    border-color: #bcd4f6;
}

.main .setup-side-panel .action-remove {
    color: #c9372c;
}

.main .setup-side-panel .action-remove:hover {
    color: #b02a20;
    background: #fdecec;
    border-color: #fdecec;
}

.main .setup-side-panel .action-default {
    color: #374151;
    background: #fafbfc;
    border-color: #e7e8ea;
}

.main .setup-side-panel .action-default:hover {
    color: #32383f;
    background: #eaf2fc;
}

.main .setup-side-panel .list-meta {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    background: #f0f1f2;
    border: 1px solid #e7e8ea;
    border-radius: 999px;
    padding: 2px 8px;
}

.main .setup-side-panel .empty-state {
    text-align: center;
    color: #6b7280;
    border: 1px dashed #c9cdd3;
    border-radius: 10px;
    padding: 24px 16px;
    background: #fafbfc;
}

/* Responsive - Tablet */
@media (max-width: 992px) {
    .setup-nav-link {
        padding: 8px 10px;
        font-size: 11px;
    }

    .setup-nav-link .glyphicon {
        font-size: 12px;
    }
}

/* Responsive - Mobile */
@media (max-width: 767px) {
    .setup-nav-container {
        margin-bottom: 16px;
    }

    .setup-nav-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }

    .setup-nav-link {
        padding: 10px 8px;
        font-size: 11px;
        flex-direction: column;
        gap: 4px;
    }

    .setup-nav-link .glyphicon {
        font-size: 14px;
    }

    .main .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
    }

    .main .setup-page-header__icon {
        width: 48px;
        height: 48px;
    }

    .main .setup-page-header__icon .glyphicon {
        font-size: 20px;
    }

    .main .setup-page-header__title {
        font-size: 20px;
    }

    .main .setup-page-header__subtitle {
        font-size: 13px;
    }

    .main .setup-side-panel .list > li,
    .main .setup-side-panel .setup-list > li,
    .main .setup-side-panel ul > li {
        flex-direction: column;
        align-items: flex-start;
    }

    .main .setup-side-panel .list-action {
        align-self: flex-start;
    }
}

/* Responsive - Small Mobile */
@media (max-width: 480px) {
    .setup-nav-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
    }

    .setup-nav-link {
        padding: 10px 6px;
        font-size: 10px;
        border-radius: 6px;
    }

    .setup-nav-link .glyphicon {
        font-size: 13px;
    }

    .setup-nav-text {
        font-size: 10px;
    }

    .main .setup-page-header {
        padding: 16px 12px;
    }

    .main .setup-page-header__title {
        font-size: 18px;
    }

    .main .setup-page-header__subtitle {
        font-size: 12px;
    }
}
</style>

<div class="setup-nav-container">
    <div class="row">
        <div class="col-xs-12">
            <ul class="setup-nav-grid">
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/ppc_accounts.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'ppc_accounts.php' || !$navigation[3]) ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-globe"></span>
                        <span class="setup-nav-text">Traffic Sources</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_networks.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'aff_networks.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-th-large"></span>
                        <span class="setup-nav-text">Categories</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_campaigns.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'aff_campaigns.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-link"></span>
                        <span class="setup-nav-text">Campaigns</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/landing_pages.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'landing_pages.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-file"></span>
                        <span class="setup-nav-text">Landing Pages</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/text_ads.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'text_ads.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-font"></span>
                        <span class="setup-nav-text">Text Ads</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/rotator.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'rotator.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-refresh"></span>
                        <span class="setup-nav-text">Redirector</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'attribution_models.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-stats"></span>
                        <span class="setup-nav-text">Attribution</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_landing_code.php"
                       class="setup-nav-link <?php echo (in_array($navigation[3], ['get_landing_code.php', 'get_simple_landing_code.php', 'get_adv_landing_code.php'])) ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-console"></span>
                        <span class="setup-nav-text">Get LP Code</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_trackers.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'get_trackers.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-link"></span>
                        <span class="setup-nav-text">Get Links</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_postback.php"
                       class="setup-nav-link <?php echo ($navigation[3] == 'get_postback.php') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-transfer"></span>
                        <span class="setup-nav-text">Postback/Pixel</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
<?php } else { ?>
<!-- Other Sections - Original Breadcrumb Style -->
<div class="row">
  <div class="col-xs-12" id="sub-menu">
    <ul class="breadcrumb">
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
              <li <?php if ($navigation[3] == 'ltv.php') { echo 'class="active"'; } ?>><a href="<?php echo get_absolute_url();?>tracking202/analyze/ltv.php">Customer LTV</a></li>
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
<?php } ?>

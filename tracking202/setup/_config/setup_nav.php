<?php
declare(strict_types=1);

// Get current page name for active state
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>

<div class="row" style="margin-bottom: 20px;">
    <div class="col-xs-12">
        <ul class="nav nav-pills setup-navigation">
            <li <?php echo ($currentPage === 'ppc_accounts') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/ppc_accounts.php">
                    <span class="glyphicon glyphicon-globe"></span>
                    <span class="nav-text">Traffic Sources</span>
                </a>
            </li>
            <li <?php echo ($currentPage === 'aff_campaigns') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_campaigns.php">
                    <span class="glyphicon glyphicon-link"></span>
                    <span class="nav-text">Campaigns</span>
                </a>
            </li>
            <li <?php echo ($currentPage === 'landing_pages') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/landing_pages.php">
                    <span class="glyphicon glyphicon-file"></span>
                    <span class="nav-text">Landing Pages</span>
                </a>
            </li>
            <li <?php echo ($currentPage === 'attribution_models') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php">
                    <span class="glyphicon glyphicon-stats"></span>
                    <span class="nav-text">Attribution Models</span>
                </a>
            </li>
            <li <?php echo ($currentPage === 'text_ads') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/text_ads.php">
                    <span class="glyphicon glyphicon-font"></span>
                    <span class="nav-text">Text Ads</span>
                </a>
            </li>
            <li <?php echo ($currentPage === 'rotator') ? 'class="active"' : ''; ?>>
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/rotator.php">
                    <span class="glyphicon glyphicon-refresh"></span>
                    <span class="nav-text">Rotator</span>
                </a>
            </li>
        </ul>
    </div>
</div>

<style>
/* Setup Navigation Mobile Responsive Styles */
.setup-navigation {
    margin-bottom: 0;
}

.setup-navigation > li > a {
    padding: 10px 15px;
    border-radius: 4px;
    margin-right: 5px;
    margin-bottom: 8px;
    transition: all 0.2s ease;
    text-align: center;
    white-space: nowrap;
}

.setup-navigation > li > a .nav-text {
    margin-left: 6px;
}

@media (max-width: 767px) {
    .setup-navigation {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        margin-bottom: 15px;
    }
    
    .setup-navigation > li {
        flex: 0 0 auto;
        margin-bottom: 8px;
        margin-right: 5px;
    }
    
    .setup-navigation > li > a {
        padding: 8px 12px;
        font-size: 13px;
        margin-right: 0;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: auto;
    }
    
    .setup-navigation > li > a .nav-text {
        margin-left: 4px;
        font-weight: 500;
    }
    
    .setup-navigation > li > a .glyphicon {
        font-size: 12px;
    }
}

@media (max-width: 480px) {
    .setup-navigation {
        flex-direction: column;
        align-items: stretch;
    }
    
    .setup-navigation > li {
        flex: none;
        width: 100%;
        margin-right: 0;
        margin-bottom: 6px;
    }
    
    .setup-navigation > li > a {
        width: 100%;
        padding: 12px 15px;
        font-size: 14px;
        justify-content: flex-start;
        text-align: left;
    }
    
    .setup-navigation > li > a .nav-text {
        margin-left: 8px;
    }
    
    .setup-navigation > li > a .glyphicon {
        font-size: 14px;
    }
}

/* Landscape phones */
@media (max-width: 767px) and (orientation: landscape) {
    .setup-navigation {
        flex-direction: row;
        justify-content: center;
    }
    
    .setup-navigation > li {
        width: auto;
        margin-right: 4px;
    }
    
    .setup-navigation > li > a {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    .setup-navigation > li > a .nav-text {
        margin-left: 4px;
    }
}
</style>
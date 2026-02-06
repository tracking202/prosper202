<?php
declare(strict_types=1);

// Get current page name for active state
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Check for related pages (e.g., get_landing_code variants)
$lpCodePages = ['get_landing_code', 'get_simple_landing_code', 'get_adv_landing_code'];
$isLpCodePage = in_array($currentPage, $lpCodePages);
?>

<style>
/* ===========================================
   SETUP NAVIGATION - Design System
   =========================================== */

/* Hide the old breadcrumb sub-menu on setup pages */
#sub-menu {
    display: none !important;
}

.setup-nav-container {
    margin-bottom: 28px;
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
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    color: #475569;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
    white-space: nowrap;
    text-align: center;
}

.setup-nav-link:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #1e293b;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.setup-nav-link:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.setup-nav-link.active {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,123,255,0.25);
}

.setup-nav-link.active:hover {
    background: linear-gradient(135deg, #0069d9 0%, #004494 100%);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.35);
}

.setup-nav-link .glyphicon {
    font-size: 12px;
    opacity: 0.85;
}

.setup-nav-link.active .glyphicon {
    opacity: 1;
}

.setup-nav-text {
    line-height: 1.2;
}

/* Divider between main setup and utility links */
.setup-nav-divider {
    display: flex;
    align-items: center;
    padding: 0 4px;
    color: #cbd5e1;
    font-size: 18px;
    line-height: 1;
}
.setup-nav-divider::before {
    content: "|";
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
        margin-bottom: 20px;
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

    .setup-nav-divider {
        display: none;
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
}
</style>

<div class="setup-nav-container">
    <div class="row">
        <div class="col-xs-12">
            <ul class="setup-nav-grid">
                <!-- Primary Setup Items -->
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/ppc_accounts.php"
                       class="setup-nav-link <?php echo ($currentPage === 'ppc_accounts' || !$currentPage) ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-globe"></span>
                        <span class="setup-nav-text">Traffic Sources</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_networks.php"
                       class="setup-nav-link <?php echo ($currentPage === 'aff_networks') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-th-large"></span>
                        <span class="setup-nav-text">Categories</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_campaigns.php"
                       class="setup-nav-link <?php echo ($currentPage === 'aff_campaigns') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-link"></span>
                        <span class="setup-nav-text">Campaigns</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/landing_pages.php"
                       class="setup-nav-link <?php echo ($currentPage === 'landing_pages') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-file"></span>
                        <span class="setup-nav-text">Landing Pages</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/text_ads.php"
                       class="setup-nav-link <?php echo ($currentPage === 'text_ads') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-font"></span>
                        <span class="setup-nav-text">Text Ads</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/rotator.php"
                       class="setup-nav-link <?php echo ($currentPage === 'rotator') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-refresh"></span>
                        <span class="setup-nav-text">Redirector</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php"
                       class="setup-nav-link <?php echo ($currentPage === 'attribution_models') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-stats"></span>
                        <span class="setup-nav-text">Attribution</span>
                    </a>
                </li>

                <!-- Divider -->
                <li class="setup-nav-divider" aria-hidden="true"></li>

                <!-- Utility Items -->
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_landing_code.php"
                       class="setup-nav-link <?php echo $isLpCodePage ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-console"></span>
                        <span class="setup-nav-text">Get LP Code</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_trackers.php"
                       class="setup-nav-link <?php echo ($currentPage === 'get_trackers') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-link"></span>
                        <span class="setup-nav-text">Get Links</span>
                    </a>
                </li>
                <li class="setup-nav-item">
                    <a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_postback.php"
                       class="setup-nav-link <?php echo ($currentPage === 'get_postback') ? 'active' : ''; ?>">
                        <span class="glyphicon glyphicon-transfer"></span>
                        <span class="setup-nav-text">Postback/Pixel</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

template_top('Get Landing Page Code');  ?>

<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-console"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Landing Page Code</h1>
				<p class="setup-page-header__subtitle">Add tracking code to your landing pages to track visitor journeys</p>
			</div>
		</div>
	</div>
</div>

<!-- Tab Navigation -->
<div class="row" style="margin-bottom: 24px;">
	<div class="col-xs-12">
		<div class="lp-tabs">
			<a href="<?php echo get_absolute_url();?>tracking202/setup/get_simple_landing_code.php" class="lp-tab active">
				<div class="lp-tab-icon">
					<span class="glyphicon glyphicon-file"></span>
				</div>
				<span class="lp-tab-text">
					<strong>Simple</strong>
					<small>Single campaign per page</small>
				</span>
			</a>
			<a href="<?php echo get_absolute_url();?>tracking202/setup/get_adv_landing_code.php" class="lp-tab">
				<div class="lp-tab-icon">
					<span class="glyphicon glyphicon-list-alt"></span>
				</div>
				<span class="lp-tab-text">
					<strong>Advanced</strong>
					<small>Multiple campaigns per page</small>
				</span>
			</a>
		</div>
	</div>
</div>

<div class="row">
	<div class="col-xs-12 col-md-6">
		<div class="lp-panel">
			<div class="lp-panel-header">
				<span class="glyphicon glyphicon-cog"></span>
				Configure Your Landing Page
			</div>
			<div class="lp-panel-body">
				<form id="tracking_form" method="post" action="" role="form">
					<div class="lp-form-group">
					    <label for="aff_network_id" class="lp-label">Category</label>
				        <img id="aff_network_id_div_loading" class="loading" src="/202-img/loader-small.gif"/>
	                	<div id="aff_network_id_div"></div>
					</div>

					<div class="lp-form-group">
						<label for="aff_campaign_id" class="lp-label">Campaign</label>
					    <img id="aff_campaign_id_div_loading" class="loading" src="/202-img/loader-small.gif" style="display: none;"/>
				        <div id="aff_campaign_id_div">
				            <select class="form-control lp-select" id="aff_campaign_id" disabled="">
				                <option>-- Select a category first --</option>
				            </select>
				        </div>
					</div>

					<div class="lp-form-group">
					    <label for="method_of_promotion" class="lp-label">Promotion Method</label>
				        <select class="form-control lp-select" id="method_of_promotion" name="method_of_promotion">
				            <option value="landingpage" selected="">Landing Page</option>
				        </select>
					</div>

					<div class="lp-form-group">
				        <label for="landing_page_id" class="lp-label">Landing Page</label>
			        	<img id="landing_page_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
						<div id="landing_page_div">
							<select class="form-control lp-select" id="landing_page_id" disabled="">
				                <option>-- Select a campaign first --</option>
				            </select>
						</div>
				    </div>

				    <div class="lp-form-group" style="margin-top: 24px; margin-bottom: 0;">
						<button type="button" id="generate-tracking-link-simple" class="lp-btn-primary">
							<span class="glyphicon glyphicon-flash"></span>
							Generate Tracking Code
						</button>
					</div>
				</form>
			</div>
		</div>

		<div class="lp-help-card">
			<div class="lp-help-icon">
				<span class="glyphicon glyphicon-question-sign"></span>
			</div>
			<div class="lp-help-content">
				<strong>When to use Simple?</strong>
				<p>Use Simple mode when your landing page promotes a single campaign. The visitor flow is: Ad → Landing Page → Offer.</p>
			</div>
		</div>
	</div>

	<div class="col-xs-12 col-md-6">
		<div class="lp-panel lp-panel-output setup-side-panel">
			<div class="lp-panel-header">
				<span class="glyphicon glyphicon-code"></span>
				Your Tracking Code
			</div>
			<div class="lp-panel-body" id="tracking-links">
				<div class="lp-empty-state">
					<div class="lp-empty-icon">
						<span class="glyphicon glyphicon-console"></span>
					</div>
					<h4>Ready to Generate</h4>
					<p>Select your category, campaign, and landing page, then click "Generate Tracking Code"</p>
				</div>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
	$(document).ready(function() {
	   	load_aff_network_id(0);
	});
</script>

<style>
/* ============================================
   LANDING PAGE CODE - DESIGN SYSTEM
   ============================================ */

/* Header */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: #2f6fdd;
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(47, 111, 221, 0.2);
}
.setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
}
.setup-page-header__icon .glyphicon {
    font-size: 28px;
}
.setup-page-header__text {
    flex: 1;
}
.setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 600;
    color: #fff;
}
.setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.85);
}

/* Tab Navigation */
.lp-tabs {
    display: flex;
    gap: 12px;
}
.lp-tab {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 24px;
    background: #fff;
    border: 2px solid #e7e8ea;
    border-radius: 12px;
    text-decoration: none;
    color: #6b7280;
    transition: all 0.2s ease;
    flex: 1;
}
.lp-tab:hover {
    border-color: #2f6fdd;
    color: #2f6fdd;
    text-decoration: none;
    transform: none;
    box-shadow: 0 4px 12px rgba(47, 111, 221, 0.1);
}
.lp-tab.active {
    background: #2f6fdd;
    border-color: transparent;
    color: #fff;
    box-shadow: 0 4px 15px rgba(47, 111, 221, 0.3);
}
.lp-tab-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: rgba(47, 111, 221, 0.1);
    border-radius: 10px;
    flex-shrink: 0;
}
.lp-tab.active .lp-tab-icon {
    background: rgba(255, 255, 255, 0.2);
}
.lp-tab-icon .glyphicon {
    font-size: 20px;
}
.lp-tab-text {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
}
.lp-tab-text strong {
    font-size: 16px;
    font-weight: 600;
}
.lp-tab-text small {
    font-size: 12px;
    opacity: 0.75;
    margin-top: 2px;
}

/* Panels */
.lp-panel {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e7e8ea;
    overflow: hidden;
    margin-bottom: 20px;
}
.lp-panel-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    background: #fafbfc;
    border-bottom: 1px solid #e7e8ea;
    font-weight: 600;
    font-size: 15px;
    color: #1f2328;
}
.lp-panel-header .glyphicon {
    color: #2f6fdd;
    font-size: 16px;
}
.lp-panel-body {
    padding: 24px;
}
.lp-panel-output .lp-panel-header {
    background: #e7f5ec;
    border-bottom-color: #e7f5ec;
}
.lp-panel-output .lp-panel-header .glyphicon {
    color: #217a41;
}

/* Form Styling */
.lp-form-group {
    margin-bottom: 20px;
}
.lp-label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.lp-select,
.lp-panel .form-control {
    display: block;
    width: 100%;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.5;
    color: #1f2328;
    background: #fff;
    border: 2px solid #e7e8ea;
    border-radius: 8px;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
}
.lp-select:focus,
.lp-panel .form-control:focus {
    border-color: #2f6fdd;
    outline: none;
    box-shadow: 0 0 0 3px rgba(47, 111, 221, 0.15);
}
.lp-select:disabled,
.lp-panel .form-control:disabled {
    background-color: #fafbfc;
    color: #8a919b;
    cursor: not-allowed;
}

/* Primary Button */
.lp-btn-primary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 14px 24px;
    font-size: 15px;
    font-weight: 600;
    color: #fff;
    background: #217a41;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.lp-btn-primary:hover {
    transform: none;
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}
.lp-btn-primary:active {
    transform: translateY(0);
}
.lp-btn-primary .glyphicon {
    font-size: 14px;
}

/* Empty State */
.lp-empty-state {
    text-align: center;
    padding: 40px 20px;
}
.lp-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    background: #f0f1f2;
    border-radius: 16px;
    margin-bottom: 16px;
}
.lp-empty-icon .glyphicon {
    font-size: 32px;
    color: #8a919b;
}
.lp-empty-state h4 {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 600;
    color: #374151;
}
.lp-empty-state p {
    margin: 0;
    font-size: 14px;
    color: #8a919b;
    line-height: 1.5;
}

/* Help Card */
.lp-help-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: #e7f5ec;
    border: 1px solid #e7f5ec;
    border-radius: 10px;
}
.lp-help-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    background: #fff;
    border-radius: 8px;
    flex-shrink: 0;
}
.lp-help-icon .glyphicon {
    color: #217a41;
    font-size: 16px;
}
.lp-help-content strong {
    display: block;
    font-size: 13px;
    color: #1d6c3a;
    margin-bottom: 4px;
}
.lp-help-content p {
    margin: 0;
    font-size: 12px;
    color: #1d6c3a;
    line-height: 1.5;
}

/* Loading States */
.loading {
    display: inline-block;
    margin: 8px 0;
}

/* Responsive */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }
    .setup-page-header__title {
        font-size: 20px;
    }
    .lp-tabs {
        flex-direction: column;
    }
    .lp-tab {
        padding: 14px 16px;
    }
    .lp-panel-body {
        padding: 16px;
    }
}
</style>

<?php template_bottom();

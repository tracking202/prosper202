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
			<a href="<?php echo get_absolute_url();?>tracking202/setup/get_simple_landing_code.php" class="lp-tab">
				<div class="lp-tab-icon">
					<span class="glyphicon glyphicon-file"></span>
				</div>
				<span class="lp-tab-text">
					<strong>Simple</strong>
					<small>Single campaign per page</small>
				</span>
			</a>
			<a href="<?php echo get_absolute_url();?>tracking202/setup/get_adv_landing_code.php" class="lp-tab active">
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
					<input type="hidden" id="counter" name="counter" value="0"/>

					<div class="lp-form-group">
					    <label for="landing_page_id" class="lp-label">Landing Page</label>
				    	<select class="form-control lp-select" name="landing_page_id" id="landing_page_id">
							<option value="0">-- Select Landing Page --</option> <?php
							$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
							$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE user_id='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
							$landing_page_result = $db->query($landing_page_sql);
							while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {
								$html['landing_page_id'] = htmlentities((string)($landing_page_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
								$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
								printf('<option value="%s">%s</option>', $html['landing_page_id'], $html['landing_page_nickname']);
							} ?>
						</select>
					</div>

					<div id="area_1" class="lp-offer-section">
						<div class="lp-form-group">
							<label class="lp-label">Offer Type</label>
							<div class="lp-radio-group">
								<label class="lp-radio">
									<input type="radio" class="offer-type-radio" value="campaign" name="offer_type1" id="offer_type1" checked>
									<span class="lp-radio-box"></span>
									<span class="lp-radio-text">Campaign</span>
								</label>
								<label class="lp-radio">
									<input type="radio" class="offer-type-radio" value="rotator" name="offer_type1" id="offer_type2">
									<span class="lp-radio-box"></span>
									<span class="lp-radio-text">Rotator</span>
								</label>
							</div>
						</div>

						<div class="campaign_select">
							<div class="lp-form-group">
								<label for="aff_campaign_id_1" class="lp-label">Select Campaign</label>
								<select class="form-control lp-select" name="aff_campaign_id_1" id="aff_campaign_id_1">
									<option value="0">-- Select Campaign --</option>
									<?php
									$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
									$aff_campaign_sql = "SELECT aff_campaign_id, aff_campaign_name, aff_network_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE 202_aff_campaigns.user_id='".$mysql['user_id']."' AND aff_campaign_deleted='0' AND aff_network_deleted=0 ORDER BY aff_network_name ASC";
									$aff_campaign_result = $db->query($aff_campaign_sql);
									while ($aff_campaign_row = $aff_campaign_result->fetch_assoc()) {
										$html['aff_campaign_id'] = htmlentities((string)($aff_campaign_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
										$html['aff_campaign_name'] = htmlentities((string)($aff_campaign_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
										$html['aff_network_name'] = htmlentities((string)($aff_campaign_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
										printf('<option value="%s">%s: %s</option>', $html['aff_campaign_id'], $html['aff_network_name'], $html['aff_campaign_name']);
									} ?>
								</select>
							</div>
						</div>

						<div class="rotator_select" style="display:none">
							<div class="lp-form-group">
								<label for="rotator_id_1" class="lp-label">Select Rotator</label>
								<select class="form-control lp-select" name="rotator_id_1" id="rotator_id_1">
									<option value="0">-- Select Rotator --</option>
									<?php
									$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
									$rotator_sql = "SELECT id, name FROM 202_rotators WHERE user_id='".$mysql['user_id']."' ORDER BY name ASC";
									$rotator_result = $db->query($rotator_sql);
									while ($rotator_row = $rotator_result->fetch_assoc()) {
										$html['rotator_id'] = htmlentities((string)($rotator_row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
										$html['rotator_name'] = htmlentities((string)($rotator_row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
										printf('<option value="%s">%s</option>', $html['rotator_id'], $html['rotator_name']);
									} ?>
								</select>
							</div>
						</div>
					</div>

					<div class="col-xs-8 col-xs-offset-4" style="padding: 0;">
						<img id="load_aff_campaign_1_loading" style="display: none;" src="<?php echo get_absolute_url();?>202-img/loader-small.gif"/>
					</div>

					<div id="load_aff_campaign_1"></div>

					<div class="lp-form-group" style="margin-top: 20px; margin-bottom: 0;">
						<button type="button" id="add-more-offers" class="lp-btn-secondary">
							<span class="glyphicon glyphicon-plus"></span>
							Add Another Offer
						</button>
						<button type="button" id="generate-tracking-link-adv" class="lp-btn-primary" style="margin-top: 10px;">
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
				<strong>When to use Advanced?</strong>
				<p>Use Advanced mode when your landing page has multiple offers or uses a rotator. Great for split testing different campaigns.</p>
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
					<p>Select your landing page and campaigns, then click "Generate Tracking Code"</p>
				</div>
			</div>
		</div>
	</div>
</div>

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
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
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
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    text-decoration: none;
    color: #64748b;
    transition: all 0.2s ease;
    flex: 1;
}
.lp-tab:hover {
    border-color: #007bff;
    color: #007bff;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.1);
}
.lp-tab.active {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
}
.lp-tab-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    background: rgba(0, 123, 255, 0.1);
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
    border: 1px solid #e2e8f0;
    overflow: hidden;
    margin-bottom: 20px;
}
.lp-panel-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}
.lp-panel-header .glyphicon {
    color: #007bff;
    font-size: 16px;
}
.lp-panel-body {
    padding: 24px;
}
.lp-panel-output .lp-panel-header {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border-bottom-color: #a7f3d0;
}
.lp-panel-output .lp-panel-header .glyphicon {
    color: #059669;
}

/* Form Styling */
.lp-form-group {
    margin-bottom: 20px;
}
.lp-label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #475569;
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
    color: #1e293b;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
}
.lp-select:focus,
.lp-panel .form-control:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
}
.lp-select:disabled,
.lp-panel .form-control:disabled {
    background-color: #f8fafc;
    color: #94a3b8;
    cursor: not-allowed;
}

/* Radio Group */
.lp-radio-group {
    display: flex;
    gap: 16px;
}
.lp-radio {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 10px 16px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.2s ease;
    flex: 1;
    margin: 0;
    font-weight: 500;
}
.lp-radio:hover {
    border-color: #007bff;
    background: #f0f7ff;
}
.lp-radio input {
    display: none;
}
.lp-radio-box {
    width: 18px;
    height: 18px;
    border: 2px solid #cbd5e1;
    border-radius: 50%;
    position: relative;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.lp-radio input:checked + .lp-radio-box {
    border-color: #007bff;
    background: #007bff;
}
.lp-radio input:checked + .lp-radio-box::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 6px;
    height: 6px;
    background: #fff;
    border-radius: 50%;
}
.lp-radio input:checked ~ .lp-radio-text {
    color: #007bff;
}
.lp-radio-text {
    font-size: 14px;
    color: #475569;
}

/* Offer Section */
.lp-offer-section {
    padding: 16px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 16px;
}
.lp-offer-section .lp-form-group:last-child {
    margin-bottom: 0;
}

/* Buttons */
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
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.lp-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}
.lp-btn-primary:active {
    transform: translateY(0);
}
.lp-btn-primary .glyphicon {
    font-size: 14px;
}

.lp-btn-secondary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 600;
    color: #007bff;
    background: #fff;
    border: 2px solid #007bff;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.lp-btn-secondary:hover {
    background: #f0f7ff;
}
.lp-btn-secondary .glyphicon {
    font-size: 12px;
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
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border-radius: 16px;
    margin-bottom: 16px;
}
.lp-empty-icon .glyphicon {
    font-size: 32px;
    color: #94a3b8;
}
.lp-empty-state h4 {
    margin: 0 0 8px 0;
    font-size: 16px;
    font-weight: 600;
    color: #475569;
}
.lp-empty-state p {
    margin: 0;
    font-size: 14px;
    color: #94a3b8;
    line-height: 1.5;
}

/* Help Card */
.lp-help-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1px solid #bbf7d0;
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
    color: #059669;
    font-size: 16px;
}
.lp-help-content strong {
    display: block;
    font-size: 13px;
    color: #065f46;
    margin-bottom: 4px;
}
.lp-help-content p {
    margin: 0;
    font-size: 12px;
    color: #047857;
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
    .lp-radio-group {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<?php template_bottom();

<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

template_top('Pixel And Postback URLs');
?>
<link rel="stylesheet" href="/202-css/design-system.css">
<?php

//the pixels
$unSecuredPixel = '<img height="1" width="1" border="0" style="display: none;" src="http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/gpx.php?amount=&subid=" />';
$unSecuredPixel_2 = '<img height="1" width="1" border="0" style="display: none;" src="http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/gpx.php?amount=&cid=&subid=" />';

//post back urls
$unSecuredPostBackUrl = 'http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/gpb.php?amount=&subid=';
$unSecuredPostBackUrl_2 = 'http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/gpb.php?amount=&subid=';

//universal pixel
$unSecuredUniversalPixel = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/upx.php?amount=&subid=" seamless></iframe>';

$unSecuredUniversalPixelJS = '
<script>
 var vars202={amount:"",cid:"",subid:""};(function(d, s) {
 	var js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {
 		if (d.getElementById(id)) {return;}
 		if202 = d.createElement("iframe");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = "no";if202.noResize = true;
 		upxf.parentNode.insertBefore(if202, upxf);
 	};
 	load("http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/upx.php?amount="+vars202[\'amount\']+"&cid="+vars202[\'cid\']+"&subid="+vars202[\'subid\'], "upxif");
 }(document, "script"));</script>
<noscript>
 	<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="http://'. getTrackingDomain() .get_absolute_url().'tracking202/static/upx.php?amount=&cid=&subid=" seamless></iframe>
</noscript>';

?>

<!-- Page Header - Design System -->
<div class="postback-page">
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-transfer"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Postback / Pixel</h1>
				<p class="setup-page-header__subtitle">Configure conversion tracking for your affiliate networks</p>
			</div>
		</div>
	</div>
</div>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-info">
			<i class="fa fa-info-circle"></i>
			<strong>How it works:</strong> Place conversion pixels on advertiser pages to automatically track conversions in real-time. 
			Postback URLs enable server-to-server tracking with supported networks. 
			<a href="#" data-toggle="tooltip" title="Learn more about pixel implementation">
				<i class="fa fa-question-circle"></i>
			</a>
		</div>
	</div>
</div>	

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<form method="post" id="tracking_form" class="form-horizontal" role="form" style="margin:0px 0px 15px 0px;">
			<div class="form-group" style="margin-bottom: 0px;" id="pixel-type">
				<label class="col-md-3 control-label" style="text-align: left;"><i class="fa fa-crosshairs"></i> Pixel Type:</label>

				<div class="col-md-9 radio-group" style="margin-top: 15px;">
					<div class="radio-option active">
						<label class="radio">
	            			<input type="radio" name="pixel_type" value="0" data-toggle="radio" checked="">
	            			<span class="radio-title">Simple Pixel</span>
	            			<div class="help-text">Basic tracking - one click tracked at a time. Perfect for simple campaigns.</div>
	          			</label>
					</div>
	          		<div class="radio-option">
	          			<label class="radio">
	            			<input type="radio" name="pixel_type" value="1" data-toggle="radio">
	            			<span class="radio-title">Advanced Pixel</span>
	            			<div class="help-text">Multi-click tracking - handle multiple simultaneous clicks with campaign-specific targeting.</div>
	          			</label>
					</div>
	          		<div class="radio-option">
	          			<label class="radio">
	            			<input type="radio" name="pixel_type" value="2" data-toggle="radio">
	            			<span class="radio-title">Universal Smart Pixel</span>
	            			<div class="help-text">Intelligent tracking - automatically fires 3rd party pixels and optimizes conversion attribution.</div>
	          			</label>
					</div>
	          	</div>
	        </div>

	        <div class="form-group" style="margin-bottom: 0px;" id="secure-pixels">
				<label class="col-md-3 control-label" style="text-align: left;"><i class="fa fa-shield"></i> Protocol:</label>

				<div class="col-md-9" style="margin-top: 15px;">
					<div class="row">
						<div class="col-md-6">
							<label class="radio">
		            			<input type="radio" name="secure_type" value="0" data-toggle="radio" checked="">
		            				<i class="fa fa-unlock text-warning"></i> HTTP <span class="label label-default">http://</span>
		          			</label>
						</div>

						<div class="col-md-6">
							<label class="radio">
			            		<input type="radio" name="secure_type" value="1" data-toggle="radio">
			            			<i class="fa fa-lock text-success"></i> HTTPS <span class="label label-success">https://</span>
			          		</label>
						</div>
					</div>
					<small class="help-block">Use HTTPS only if you have SSL certificates installed on your tracking domain.</small>
	          	</div>
	        </div>

	        <div class="form-group" style="margin-bottom: 0px;">
				<label class="col-md-3 control-label" for="amount_value" style="text-align: left;">Amount:</label>
				<div class="col-md-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="amount_value" id="amount_value"/>
					<span class="help-block" style="font-size: 10px;">Enter an amount to override the affiliate campaign default</span>
				</div>
			</div>

			<div id="advanced_pixel_type" style="display:none;">
				<div class="form-group" style="margin-bottom: 0px;">
			        <label for="aff_network_id" class="col-md-3 control-label" style="text-align: left;">Category:</label>
			        <div class="col-md-6" style="margin-top: 10px;">
			        	<img id="aff_network_id_div_loading" src="/202-img/loader-small.gif" />
						<div id="aff_network_id_div"></div>
			        </div>
			    </div>
			    <div class="form-group" style="margin-bottom: 0px;">
			        <label for="aff_campaign_id" class="col-md-3 control-label" style="text-align: left;">Campaign:</label>
			        <div class="col-md-6" style="margin-top: 10px;">
			        	<img id="aff_campaign_id_div_loading" src="/202-img/loader-small.gif" style="display: none;" />
						<div id="aff_campaign_id_div">
							<select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                	<option>--</option>
			            	</select>
						</div>
			        </div>
			    </div>

		    </div>
		    					<div class="form-group">
						<label class="col-md-3 control-label" for="subid_value"><i class="fa fa-tag"></i> SubID:</label>
						<div class="col-md-4">
							<input class="form-control" type="text" name="subid_value" id="subid_value" placeholder="{aff_sub}"/>
							<p class="help-block">Network-specific subID parameter. Common formats:</p>
							<div class="subid-examples">
								<span class="label label-info">%subid1%</span>
								<span class="label label-info">#s1#</span>
								<span class="label label-info">{aff_sub}</span>
							</div>
						</div>
					</div>
			
		</form>
	</div>
</div>

	<div class="row form_seperator" style="margin-bottom:15px;">
		<div class="col-xs-12"></div>
	</div>
<div class="row">
	<div class="col-xs-12">
		<?php

		printf('
			<div id="pixel_type_simple_id" class="tracking-output">
				<div class="panel panel-default setup-side-panel">
					<div class="panel-heading">
						<h3 class="panel-title">
							<i class="fa fa-image"></i> Simple Global Tracking Pixel
						</h3>
					</div>
					<div class="panel-body">
						<div class="alert alert-info">
							<i class="fa fa-info-circle"></i> Place this pixel on your conversion/thank you page. It will automatically track conversions when fired.
						</div>
						
						<div class="code-wrapper">
							<div class="input-group">
								<textarea id="unsecure_pixel" class="form-control code-textarea" rows="2" readonly>%s</textarea>
								<span class="input-group-btn">
									<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_pixel" data-toggle="tooltip" title="Copy to clipboard">
										<i class="fa fa-copy"></i> Copy
									</button>
								</span>
							</div>
						</div>
					</div>
				</div>
				<div class="panel panel-default setup-side-panel">
					<div class="panel-heading">
						<h3 class="panel-title">
							<i class="fa fa-link"></i> Simple Global Post Back URL
						</h3>
					</div>
					<div class="panel-body">
						<div class="alert alert-info">
							<i class="fa fa-info-circle"></i> For server-to-server tracking. The network calls this URL with the SUBID parameter when conversions occur.
							<br><small><strong>Tip:</strong> Replace <code>?subid=</code> with <code>?sid=</code> if your network only supports the sid parameter.</small>
						</div>
						
						<div class="code-wrapper">
							<div class="input-group">
								<textarea id="unsecure_postback" class="form-control code-textarea" rows="2" readonly>%s</textarea>
								<span class="input-group-btn">
									<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_postback" data-toggle="tooltip" title="Copy to clipboard">
										<i class="fa fa-copy"></i> Copy
									</button>
								</span>
							</div>
						</div>
					</div>
				</div>
			</div>
', $unSecuredPixel, $unSecuredPostBackUrl
		);

		printf('
<div id="pixel_type_advanced_id" style="display:none;">
	<div class="panel panel-default setup-side-panel">
		<div class="panel-heading">
			<h3 class="panel-title">
				<i class="fa fa-cogs"></i> Advanced Global Tracking Pixel
			</h3>
		</div>
		<div class="panel-body">
			<div class="alert alert-success">
				<i class="fa fa-check-circle"></i> Advanced pixel supports campaign-specific tracking and multiple simultaneous clicks.
			</div>
			
			<div class="code-wrapper">
				<div class="input-group">
					<textarea id="unsecure_pixel_2" class="form-control code-textarea" rows="2" readonly>%s</textarea>
					<span class="input-group-btn">
						<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_pixel_2" data-toggle="tooltip" title="Copy to clipboard">
							<i class="fa fa-copy"></i> Copy
						</button>
					</span>
				</div>
			</div>
		</div>
	</div>

	<div class="panel panel-default setup-side-panel">
		<div class="panel-heading">
			<h3 class="panel-title">
				<i class="fa fa-exchange"></i> Advanced Global Post Back URL
			</h3>
		</div>
		<div class="panel-body">
			<div class="alert alert-info">
				<i class="fa fa-server"></i> Server-to-server postback with campaign targeting capabilities.
				<br><small><strong>Note:</strong> Requires campaign selection above to populate the CID parameter.</small>
			</div>
			
			<div class="code-wrapper">
				<div class="input-group">
					<textarea id="unsecure_postback_2" class="form-control code-textarea" rows="2" readonly>%s</textarea>
					<span class="input-group-btn">
						<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_postback_2" data-toggle="tooltip" title="Copy to clipboard">
							<i class="fa fa-copy"></i> Copy
						</button>
					</span>
				</div>
			</div>
		</div>
	</div>
</div>
', $unSecuredPixel_2, $unSecuredPostBackUrl_2
		);
		
		printf('
<div id="pixel_type_universal_id" style="display:none;">
	<div class="panel panel-default setup-side-panel">
		<div class="panel-heading">
			<h3 class="panel-title">
				<i class="fa fa-magic"></i> Javascript Universal Smart Tracking Pixel
			</h3>
		</div>
		<div class="panel-body">
			<div class="alert alert-warning">
				<i class="fa fa-star"></i> <strong>Smart Technology:</strong> Automatically fires your traffic source pixels in addition to Prosper202 tracking. 
				Includes fallback noscript version for maximum compatibility.
			</div>
			
			<div class="code-wrapper">
				<div class="input-group">
					<textarea id="unsecure_universal_pixel_js" class="form-control code-textarea" rows="13" readonly>%s</textarea>
					<span class="input-group-btn">
						<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_universal_pixel_js" data-toggle="tooltip" title="Copy to clipboard">
							<i class="fa fa-copy"></i> Copy
						</button>
					</span>
				</div>
			</div>
		</div>
	</div>

	<div class="panel panel-default setup-side-panel">
		<div class="panel-heading">
			<h3 class="panel-title">
				<i class="fa fa-code"></i> Iframe Universal Smart Tracking Pixel
			</h3>
		</div>
		<div class="panel-body">
			<div class="alert alert-info">
				<i class="fa fa-info-circle"></i> Simple iframe version of the Universal Smart Pixel. Use when JavaScript implementation is not possible.
			</div>
			
			<div class="code-wrapper">
				<div class="input-group">
					<textarea id="unsecure_universal_pixel" class="form-control code-textarea" rows="2" readonly>%s</textarea>
					<span class="input-group-btn">
						<button class="btn btn-primary copy-btn" type="button" data-target="unsecure_universal_pixel" data-toggle="tooltip" title="Copy to clipboard">
							<i class="fa fa-copy"></i> Copy
						</button>
					</span>
				</div>
			</div>
		</div>
	</div>

</div>
', $unSecuredUniversalPixelJS, $unSecuredUniversalPixel
		); ?>
	</div>
</div>
</div> <!-- Close container-fluid -->

<script type="text/javascript">
$(document).ready(function() {
    // Initialize radio buttons and tooltips
    $('[data-toggle="radio"]').radiocheck();
    $('[data-toggle="tooltip"]').tooltip();
    
    // Enhanced radio button styling and interactions
    setupRadioInteractions();
    
    // Copy functionality
    setupCopyButtons();
    
    // Form change handlers
    $("#secure-pixels input:radio").on("change.radiocheck", function () {
        change_pixel_data();
        updateSecurityBadges();
    });

    $('#amount_value').keyup(function () { 
        debounce(change_pixel_data, 300)();
        validateAmount();
    });
    
    $('#subid_value').keyup(function () { 
        debounce(change_pixel_data, 300)();
        validateSubid();
    });

    // Initialize
    load_aff_network_id();
    change_pixel_data();
    updateSecurityBadges();
});

// Enhanced radio button interactions
function setupRadioInteractions() {
    // Handle pixel type selection
    $("#pixel-type input:radio").on("change.radiocheck", function() {
        const value = $(this).val();
        
        // Update visual state
        $('.radio-option').removeClass('active');
        $(this).closest('.radio-option').addClass('active');
        
        // Show/hide appropriate panels
        $('.tracking-output > div').hide();
        
        if (value === '0') {
            $('#pixel_type_simple_id').fadeIn(300);
        } else if (value === '1') {
            $('#pixel_type_advanced_id').fadeIn(300);
            $('#advanced_pixel_type').show();
        } else if (value === '2') {
            $('#pixel_type_universal_id').fadeIn(300);
        }
        
        change_pixel_data();
    });
    
    // Security type changes
    $("#secure-pixels input:radio").on("change.radiocheck", function() {
        updateSecurityBadges();
    });
}

// Copy to clipboard functionality
function setupCopyButtons() {
    $(document).on('click', '.copy-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const targetId = button.data('target');
        const textarea = $('#' + targetId);
        
        if (textarea.length === 0) return;
        
        // Select and copy text
        textarea.select();
        textarea[0].setSelectionRange(0, 99999); // For mobile devices
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess(button);
            } else {
                showCopyError(button);
            }
        } catch (err) {
            // Fallback for modern browsers
            navigator.clipboard.writeText(textarea.val()).then(function() {
                showCopySuccess(button);
            }).catch(function() {
                showCopyError(button);
            });
        }
        
        // Deselect text
        window.getSelection().removeAllRanges();
    });
}

function showCopySuccess(button) {
    const originalHtml = button.html();
    button.addClass('success')
          .html('<i class="fa fa-check"></i> Copied!');
    
    setTimeout(function() {
        button.removeClass('success').html(originalHtml);
    }, 2000);
}

function showCopyError(button) {
    const originalHtml = button.html();
    button.addClass('btn-danger')
          .html('<i class="fa fa-exclamation"></i> Error');
    
    setTimeout(function() {
        button.removeClass('btn-danger').html(originalHtml);
    }, 2000);
}

// Update security badges
function updateSecurityBadges() {
    const isSecure = $("input[name=secure_type]:checked").val() === '1';
    const protocol = isSecure ? 'HTTPS' : 'HTTP';
    const icon = isSecure ? 'fa-lock' : 'fa-unlock';
    const color = isSecure ? 'success' : 'warning';
    
    $('.protocol-badge').remove();
    $('.panel-heading').each(function() {
        const badge = $('<span class="badge badge-' + color + ' protocol-badge pull-right">')
                     .html('<i class="fa ' + icon + '"></i> ' + protocol);
        $(this).find('.panel-title').prepend(badge);
    });
}

// Input validation
function validateAmount() {
    const amount = $('#amount_value').val();
    const formGroup = $('#amount_value').closest('.form-group');
    
    formGroup.removeClass('has-error has-success');
    
    if (amount && isNaN(amount)) {
        formGroup.addClass('has-error');
        showFieldError('#amount_value', 'Please enter a valid number');
    } else if (amount && parseFloat(amount) < 0) {
        formGroup.addClass('has-error');
        showFieldError('#amount_value', 'Amount cannot be negative');
    } else if (amount) {
        formGroup.addClass('has-success');
        hideFieldError('#amount_value');
    }
}

function validateSubid() {
    const subid = $('#subid_value').val();
    const formGroup = $('#subid_value').closest('.form-group');
    
    formGroup.removeClass('has-error has-success');
    
    if (subid) {
        formGroup.addClass('has-success');
    }
}

function showFieldError(selector, message) {
    hideFieldError(selector);
    const errorDiv = $('<div class="alert alert-danger field-error">')
                    .html('<small>' + message + '</small>');
    $(selector).closest('.form-group').append(errorDiv);
}

function hideFieldError(selector) {
    $(selector).closest('.form-group').find('.field-error').remove();
}

// Debounce function for performance
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Enhanced pixel data change function
function change_pixel_data(){
    $('.tracking-output').addClass('loading');
    pixel_data_changed("<?php echo getTrackingDomain(); ?>");
    
    setTimeout(function() {
        $('.tracking-output').removeClass('loading');
        $('.tracking-output .panel:visible').addClass('fade-in-success');
        
        setTimeout(function() {
            $('.fade-in-success').removeClass('fade-in-success');
        }, 500);
    }, 300);
}

// Add smooth scrolling to results
function scrollToResults() {
    $('html, body').animate({
        scrollTop: $('.tracking-output:visible').offset().top - 20
    }, 500);
}

// Enhanced form submission feedback
$(document).on('change', '#tracking_form input, #tracking_form select', function() {
    if (!$('.tracking-output:visible').length) return;
    
    debounce(function() {
        scrollToResults();
    }, 1000)();
});
</script>

<style>
/* Setup Page Header */
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

/* Enhanced Panel Styling */
.panel {
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
}

.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 12px 12px 0 0 !important;
    padding: 16px 20px;
}

.panel-title {
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}

.panel-body {
    padding: 24px;
}

/* Form Enhancements */
.form-control {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
}

/* Button Enhancements */
.btn-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.25);
    transition: all 0.2s ease;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 123, 255, 0.35);
}

.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

/* Code Block Styling */
pre, code {
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

/* Radio group enhancements */
.radio-group {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.radio-option {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.radio-option:hover {
    border-color: #007bff;
    background: #f0f8ff;
}

.radio-option.active {
    border-color: #007bff;
    background: #e7f3ff;
}

.radio-title {
    font-weight: 600;
    font-size: 16px;
    color: #333;
    display: block;
    margin-top: 5px;
}

.help-text {
    color: #666;
    font-size: 13px;
    margin: 5px 0 0 25px;
}

.subid-examples {
    margin-top: 10px;
}

.subid-examples .label {
    margin-right: 8px;
}

.code-wrapper {
    position: relative;
    margin-bottom: 10px;
}

.code-textarea {
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 12px;
    background-color: #f8f9fa !important;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    resize: vertical;
    min-height: 60px;
}

.code-textarea:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.copy-btn {
    position: relative;
    z-index: 10;
}

.copy-btn.success {
    background-color: #28a745;
    border-color: #28a745;
    color: white;
}

.tracking-output {
    margin-top: 30px;
}

/* Alert Styles - Design System */
.alert-info {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #93c5fd;
    color: #1e40af;
    border-radius: 8px;
    padding: 12px 16px;
}

.alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1px solid #86efac;
    color: #166534;
    border-radius: 8px;
    padding: 12px 16px;
}

.alert-warning {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fcd34d;
    color: #92400e;
    border-radius: 8px;
    padding: 12px 16px;
}

/* Better form styling */
.form-group {
    margin-bottom: 25px;
}

.control-label {
    font-weight: 600;
    color: #495057;
}

.input-group-addon {
    background-color: #e9ecef;
    border-color: #ced4da;
}

/* Loading animations */
.loading {
    opacity: 0.7;
    pointer-events: none;
}

.spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Success animations */
@keyframes fadeInSuccess {
    0% { opacity: 0; transform: translateY(-10px); }
    100% { opacity: 1; transform: translateY(0); }
}

.fade-in-success {
    animation: fadeInSuccess 0.5s ease-out;
}

/* Setup List Item Styling */
.setup-list-name {
    display: inline-block;
    flex: 1;
    word-break: break-word;
}

.filter_xxx_name {
    font-weight: 500;
    color: #333;
}

.setup-list-actions {
    display: inline-block;
    white-space: nowrap;
    margin-left: 15px;
}

.setup-list-actions a {
    display: inline-block;
    margin-left: 10px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 500;
    border-radius: 4px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.setup-list-actions .action-edit {
    background-color: #e7f3ff;
    color: #0056b3;
    border: 1px solid #93c5fd;
}

.setup-list-actions .action-edit:hover {
    background-color: #cce5ff;
    color: #004085;
}

.setup-list-actions .action-remove {
    background-color: #ffe7e7;
    color: #b30000;
    border: 1px solid #ffb3b3;
}

.setup-list-actions .action-remove:hover {
    background-color: #ffcccc;
    color: #800000;
}

ul.setup-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

ul.setup-list li {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    margin-bottom: 8px;
    background: #ffffff;
    transition: all 0.2s ease;
}

ul.setup-list li:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e1;
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

    .setup-page-header__icon {
        width: 48px;
        height: 48px;
    }

    .setup-page-header__icon .glyphicon {
        font-size: 20px;
    }

    .setup-page-header__subtitle {
        font-size: 13px;
    }

    .radio-group {
        gap: 10px;
    }

    .radio-option {
        padding: 12px;
    }

    .col-md-3,
    .col-md-4,
    .col-md-6,
    .col-md-8,
    .col-md-9,
    .col-md-12 {
        padding-left: 10px;
        padding-right: 10px;
    }

    ul.setup-list li {
        flex-direction: column;
        align-items: flex-start;
    }

    .setup-list-actions {
        margin-left: 0;
        margin-top: 10px;
        display: flex;
        width: 100%;
    }

    .setup-list-actions a {
        margin-left: 0;
        margin-right: 8px;
        flex: 0 1 auto;
    }
}
</style>

<?php template_bottom(); ?>

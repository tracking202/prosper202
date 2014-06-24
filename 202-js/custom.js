$(document).ready(function() {

	var validator = $("#survey-form").validate({
	  	    ignore:[],
	  	    focusCleanup: true,
	  	    errorPlacement: function(error, element) {},
		  	highlight: function(element) {
			    $(element).closest('.form-group').addClass('has-error');
			},
			unhighlight: function(element, errorClass) {
		        $(element).closest('.form-group').removeClass('has-error');
		    }
	});

	var select_id = 0;

	//P202 update check
	$.ajax({
		url: "/202-account/ajax/check-for-update.php",
	})
	.done(function() {
		$.get("/202-account/ajax/update-needed.php", function(data) {
		  	$("#update_needed").html(data);
		});
	});

	//tooltips int
	$("[data-toggle=tooltip]").tooltip();

	//show/hide help text
	$("#help-text-trigger").click(function() {
		var element = $("#help-text");
		if (element.is(':hidden')) {
			element.fadeIn();
		} else {
			element.fadeOut();
		}
	});

	//Campaign rotator No/Yes
	$(":radio[name=aff_campaign_rotate]").on("toggle", function () {
		var element = $("#rotateUrls");
        $(this).radio("check");
        
        if ($(this).val() == 1) {
        	element.show();
        } else {
        	element.hide();
        }
    });

	//Direct/Simple/Advanced landing page radio buttons
    $("#radio-select input:radio").on("toggle", function () {
		var element = $("#aff-campaign-div");
		var lp_element = $("#lp_landing_page");
        $(this).radio("check");
        
        if ($(this).val() == 0) {
        	element.show();
        	lp_element.hide();
        	load_aff_network_id();
        	$("#aff_campaign_id").html("<option>--</option>").prop( "disabled", true );;
        } else {
        	element.hide();
        	lp_element.show();
        }
    });

    //Ad Preview update from input
    $('#text_ad_headline').keyup(function () { $("#ad-preview-headline").html($(this).val()); });
    $('#text_ad_description').keyup(function () { $("#ad-preview-body").html($(this).val()); });
    $('#text_ad_display_url').keyup(function () { $("#ad-preview-url").html($(this).val()); });

    //Placeholder select buttons
    $('#placeholders input[type=button]').click(function() {
            var value = $(this).val();
            var input = $("#aff_campaign_url");
            input.caret(value);
            return false;
    });

    //triger simple LP tracking link generate function
    $("#generate-tracking-link-simple").click(function() {
    	generate_simple_lp_tracking_links();
	});

    //triger add new offer function on ADV LP page
    $("#add-more-offers").click(function() {
    	load_new_aff_campaign();
	});

    //triger ADV LP tracking link generate function
    $("#generate-tracking-link-adv").click(function() {
    	generate_adv_lp_tracking_links();
	});

	//triger Get Links function
    $("#get-links").click(function() {
    	getTrackingLinks();
	});

    //Tracker type select on Get Links
	$("#tracker-type input:radio").on("toggle", function () {
        $(this).radio("check");
        var element1 = $('#tracker_aff_network');
        var element2 = $('#tracker_aff_campaign');
        var element3 = $('#tracker_method_of_promotion');
        var element4 = $('#tracker_lp');
        var element5 = $('#tracker_ad_copy');
        var element6 = $('#tracker_ad_preview');
        var element7 = $('#tracker_cloaking');
        var element8 = $('#tracker_rotator');

        if ($(this).val() == 0) {
        	element1.show();
			element2.show();
			element3.show();
			element4.show();
			element5.show();
			element6.show();
			element7.show();
			element8.hide();

			load_aff_network_id(0);
			load_aff_campaign_id(0,0);
			load_landing_page(0, 0, '');
        } else if($(this).val() == 1) {
        	element1.hide();
			element2.hide();
			element3.hide();
			element4.show();
			element5.show();
			element6.show();
			element7.show();
			element8.hide();

			load_aff_network_id(0);
			load_aff_campaign_id(0,0);
			load_landing_page(0, 0, 'advlandingpage');
        } else if($(this).val() == 2) {
			element1.hide();
			element2.hide();
			element3.hide();
			element4.hide();
			element5.hide();
			element6.hide();
			element7.hide();
			element8.show();
			load_rotator_id(0);
		}
    });

	//Pixel Type select
	$("#pixel-type input:radio").on("toggle", function () {
		$(this).radio("check");
		var pixel_type = $(this).val();

		var element1 = $('#pixel_type_simple_id');
        var element2 = $('#pixel_type_advanced_id');
        var element3 = $('#advanced_pixel_type');
        var element4 = $('#pixel_type_universal_id');

		if (pixel_type == '0') { 
			element1.show();
			element2.hide();
			element3.hide();
			element4.hide();
		} else if (pixel_type == '1') {
			element1.hide();
			element2.show();
			load_aff_network_id();
			element3.show();
			element4.hide();
		} else if (pixel_type == '2') {
			element1.hide();
			element2.hide();
			element3.hide();
			element4.show();
			
		}	
	});

	//Search preferences datepicker
	$('#preferences-wrapper .datepicker input:text').datepicker({
	    dateFormat: 'mm/dd/yy',

	    onSelect: function(datetext){
	    	var id = $(this).attr("id");

	    	if (id == "from") {
	    		$(this).val(datetext+" - 0:00");
	    		unset_user_pref_time_predefined();
	    	} else {
	    		$(this).val(datetext+" - 23:59");
	    		unset_user_pref_time_predefined();
	    	}
	    },
	});

	//More/Less Options in search preferences
	$("#s-toogleAdv").click(function() {
		$('#text_ad_id').val(0);
		$('#method_of_promotion').val(0);
		$('#landing_page_id').val(0);
		$('#ad_preview_div').html("");
		$('#country').val("");
		$('#referer').val("");

		if($('#more-options').is(':hidden')){
			$("#more-options").fadeToggle( "fast" );
			$('#user_pref_adv').val("1");
			$('#s-toogleAdv').text('Less Options');
		} else {
			$("#more-options").fadeToggle( "fast" );
			$('#user_pref_adv').val("0");
			$('#s-toogleAdv').text('More Options');
		}
	});

	//Update CPC date picker
	$("#update-cpc-dates input").datepicker({dateFormat: 'mm/dd/yy'});

	//Update CPC button
	$("#update-cpc").click(function() {
		var element = $("#confirm-cpc-update-content");
		$.post("/tracking202/ajax/update_cpc.php", $('#cpc_form').serialize(true))
		  .done(function(data) {
		  	element.css("opacity", "1");
		  	element.html(data);
		});
		
	});

	//Clear SUBIDs button
	$("#clear-subids").click(function() {
		var element = $("#response");
		$.post("/tracking202/ajax/clear_subids.php", $('#clear_subids_form').serialize(true))
		  .done(function(data) {
		  	element.html(data);
		});
		
	});

	//Update Survey questions
	$("#survey-form-submit").click(function() {
		$('#perks-loading').show();

		$("#survey-form").validate().resetForm();

		if ($("#survey-form").valid()) {
			$.post("/202-account/ajax/survey.php", $('#survey-form').serialize(true))
			  .done(function(data) {
			  	if (!data) {
			  		$('#perks-error').hide();
					$('#perks-success').show();
			  		$('#survey-modal').modal('hide');
			  		$('#notification').remove();
			  		$('#notification-perks').remove();
			  		$('#perks-loading').hide();
			  		$("html, body").animate({ scrollTop: 0 }, "slow");
			  	} else {
			  		$('#perks-error').html(data).show();
			  		$('#perks-loading').hide();
					$("html, body").animate({ scrollTop: 0 }, "slow");
			  	}
			});
		} else {
			$('#perks-success').hide();
			$('#perks-error').show();
			$('#perks-loading').hide();
			$("html, body").animate({ scrollTop: 0 }, "slow");
		}
	});

	$("#survey-form :radio").on("toggle", function () {
		$(this).prop('checked', true);
    });

	$('#account-dropdown').on('shown.bs.dropdown', function () {
	  $('#notification').hide();
	})

	$('#account-dropdown').on('hidden.bs.dropdown', function () {
	  $('#notification').show();
	})

	//Add more rotator rules
	$("#add_more_rules").click(function() {
		var id;
		var select_id = Math.round(Math.random()*1000);
		$('#addmore_loading').show();
		$.post("/tracking202/ajax/rotator.php", {add_more_rules: 1})
		  .done(function(data) {
		  	var html = $(data);
		  	var select = html.filter('.rule_added').find('#tags_select').find('input');
		  	html.find('.rules').attr('id', 'rule_' + select_id);
		  	html.find('#rule_name').attr('name', 'rule_name_' + select_id);
		  	html.find('input[type=radio]').attr('name', 'redirect_type_' + select_id);
		  	id = 'tag_input_' + select_id;
		  	select.attr('id', id);

		  	$('#rotator_rules_container').append(html);
		  	$('#addmore_loading').hide();
		  	rotator_tags_autocomplete(id, 'country');

		});  
		
	});

	//Post rotator rules
	$("#post_rules").click(function() {
		$('#addmore_loading').show();
		//$(this).prop('disabled', true);
		//$('#add_more_rules').prop('disabled', true);

		var rules = [];
		var rotator_id = $('select[name=rotator_id]').val();
		var default_type = $(':radio[name=default_type]:checked').val();
		var defaults;

		if (default_type == 'campaign') {
			defaults = $('select[name=default_campaign]').val();
		} else if (default_type == 'url') {
			defaults = $('input[name=default_url]').val();
		}

		$('.rules').each(function(){
			var rule_id = $(this).data("rule-id");
			var rule_name = $(this).find('#rule_name').val();
			var inactive = $(this).find(':checkbox[name=inactive]');
			if(inactive.is(':checked')) {status = 'inactive';} else {status = 'active';}

			var redirect_type = $(this).find(':radio:checked').val();
			var redirects;
			var criteria = [];

			if (redirect_type == 'campaign') {
				redirects = $(this).find('select[name=redirect_campaign]').val();
			} else if (redirect_type == 'url') {
				redirects = $(this).find('input[name=redirect_url]').val();
			}

			$(this).find('.criteria').each(function() {
				var criteria_id = $(this).data("criteria-id");
			    var type = $(this).find('select[name=rule_type]').val();
				var statement = $(this).find('select[name=rule_statement]').val();
				var value = $(this).find('input[name=value]').tokenfield('getTokensList', ',', false, false);

				criteria.push({
					criteria_id: criteria_id,
			        type: type,
			        statement: statement,
			        value: value
			    });
			});
			
			rules.push({
				rule_id: rule_id,
		        rule_name: rule_name,
		        status: status,
		        redirect_type: redirect_type,
		        redirects: redirects,
		        criteria: criteria
		    });
		});
		$.post("/tracking202/ajax/rotator.php", 
			{
				post_rules: 1,  
				rotator_id: rotator_id, 
				data: rules,
				default_type: default_type,
				defaults: defaults
			})

		  	.done(function(data) {
		  		
		  		var result = $.trim(data);
		  		if (result == 'ERROR') {
		  			$('#form_response').hide();
		  			$('#addmore_loading').hide();
					$('#form_erors').show();
					$("html, body").animate({ scrollTop: 0 }, "slow");
		  		} else if(result == 'DONE') {
		  			window.location = "/tracking202/setup/rotator.php?rules_added=1";
		  		}
		});  
	});

	$('select[name=rotator_id]').change(function () {
		var loading = $('#rules_loading');
		loading.show();
		var elt = $(this).val();
		$.post("/tracking202/ajax/rotator.php", {rule_defaults: 1, rotator_id: elt})
		  .done(function(data) {
		  	$('#defaults_container').html(data);

		  	$.post("/tracking202/ajax/rotator.php", {generate_rules: 1, rotator_id: elt})
			  .done(function(data) {
			  	$('#rotator_rules_container').html(data);
			});
		  	loading.hide();
		});

	    if (elt > 0) {
	    	$('#defaults_container').css('opacity', '1');
	    	$('#rotator_rules_container').css('opacity', '1');
	    	$('#add_more_rules').prop('disabled', false);
	    	$('#post_rules').prop('disabled', false);
	    } else {
	    	$('#defaults_container').css('opacity', '0.5');
	    	$('#rotator_rules_container').css('opacity', '0.5');
	    	$('#add_more_rules').prop('disabled', true);
	    	$('#post_rules').prop('disabled', true);
	    }
 	});

 	//Rotator details modal
	$('#rule_values_modal').on('show.bs.modal', function (e) {
		var id = $(e.relatedTarget).data('id');
		$.post("/tracking202/ajax/rotator.php", {rotator_details: 1, rotator_id: id})
		  .done(function(data) {
		  	$('.modal-body').html(data);
		});
	})

});

//Rotator redirect type
$(document).on('toggle', '#redirect_type_radio input:radio', function() {
	$(this).radio("check");
    var redirect_campaign = $(this).parents().eq(2).find('#redirect_campaign_select');
    var redirect_url = $(this).parents().eq(2).find('#redirect_url_input');

    if ($(this).val() == 'campaign') {
    	redirect_campaign.show();
    	redirect_url.hide();
    } else if ($(this).val() == 'url') {
    	redirect_url.show();
    	redirect_campaign.hide();
    }
});

//Rotator defaults type
$(document).on('toggle', '#defaults_type_radio input:radio', function() {
	$(this).radio("check");
    var redirect_campaign = $('#default_campaign_select');
    var redirect_url = $('#default_url_input');

    if ($(this).val() == 'campaign') {
    	redirect_campaign.show();
    	redirect_url.hide();
    } else if ($(this).val() == 'url') {
    	redirect_url.show();
    	redirect_campaign.hide();
    }
});

//Add more rotator rules
$(document).on('click', '#add_more_criteria', function() {
		var id;
		var loading = $(this).parent().find('img');
		var container = $(this).parents().eq(2).find('#criteria_container');
		loading.show();
		//$('#addmore_criteria_loading').show();
		$.post("/tracking202/ajax/rotator.php", {add_more_criteria: 1})
		  .done(function(data) {
		  	var html = $(data);
		  	var select = html.find('#tag');
		  	select_id = Math.round(Math.random()*1000);
		  	id = 'tag_input_' + select_id;
		  	select.attr('id', id);

		  	container.append(html);
		  	loading.hide();
		  	rotator_tags_autocomplete(id, 'country');
		}); 
});

//Remove rule
$(document).on('click', '#remove_rule', function() {
	$(this).parents().eq(2).remove();
});

//Remove criteria
$(document).on('click', '#remove_criteria', function() {
	$(this).parents().eq(2).remove();
});

//Rotator details modal (in report)
$(document).on('click', '#rule_details', function(e) {
	var id = $(this).data('id');

	$.post("/tracking202/ajax/rotator.php", {rule_details: 1, rule_id: id})
		.done(function(data) {
		  	$('.modal-body').html(data);
	});
});

//On rule type change, generate new autocomplate
$(document).on('change', 'select[name=rule_type]', function() {
	var val = $(this).val();
	var parent = $(this).parent().parent();
	var select = parent.find('.value_select');
	var select_id = select.attr('id');
	switch(val) {
		case 'country':
			select.tokenfield('destroy');
			select.attr('placeholder', 'Type in country and hit Enter');
			rotator_tags_autocomplete(select_id, 'country');
		break;

	  	case 'region':
	  		select.tokenfield('destroy');
			select.attr('placeholder', 'Type in state/region and hit Enter');
			rotator_tags_autocomplete(select_id, 'region');
	  	break;

	  	case 'city':
			select.tokenfield('destroy');
			select.attr('placeholder', 'Type in city and hit Enter');
			rotator_tags_autocomplete(select_id, 'city');
	  	break;

	  	case 'isp':
			select.tokenfield('destroy');
			select.attr('placeholder', 'Type in ISP/Carrier and hit Enter');
			rotator_tags_autocomplete(select_id, 'isp');
	  	break;

	  	case 'ip':
	  		select.tokenfield('destroy');
			select.attr('placeholder', 'Type in IP address and hit Enter');
			rotator_tags_autocomplete_ip(select_id);
	  	break;

	  	case 'browser':
	  		select.tokenfield('destroy');
			select.attr('placeholder', 'Type in browser and hit Enter');
			rotator_tags_autocomplete(select_id, 'browser');
	  	break;

	  	case 'platform':
	  		select.tokenfield('destroy');
			select.attr('placeholder', 'Type in OS and hit Enter');
			rotator_tags_autocomplete(select_id, 'platform');
	  	break;

	  	case 'device':
	  		select.tokenfield('destroy');
	  		select.attr('placeholder', '');
			rotator_tags_autocomplete_devices(select_id);
	  	break;
	}
});

// Load affiliate networks
function load_aff_network_id(aff_network_id){
	var element = $("#aff_network_id_div");
		$.post("/tracking202/ajax/aff_networks.php", {aff_network_id: aff_network_id})
		  .done(function(data) {
		  	$('#aff_network_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load rotators
function load_rotator_id(rotator_id){
	var element = $("#rotator_id_div");
	$('#rotator_id_div_loading').show();
		$.post("/tracking202/ajax/rotator.php", {get_rotators:1, rotator_id: rotator_id})
		  .done(function(data) {
		  	$('#rotator_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load affiliate campaigns
function load_aff_campaign_id(aff_network_id, aff_campaign_id){
	var element = $("#aff_campaign_id_div");
		$('#aff_campaign_id').hide();
		$('#aff_campaign_id_div_loading').show();

		$.post("/tracking202/ajax/aff_campaigns.php", {aff_network_id: aff_network_id, aff_campaign_id: aff_campaign_id})
		  .done(function(data) {
		  	$('#aff_campaign_id_div_loading').hide();
		  	element.html(data);
		});
}

//Load landing pages
function load_landing_page(aff_campaign_id, landing_page_id, type) {
	var element = $("#landing_page_div");
    
		$('#landing_page_div_loading').show();

		$.post("/tracking202/ajax/landing_pages.php", {aff_campaign_id: aff_campaign_id, landing_page_id: landing_page_id, type: type})
		  .done(function(data) {
		  	$('#landing_page_div_loading').hide();
		  	element.html(data);
		});
}

// Load countries
function load_country_id(country_id){
	var element = $("#country_id_div");
		$.post("/tracking202/ajax/countries.php", {country_id: country_id})
		  .done(function(data) {
		  	$('#country_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load regions
function load_region_id(region_id){
	var element = $("#region_id_div");
		$.post("/tracking202/ajax/regions.php", {region_id: region_id})
		  .done(function(data) {
		  	$('#region_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load isp's/carriers
function load_isp_id(isp_id){
	var element = $("#isp_id_div");
		$.post("/tracking202/ajax/isp.php", {isp_id: isp_id})
		  .done(function(data) {
		  	$('#isp_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load device types
function load_device_id(device_id){
	var element = $("#device_id_div");
		$.post("/tracking202/ajax/device_type.php", {device_id: device_id})
		  .done(function(data) {
		  	$('#device_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load browser types
function load_browser_id(browser_id){
	var element = $("#browser_id_div");
		$.post("/tracking202/ajax/browser.php", {browser_id: browser_id})
		  .done(function(data) {
		  	$('#browser_id_div_loading').hide();
		  	element.html(data);
		});
}

// Load platform types
function load_platform_id(platform_id){
	var element = $("#platform_id_div");
		$.post("/tracking202/ajax/platform.php", {platform_id: platform_id})
		  .done(function(data) {
		  	$('#platform_id_div_loading').hide();
		  	element.html(data);
		});
}

//Generate simple landing page trackin links
function generate_simple_lp_tracking_links() {
	var element = $("#tracking-links");

	var spinner_html = '<center><img id="get_code_loading" src="/202-img/loader-small.gif"/></center>'
	element.css("opacity", "0.5");
	element.html(spinner_html);

	$.post("/tracking202/ajax/get_landing_code.php", $("#tracking_form").serialize(true))
		  .done(function(data) {
		  	element.css("opacity", "1");
		  	element.html(data);
		});
}

//Remove offer on ADV LP
function remove_new_campaign(counter) {
	$("#area_"+counter).remove();
}

//Load more offers on adv lp code page
function load_new_aff_campaign() {
	var counter = $("#counter").val();
	counter++;

	var element = $("#load_aff_campaign_"+counter);
	$("#counter").val(counter);

	$("#load_aff_campaign_"+counter+"_loading").show();

	$.post("/tracking202/ajax/adv_landing_pages.php", $("#tracking_form").serialize(true))
		.done(function(data) {
			$("#load_aff_campaign_"+counter+"_loading").hide();
			element.html(data);
		});
}

//Generate advanced landing page trackin links
function generate_adv_lp_tracking_links() {
	var element = $("#tracking-links");
	var spinner_html = '<center><img id="get_code_loading" src="/202-img/loader-small.gif"/></center>'
	element.css("opacity", "0.5");
	element.html(spinner_html);
	$.post("/tracking202/ajax/get_adv_landing_code.php", $("#tracking_form").serialize(true))
		  .done(function(data) {
		  	element.css("opacity", "1");
		  	element.html(data);
		});
}

//Load methods of promotion (direct/lp)
function load_method_of_promotion(method_of_promotion) {
	var element = $("#method_of_promotion_div");
    
		$('#method_of_promotion_div_loading').show();

		$.post("/tracking202/ajax/method_of_promotion.php", {method_of_promotion: method_of_promotion})
		  .done(function(data) {
		  	$('#method_of_promotion_div_loading').hide();
		  	element.html(data);
		});
}

//Load text ads
function load_text_ad_id(aff_campaign_id, text_ad_id) {
	var element = $("#text_ad_id_div");
    
		$('#text_ad_id_div_loading').show();

		$.post("/tracking202/ajax/text_ads.php", {aff_campaign_id: aff_campaign_id, text_ad_id: text_ad_id})
		  .done(function(data) {
		  	$('#text_ad_id_div_loading').hide();
		  	element.html(data);
		});
}

//Load adv lp text ads
function load_adv_text_ad_id(landing_page_id) {
	var element = $("#text_ad_id_div");
    
		$('#text_ad_id_div_loading').show();

		$.post("/tracking202/ajax/adv_text_ads.php", {landing_page_id: landing_page_id})
		  .done(function(data) {
		  	$('#text_ad_id_div_loading').hide();
		  	element.html(data);
		});
}

//Load text ad preview
function load_ad_preview(text_ad_id) {
	var element = $("#ad_preview_div");
    
		$('#ad_preview_div_loading').show();

		$.post("/tracking202/ajax/ad_preview.php", {text_ad_id: text_ad_id})
		  .done(function(data) {
		  	$('#ad_preview_div_loading').hide();
		  	element.html(data);
		});
}

//Load ppc networks
function load_ppc_network_id(ppc_network_id) {
	var element = $("#ppc_network_id_div");
    
		$('#ppc_network_id_div_loading').show();

		$.post("/tracking202/ajax/ppc_networks.php", {ppc_network_id: ppc_network_id})
		  .done(function(data) {
		  	$('#ppc_network_id_div_loading').hide();
		  	element.html(data);
		});
}

//Load ppc networks
function load_ppc_account_id(ppc_network_id, ppc_account_id) {
	var element = $("#ppc_account_id_div");
    
		$('#ppc_account_id_div_loading').show();

		$.post("/tracking202/ajax/ppc_accounts.php", {ppc_network_id: ppc_network_id, ppc_account_id: ppc_account_id})
		  .done(function(data) {
		  	$('#ppc_account_id_div_loading').hide();
		  	element.html(data);
		});
}

function tempLoadMethodOfPromotion(select) {
	console.log(select.value);
	if (select.value == 'directlink') {
		if ($('#aff_campaign_id').val() > 0) { 
		   	load_text_ad_id( $('#aff_campaign_id').val());
		}
			load_landing_page( 0, 0, select.value);

	} else if(select.value == 'landingpage') {
		load_landing_page( $('#aff_campaign_id').val(), 0, select.value);
		if ($('#landing_page_id').val() >= 0) {
			load_text_ad_id( $('#aff_campaign_id').val());
		}
	}
}	

//Get Links function
function getTrackingLinks() { 
  	var element = $("#tracking-links");
	var spinner_html = '<center><img id="get_code_loading" src="/202-img/loader-small.gif"/></center>'
	element.css("opacity", "0.5");
	element.html(spinner_html);

	$.post("/tracking202/ajax/generate_tracking_link.php", $("#tracking_form").serialize(true))
		  .done(function(data) {
		  	element.css("opacity", "1");
		  	element.html(data);
		});
}

// Confirms alert
function confirmAlert(text){
	var c = confirm(text);
	if(c == false) {
	    event.preventDefault();
	}
}

function pixel_data_changed(trackingDomain) {
		var pixel_code = '<img height="1" width="1" border="0" style="display: none;" src="{{0}}://' + trackingDomain + '/tracking202/static/gpx.php?amount={{1}}" />';
		var pixel_code_2 = '<img height="1" width="1" border="0" style="display: none;" src="{{0}}://' + trackingDomain + '/tracking202/static/gpx.php?amount={{1}}&cid={{2}}" />';
		
		var postback_code = '{{0}}://' + trackingDomain + '/tracking202/static/gpb.php?amount={{1}}&subid=';
		var postback_code_2 = '{{0}}://' + trackingDomain + '/tracking202/static/gpb.php?amount={{1}}&cid={{2}}&subid=';
		
		var universal_pixel_code = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="{{0}}://' + trackingDomain + '/tracking202/static/upx.php?amount={{1}}" />';
		var universal_pixel_code_2 = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="=" scrolling="no" src="{{0}}://' + trackingDomain + '/tracking202/static/upx.php?amount={{1}}&cid={{2}}" />';

		var universal_pixel_code_js = '<script>\n var vars202={amount:"",cid:""};(function(d, s) {\n 	var js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {\n 		if (d.getElementById(id)) {return;}\n 		if202 = d.createElement("iframe");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = "no";if202.noResize = true;\n 		upxf.parentNode.insertBefore(if202, upxf);\n 	};\n 	load("{{0}}://' + trackingDomain + '/tracking202/static/upx.php?amount="+vars202[\'amount\']+"&cid="+vars202[\'cid\'], "upxif");\n }(document, "script"));</\script>\n<noscript>\n 	<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="{{0}}://' + trackingDomain + '/tracking202/static/upx.php?amount={{1}}&cid= seamless></iframe>\n</noscript>';

		var secureTypeValue = $("input[name=secure_type]:checked").val();

		var http_val = 'http';
		if(secureTypeValue == 1) {
			var http_val = 'https';
		}
		
		var amount_value = $('#amount_value').val();
		var campaign_id_value = '';
		campaign_id_value = $('#aff_campaign_id').val();
		
		$('#unsecure_pixel').val(pixel_code.replace('{{0}}',http_val).replace('{{1}}',amount_value));
		$('#unsecure_pixel_2').val(pixel_code_2.replace('{{0}}',http_val).replace('{{1}}',amount_value).replace('{{2}}',campaign_id_value));
		$('#unsecure_postback').val(postback_code.replace('{{0}}',http_val).replace('{{1}}',amount_value));
		$('#unsecure_postback_2').val(postback_code_2.replace('{{0}}',http_val).replace('{{1}}',amount_value).replace('{{2}}',campaign_id_value));

		$('#unsecure_universal_pixel').val(universal_pixel_code.replace('{{0}}',http_val).replace('{{1}}',amount_value));
		$('#unsecure_universal_pixel_2').val(universal_pixel_code_2.replace('{{0}}',http_val).replace('{{1}}',amount_value).replace('{{2}}',campaign_id_value));
}

function loadContent(page, offset, order){
	var element = $("#m-content");
	element.css("opacity", "0.5");
	var chartWidth = element.width();

	$.post(page, { offset: offset, order: order, chartWidth:chartWidth})
		  .done(function(data) {
		  	element.html(data);
		  	element.css("opacity", "1");
		});
}

function createCookie(name,value,days) {
	if (days) {
		var date = new Date();
		date.setTime(date.getTime()+(days*24*60*60*1000));
		var expires = "; expires="+date.toGMTString();
	}
	else var expires = "";
	document.cookie = name+"="+value+expires+"; path=/";

}

function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(';');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==' ') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
	}
	return null;

}

function eraseCookie(name) {
	createCookie(name,"",-1);
}

function set_user_prefs(page, offset) { 
	var element = $('#m-content');
	element.css("opacity", "0.5");

	$.post("/tracking202/ajax/set_user_prefs.php", $("#user_prefs").serialize(true))
		.done(function(data) {
		  	loadContent(page, offset);
		  	element.css("opacity", "1"); 
		});
}

function unset_user_pref_time_predefined() {
	$('#user_pref_time_predefined').val($("#user_pref_time_predefined option:first").val()); 
}

function runSpy() {

	$.get("/tracking202/ajax/click_history.php", {spy: '1'})
		.done(function(data) {
			$("#m-content").html(data); 
		  	goSpy();
		});
}

function goSpy() {
	setTimeout(appearSpy,1);  
} 

function appearSpy(){
    $('.new-click').fadeIn(1000);
}

function rotator_tags_autocomplete(selector, type){
	var elt = $('#' + selector);

	var tags = new Bloodhound({
	  remote: '/tracking202/ajax/rotator.php?autocomplete=true&type='+ type +'&query=%QUERY',
	  datumTokenizer: function(d) { 
	      return Bloodhound.tokenizers.whitespace(d.val); 
	  },
	  queryTokenizer: Bloodhound.tokenizers.whitespace,
	  limit: 10
	});

	tags.initialize();

  	elt.on('tokenfield:initialize', function (e) {
	    elt.parent().find('.token').remove();
	})

  	.tokenfield({
	  createTokensOnBlur: true,
	  typeahead: {
	  	displayKey: 'label',
	    source: tags.ttAdapter()
	  }
	});
}

function rotator_tags_autocomplete_ip(selector){
	var elt = $('#' + selector);

  	elt.tokenfield({
	  createTokensOnBlur: true,
	});
}

function rotator_tags_autocomplete_devices(selector){
	var elt = $('#' + selector);

	var tags = new Bloodhound({
	  local: [{value: 'bot', label: 'Bot'}, {value: 'mobile', label: 'Mobile'}, {value: 'tablet', label: 'Tablet'} , {value: 'desktop', label: 'Desktop'}],
	  datumTokenizer: function(d) {
	    return Bloodhound.tokenizers.whitespace(d.value); 
	  },
	  queryTokenizer: Bloodhound.tokenizers.whitespace    
	});

	tags.initialize();
	
	elt.tokenfield({
	  createTokensOnBlur: true,
	  typeahead: {
	  	displayKey: 'label',
	    source: tags.ttAdapter()
	  }
	});

	elt.tokenfield('setTokens', [{value: 'bot', label: 'Bot'}, {value: 'mobile', label: 'Mobile'}, {value: 'tablet', label: 'Tablet'} , {value: 'desktop', label: 'Desktop'}]);

}

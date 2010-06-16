

function load_aff_network_id(aff_network_id) {

    if($('aff_network_id_div_loading')) {   $('aff_network_id_div_loading').style.display='inline'; }
    if($('aff_network_id_div')) {           $('aff_network_id_div').style.display='none';           }
	if($('text_ad_id_div')) {                    $('text_ad_id_div').style.display='none';                    }
    if($('ad_preview_div')) {               $('ad_preview_div').style.display='none';               }
    
    if($('aff_network_id_div')) { 
     
        new Ajax.Updater('aff_network_id_div', '/tracking202/ajax/aff_networks.php', 
        { 
			parameters:{ aff_network_id: aff_network_id}, 
            onSuccess: function() { 
                $('aff_network_id_div_loading').style.display='none'; 
                $('aff_network_id_div').style.display='inline';
                
            }
        });
    } 
									  
}

function load_aff_campaign_id(aff_network_id, aff_campaign_id) {

    if($('aff_campaign_id_div_loading')) {      $('aff_campaign_id_div_loading').style.display='inline';  }
    if($('aff_campaign_id_div'))  {             $('aff_campaign_id_div').style.display='none';            }
	if($('text_ad_id_div')) {                        $('text_ad_id_div').style.display='none';                      }
    if($('ad_preview_div')) {                   $('ad_preview_div').style.display='none';                 }

    if($('aff_campaign_id_div')) { 
    
		new Ajax.Updater('aff_campaign_id_div', '/tracking202/ajax/aff_campaigns.php', 
        { 
            parameters:{aff_network_id: aff_network_id,
                        aff_campaign_id: aff_campaign_id}, 
            onSuccess: function() { 
                $('aff_campaign_id_div_loading').style.display='none'; 
                $('aff_campaign_id_div').style.display='inline';
                
                /*update the method of promotion*/
                if($('method_of_promotion').options[$('method_of_promotion').selectedIndex].value == 'landingpage') {
                    load_landing_page(0, 0, 'landingpage');    
                }
                
				if ($('text_ad_id').selectedIndex != '0') {
					load_text_ad_id(aff_campaign_id, 0);
				}   
            }
        });
    }                            
}

function load_text_ad_id(aff_campaign_id, text_ad_id) {   

	if($('text_ad_id_div_loading')) {       $('text_ad_id_div_loading').style.display='inline';    }
	if($('text_ad_id_div')) {               $('text_ad_id_div').style.display='none';              }
    if($('ad_preview_div')) {          $('ad_preview_div').style.display='none';         }
//    if($('landing_page_div')) {        $('landing_page_div').style.display='none';       }
	
	if($('text_ad_id_div')) { 
    
		new Ajax.Updater('text_ad_id_div', '/tracking202/ajax/text_ads.php', 
        { 
            parameters:{aff_campaign_id: aff_campaign_id,
						text_ad_id: text_ad_id}, 
            onSuccess: function() { 
				$('text_ad_id_div_loading').style.display='none'; 
				$('text_ad_id_div').style.display='inline';

            }
        });
    }
}

function load_ad_preview(text_ad_id) {

    if($('ad_preview_div_loading')) {    $('ad_preview_div_loading').style.display='inline';  }
    if($('ad_preview_div')) {           $('ad_preview_div').style.display='none';             }

    if($('ad_preview_div')) {  
    
        new Ajax.Updater('ad_preview_div', '/tracking202/ajax/ad_preview.php', 
        { 
			parameters:{text_ad_id: text_ad_id}, 
            onSuccess: function() { 
                $('ad_preview_div_loading').style.display='none'; 
                $('ad_preview_div').style.display='inline';
            }
         });
    }

}

    
function load_method_of_promotion(method_of_promotion) {  
    
    if($('method_of_promotion_div_loading')) {     $('method_of_promotion_div_loading').style.display='inline'; }
    if($('method_of_promotion_div')) {             $('method_of_promotion_div').style.display='none';           }

    if($('method_of_promotion_div')) {
        new Ajax.Updater('method_of_promotion_div', '/tracking202/ajax/method_of_promotion.php',
        {
            parameters:{method_of_promotion: method_of_promotion},
            onSuccess: function() { 
                $('method_of_promotion_div_loading').style.display='none'; 
                $('method_of_promotion_div').style.display='inline';  
            }
        }); 
    }
    
}


function load_landing_page(aff_campaign_id, landing_page_id, type) {

    if($('landing_page_div_loading')) {     $('landing_page_div_loading').style.display='inline'; }
    if($('landing_page_div')) {             $('landing_page_div').style.display='none';           }
    
    if($('landing_page_div')) {  
        new Ajax.Updater('landing_page_div', '/tracking202/ajax/landing_pages.php', 
        {                     
            parameters:{aff_campaign_id: aff_campaign_id,
                        landing_page_id: landing_page_id,
                        type: type}, 
            onSuccess: function() { 
                $('landing_page_div_loading').style.display='none'; 
                $('landing_page_div').style.display='inline';
            }
        });
    }
}



function load_ppc_network_id(ppc_network_id) {

    if($('ppc_account_id_div_loading')) {     $('ppc_account_id_div_loading').style.display='inline';  }
    if($('ppc_account_id_div')) {             $('ppc_account_id_div').style.display='none';            }
    
    if($('ppc_network_id_div')) { 
        new Ajax.Updater('ppc_network_id_div', '/tracking202/ajax/ppc_networks.php', 
        { 
            parameters:{ppc_network_id: ppc_network_id}, 
            onSuccess: function() { 
                $('ppc_account_id_div_loading').style.display='none'; 
                $('ppc_account_id_div').style.display='inline';
            }
        });
    } 
                                    
}



function load_ppc_account_id(ppc_network_id, ppc_account_id) {

    if($('ppc_account_id_div_loading')) {   $('ppc_account_id_div_loading').style.display='inline';   }
    if($('ppc_account_id_div')) {           $('ppc_account_id_div').style.display='none';             }
    
    if($('ppc_account_id_div')) { 
        new Ajax.Updater('ppc_account_id_div', '/tracking202/ajax/ppc_accounts.php', 
        { 
            parameters:{ppc_network_id: ppc_network_id,
                        ppc_account_id: ppc_account_id}, 
            onSuccess: function() { 
                $('ppc_account_id_div_loading').style.display='none'; 
				$('ppc_account_id_div').style.display='inline';
            }
        });  
    }                          
}



function unset_user_pref_time_predefined() {
	$('user_pref_time_predefined').selectedIndex = 0;   
}




function set_user_prefs(page, offset) { 

	$('to_cal').style.display='none'; 
	$('from_cal').style.display='none';
	
	if($('s-status-loading')) {   $('s-status-loading').style.display='';       }
	if($('m-content')) {          $('m-content').className='transparent_class'; } 

	new Ajax.Updater('s-status', '/tracking202/ajax/set_user_prefs.php', {
	  
      parameters: $('user_prefs').serialize(true),
      onSuccess: function() {
         
         loadContent(page, offset);     
         
      }
    });
}



function loadContent(page, offset, order) {
     if($('s-status-loading')) {   $('s-status-loading').style.display='';       }
     if($('m-content')) {          $('m-content').className='transparent_class'; } 
	 
	 var chartWidth = Element.getDimensions('s-top').width;   
	 
	 new Ajax.Updater('m-content',page, {
		parameters: { offset: offset, order: order, chartWidth:chartWidth},
        onSuccess: function() {
            if($('s-status-loading')) {   $('s-status-loading').style.display='none';   }
            if($('m-content')) {          $('m-content').className=''; }    
        }
     });
}

function runSpy() {

     new Ajax.Updater('m-content','/tracking202/ajax/click_history.php', {
        method: 'get',
        parameters: { spy: '1'},
        onSuccess: function() {
            goSpy();
            if($('s-status-loading')) {   $('s-status-loading').style.display='none';       }    
        }
     });
 
}

function goSpy() {
	setTimeout(appearSpy,1);  
} 

function appearSpy(){
    new Effect.Appear('m-newclicks');
}  




 


function load_adv_text_ad_id(landing_page_id) {    

    if($('text_ad_id_div_loading')) {       $('text_ad_id_div_loading').style.display='inline';    }
    if($('text_ad_id_div')) {               $('text_ad_id_div').style.display='none';              }
    if($('ad_preview_div')) {          $('ad_preview_div').style.display='none';         }

	if($('text_ad_id_div')) { 
    
		new Ajax.Updater('text_ad_id_div', '/tracking202/ajax/adv_text_ads.php', 
        { 
            parameters:{	landing_page_id: landing_page_id}, 
            onSuccess: function() { 
				$('text_ad_id_div_loading').style.display='none'; 
				$('text_ad_id_div').style.display='inline';
				
            }
        });
    }

}


function tempLoadMethodOfPromotion(select) { 
  	 if ($('aff_campaign_id')) { 
	   	load_landing_page( $('aff_campaign_id').value, 0, select.value);
	} else {
		load_landing_page( 0, 0, select.value);
	} 
}
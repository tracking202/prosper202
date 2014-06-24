

function setOffersPref() { 

	if($('offers_loading')) {   $('offers_loading').style.display='';       }
	if($('m-content')) {          $('m-content').className='transparent_class'; } 

	new Ajax.Request('/offers202/ajax/setPrefs.php', {
	  
      parameters: $('offers_form').serialize(true),
      onSuccess: function() {
         
       	getOffers();
      }
    });
}


function setOffersLimitPref() { 

	if($('offers_loading')) {   $('offers_loading').style.display='';       }
	if($('m-content')) {          $('m-content').className='transparent_class'; } 

	new Ajax.Request('/offers202/ajax/setPrefs.php', {
	  
      parameters: $('offers_limit_form').serialize(true),
      onSuccess: function() {
         
       	getOffers();
      }
    });
}


function getOffers(page, order, by) { 
	if($('offers_loading')) {   $('offers_loading').style.display='';       }
	if($('m-content')) {          $('m-content').className='transparent_class'; } 

	new Ajax.Updater('m-content', '/offers202/ajax/getOffers.php', {
	  
      parameters: { page: page, order:order, by:by },
      onSuccess: function() {
         if($('m-content')) {          $('m-content').className=''; }
         if($('offers_loading')) {   $('offers_loading').style.display='none';       }
      }
    });
}


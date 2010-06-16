function getStats(page, order, by) { 
	
	if($('s-status-loading')) {   $('s-status-loading').style.display='';       }
     	if($('m-content')) {          $('m-content').className='transparent_class'; } 
	 
	new Ajax.Updater('m-content', '/stats202/ajax/getStats.php', {
	  
      parameters: { page:page, order:order, by:by },
      onSuccess: function() {
         	if($('s-status-loading')) {   $('s-status-loading').style.display='none';   }
            if($('m-content')) {          $('m-content').className=''; }    
      }
    });
}


function getSubids(page, order, by) { 
	
	if($('s-status-loading')) {   $('s-status-loading').style.display='';       }
     	if($('m-content')) {          $('m-content').className='transparent_class'; } 
	
	new Ajax.Updater('m-content', '/stats202/ajax/getSubids.php', {
	  
      parameters: { page:page, order:order, by:by },
      onSuccess: function() {
         	if($('s-status-loading')) {   $('s-status-loading').style.display='none';   }
            if($('m-content')) {          $('m-content').className=''; }    
      }
    });
}




function getOfferStats(page, order, by) { 
	
	if($('s-status-loading')) {   $('s-status-loading').style.display='';       }
     	if($('m-content')) {          $('m-content').className='transparent_class'; } 
	
	new Ajax.Updater('m-content', '/stats202/ajax/getOfferStats.php', {
	  
      parameters: { page:page, order:order, by:by },
      onSuccess: function() {
         if($('s-status-loading')) {   $('s-status-loading').style.display='none';   }
         if($('m-content')) {          $('m-content').className=''; }    
      }
    });
}

function addDownloadRequest() { 
	
	//check to see if the stats are already downloading:
	if ($('downloadComplete').value==0) {
		alert('Stats202 is already downloading your earnings, please wait for it to complete.'); return false;
	}
	
	//make status screen transparent to show it's now updating
	if($('stats202-download-status')) {
		$('stats202-download-status').className='transparent_class';
	}
	
	//request download
	new Ajax.Request('/stats202/ajax/addDownloadRequest.php', {
		onSuccess: function() { 
			//mark input field that download is no longer complete
			$('downloadComplete').value = 0;
			
			//download the status
			downloadStatus();
		}
	}); 
	
	
}

function downloadStatus() { 
	//if no downloadComplete input field has been found, just go ahead and start the downloadStatus ajax page
	if (!$('downloadComplete')) {
		new Ajax.Updater('stats202-download-status', '/stats202/ajax/downloadStatus.php', {
			onSuccess: function() {
				if($('stats202-download-status')) {
					$('stats202-download-status').className='';
				}
			}
		});
		return true;
	}
	
	//only download the status if the download is not alredy complete
	if ($('downloadComplete').value == 0) {
		new Ajax.Updater('stats202-download-status', '/stats202/ajax/downloadStatus.php', {
			onSuccess: function() {
				if($('stats202-download-status')) {
					$('stats202-download-status').className='';
				}
			}
		});
		return false;
	}
}



//this lights up a row
function lightUpRow(element) { 
	element.style.background = '#FFFFD9';	
}
 
//this dims down a row
function dimDownRow(element, color) { 

	if (!color) { color = 'white'; } 

	element.style.background = color; 
}
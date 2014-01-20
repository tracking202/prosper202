function submitFeedback() { 
    if ($('feedback_suggestion').value == '') { 
        $('feedback_denied').style.display = 'block';
        Effect.Fade('feedback_denied', {delay: 2});
    } else {
        $('feedback_button_loading').style.display='inline';
        new Ajax.Updater('feedback_status', '/_ajax/send_feedback', {
          parameters: $('feedback_form').serialize(true),
          onSuccess: function() {
             $('feedback_button_loading').style.display='none';
             $('feedback_denied').display = 'none'; 
             $('feedback_accepted').style.display='block';
             Effect.Fade('feedback_accepted', {delay: 2});
          }
        });
    }
    return false;
}


function set_timeframe(page) { 
  
  $('set_timeframe_loading').style.display='inline';
  new Ajax.Updater('set_timeframe_status','/_ajax/set_timeframe', {
      
      parameters: $('set_timeframe').serialize(true),
      onSuccess: function() {
         
		  //after setting the time, this will now call the data to display
           new Ajax.Updater('spy','/_ajax/'+page, {
            onSuccess: function() { 
               $('set_timeframe_loading').style.display='none';
            }
         });

      }
    });
    
    return false;
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


function confirmSubmit(message)
{
var msg = message ? message : "Are you sure you wish to continue?";
var agree=confirm(msg);
if (agree)
	return true ;
else
	return false ;
}

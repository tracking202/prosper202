<?php 
header('Content-type: application/javascript');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT');
header("Pragma: no-cache");
include_once(substr(dirname( __FILE__ ), 0,-7) . '/202-config/functions.php');
?>
$(document).ready(function() {

	$("#get-logs").click(function() {
		var element = $("#logs_table");
		element.css("opacity", "0.5");
		$.post("<?php echo get_absolute_url();?>202-account/ajax/conversion_logs.php", $('#logs_from').serialize(true))
		  .done(function(data) {
		  	element.css("opacity", "1");
		  	element.html(data);
		});
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

	$('#cb_status').click(function(){
    	$.post("<?php echo get_absolute_url();?>202-account/api-integrations.php/?cb_status=1", function(data) {
			$( "#cb_verified" ).hide().html(data).fadeIn("slow");
		});
	});

    $("#erase_clicks_date").datepicker({dateFormat: 'dd-mm-yy'});

	$('#erase_clicks_form').submit(function(event) {
	    // check validation
	    if ($("#erase_clicks_date").val() == "") {
	        alert("Please pick date for clicks data!");
	        event.preventDefault();
	    } else {
	    	var c = confirm("Are You Sure You Want To Delete All Your Clicks?");
	    	if(c == false) {
	    		event.preventDefault();
	    	}
	    }
	});

	$('input[name=maxmind-isp]').on("change.radiocheck", function(){
        var checkbox = $(this);
        $.post("<?php echo get_absolute_url();?>202-account/administration.php", { maxmind: checkbox.val()})
		  .done(function(data) {
		  	if(data){
		  		$('#on-label').removeClass("checked");
		  		checkbox.attr("checked", false);
		  		$('input[id=off]').attr("checked", true);
		  		$('#off-label').addClass("checked");
		  	}
		});
    });

    $('#generate-new-api-key').click(function(){
    	var d = new Date();
    	var date = (d.getMonth()+1)+"/"+d.getDate()+"/"+d.getFullYear();
    	var key = generateApiKey();

    	$.post("<?php echo get_absolute_url();?>202-account/account.php", { add_rest_api_key: true, rest_api_key: key})
		  .done(function(data) {
		  	$("#no-api-keys").remove();
		  	$("#rest-api-keys").append('<li id="'+key+'"><span class="infotext">Date created: '+date+'</span> - <code>'+key+'</code> <a id="delete-rest-key" class="close fui-cross"></a></li>');
		});
	});

});

$(document).on('closed.bs.alert', '#prosper-alerts', function() {
	var id = $(this).data("alertid");
    $.post("<?php echo get_absolute_url();?>202-account/ajax/alert-seen.php", { prosper_alert_id:id })
});

$(document).on('click', '#delete-rest-key', function() {
    var key = $(this).parent().attr("id");
    
    $.post("<?php echo get_absolute_url();?>202-account/account.php", { remove_rest_api_key: true, rest_api_key: key})
		.done(function(data) {
			if($('#rest-api-keys li').size() < 2){
		    	$("#"+key).remove();
		    	$("#rest-api-keys").append('<li id="no-api-keys">No API key\'s generated</li>');
		    } else {
		    	$("#"+key).remove();
		    }
	});
});

function unset_user_pref_time_predefined() {
	$('#user_pref_time_predefined').val($("#user_pref_time_predefined option:first").val()); 
}

function generateApiKey() {

  	var limit = 32;
    var key = '';
  
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  
    var list = chars.split('');
    var len = list.length, i = 0;
    
    do {
    
      i++;
    
      var index = Math.floor(Math.random() * len);
      
      key += list[index];
    
    } while(i < limit);
    
    return key;
}


/**
 * Function : dump()
 * Arguments: The data - array,hash(associative array),object
 *    The level - OPTIONAL
 * Returns  : The textual representation of the array.
 * This function was inspired by the print_r function of PHP.
 * This will accept some data as the argument and return a
 * text that will be a more readable version of the
 * array/hash/object that is given.
 * Docs: http://www.openjs.com/scripts/others/dump_function_php_print_r.php
 */
function dump(arr,level) {
	var dumped_text = "";
	if(!level) level = 0;
	
	//The padding given at the beginning of the line.
	var level_padding = "";
	for(var j=0;j<level+1;j++) level_padding += "    ";
	
	if(typeof(arr) == 'object') { //Array/Hashes/Objects 
		for(var item in arr) {
			var value = arr[item];
			
			if(typeof(value) == 'object') { //If it is an array,
				dumped_text += level_padding + "'" + item + "' ...\n";
				dumped_text += dump(value,level+1);
			} else {
				dumped_text += level_padding + "'" + item + "' => \"" + value + "\"\n";
			}
		}
	} else { //Stings/Chars/Numbers etc.
		dumped_text = "===>"+arr+"<===("+typeof(arr)+")";
	}
	return dumped_text;
}
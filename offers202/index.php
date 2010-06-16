<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//before loading the offers202 page, check to make sure this users api key is valid, 
//if they do not have one, they will have to generated one 
AUTH::require_valid_api_key();

template_top('Offers202'); 

include_once('top.php');
include_once('form.php');

template_bottom();
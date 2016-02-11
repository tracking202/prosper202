<?php

include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php');    

AUTH::require_user();

template_top('API Key Required');  ?> 


<div class="big-alert">

	The application you are trying to use requires your server to have PHP SAFE MODE turned OFF. <br/>
	You will have to contact your web host and have them turn of safe mode in order to use this application.

</div>

        
<?php template_bottom();
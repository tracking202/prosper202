<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

template_top('API Key Required');  ?> 


<div class="big-alert">

	The application you are trying to use requires a valid Stats202 App Key. <br/>
	You may enter in your Application Key by visiting the <a href="/202-account/account.php">My Account</a> tab in Prosper202.

</div>

        
<? template_bottom();
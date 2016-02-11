<?php
        // 
        // SLACK MESSAGE (the message you'll see in your Slack channel)
        //$message = "New Campaign Created by Nana: *<http://localhost/tracking202/setup/aff_campaigns.php|Auto Insurance 123>*";
    //    $message = "Campaign Edited by Nana: *<http://localhost/tracking202/setup/aff_campaigns.php|Auto Insurance 123>*";
        //$message = "Campaign Deleted by Nana: *<http://localhost/tracking202/setup/aff_campaigns.php|Auto Insurance 123>*";
//$message = "New Traffic Source Created by Nana: *<http://localhost/tracking202/setup/ppc_accounts.php|Google Adwords>*";
$message = "Traffic Source Edited by Nana: *<http://localhost/tracking202/setup/ppc_accounts.php|Google Adwords>*";
        // Array of data posted Slack
        $fields = array(
            
            'username' => "Prosper202 Traffic Source Edited",
            'icon_url' => "https://pbs.twimg.com/profile_images/720442893/202_icon.png",
            'text' => $message
        );  

        // 'payload' parameter is required by Slack
        // $fields array must be json encoded
        $payload = "payload=" . json_encode($fields);

        // URL we need to post data to (Given to you by Slack when creating an Incoming Webhook integration)
        $url = 'https://hooks.slack.com/services/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';

        // Start CURL connection
        $ch = curl_init();

        // Set the:
        // - URL
        // - Number of POST variables
        // - Data
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, count($fields));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $payload);

        // Execute post to Slack integration
        $result = curl_exec($ch);

        // Close CURL connection
        curl_close($ch);
    
<?php
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/class-dataengine.php');

set_time_limit(0);

/*
 RollingCurl code Authored by Josh Fraser (www.joshfraser.com)
 */

$query = "SELECT * FROM 202_dataengine_job WHERE processed = '0' and processing != '1'";
$result = $db->query($query);
$row = $result->fetch_assoc();

if ($result->num_rows) {
    if (! $row['processing']) {
        $snippet = "AND 2c.user_id = " . 1;
        
        $mysql['click_time_from'] = $db->real_escape_string($row['time_from']);
        $mysql['click_time_to'] = $db->real_escape_string($row['time_to']);
        $sql = "UPDATE 202_dataengine_job SET processing = '1' WHERE time_from ='" . $mysql['click_time_from'] . "' AND time_to = '" . $mysql['click_time_to'] . "'";
        $db->query($sql);
        
        for ($i = $mysql['click_time_from']; $i < $mysql['click_time_to']; $i = $i + 3599) {
            $nextval = $i + 3599;
            $urls[] = 'http://' . getTrackingDomain() .get_absolute_url(). '202-cronjobs/dej.php?s=' . $i . '&e=' . $nextval;
        }

      
        
       $callback = null;
       $custom_options = null;
        
            // make sure the rolling window isn't greater than the # of urls
            $rolling_window = 7;
            $rolling_window = (sizeof($urls) < $rolling_window) ? sizeof($urls) : $rolling_window;
        
            $master = curl_multi_init();
            $curl_arr = array();
        
            // add additional curl options here
            $std_options = array(CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5);
            $options = ($custom_options) ? ($std_options + $custom_options) : $std_options;
        
            // start the first batch of requests
            for ($i = 0; $i < $rolling_window; $i++) {
                $ch = curl_init();
                $options[CURLOPT_URL] = $urls[$i];
                curl_setopt_array($ch,$options);
                curl_multi_add_handle($master, $ch);
            }
        
            do {
                while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
                if($execrun != CURLM_OK)
                    break;
                // a request was just completed -- find out which one
                while($done = curl_multi_info_read($master)) {
                    $info = curl_getinfo($done['handle']);
                    if ($info['http_code'] == 200)  {
                        $output = curl_multi_getcontent($done['handle']);
        
                        // request successful.  process output using the callback function.
                      //  $callback($output);
        
                        // start a new request (it's important to do this before removing the old one)
                        $ch = curl_init();
                        $options[CURLOPT_URL] = $urls[$i++];  // increment i
                        curl_setopt_array($ch,$options);
                        curl_multi_add_handle($master, $ch);
        
                        // remove the curl handle that just completed
                        curl_multi_remove_handle($master, $done['handle']);
                    } else {
                        // request failed.  add error handling.
                    }
                }
            } while ($running);
        
            curl_multi_close($master);

        
        
        
        $sql = "UPDATE 202_dataengine_job SET processing = '0', processed = '1' WHERE time_from = '" . $mysql['click_time_from'] . "' AND time_to = '" . $mysql['click_time_to'] . "'";
        $db->query($sql);
    }
}
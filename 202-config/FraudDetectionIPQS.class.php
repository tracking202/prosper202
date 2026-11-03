<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', substr(__DIR__, 0, -10));
}
include_once (ROOT_PATH . '/202-interfaces/FraudDetectionInterface.php');

class FraudDetectionIPQS implements FraudDetectionInterface
{
    protected $key;
    protected $strictness;
    protected $ip;
    protected $user_agent;
    protected $language;
    
    public function __construct($config)
    {       
        $this->key = $config['key'];
        $this->strictness = 0; //$config['strictness'];
        $this->ip = $config['ip']; //$ip_address->address;
        $this->user_agent = $config['user_agent']; //$ip_address->address;
        $this->language = $config['language']; //$ip_address->address;
    }
    
    function verifyKey()
    {
        $result = json_decode((string) $this->get_IPQ_URL('https://www.ipqualityscore.com/api/json/account/' . rawurlencode((string) $this->key)), true);
        
        if($result !== null){
            if(isset($result['success']) && $result['success'] == true) {
                return true;
            }  else {
                throw new Exception($result['message']);
            }
        } else {
            throw new Exception('Invalid or unauthorized key. Please check the API key and try again.');
        }
    }

    function isFraud($ip)
    {
        // Encoded: libcurl refuses a URL with the spaces a user agent holds.
        $query = http_build_query([
            'user_agent' => (string) $this->user_agent,
            'fast' => 'true',
            'user_language' => (string) $this->language,
            'strictness' => $this->strictness,
            'allow_public_access_points' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
        $result = json_decode((string) $this->get_IPQ_URL('https://www.ipqualityscore.com/api/json/ip/'
            . rawurlencode((string) $this->key) . '/' . rawurlencode((string) $this->ip->address) . '?' . $query), true);
        if($result !== null){
            if(isset($result['fraud_score']) && $result['fraud_score'] >= 80) {
                return true;
            } else if(isset($result['fraud_score']) && $result['fraud_score'] < 80) {
                return false;
            } else {
                throw new Exception($result['message']);
            }
        } else {
            throw new Exception('No Response');
        }
    }
    
    function get_IPQ_URL($url) 
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_REFERER, "http://my.tracking202.com");
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}

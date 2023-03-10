<?php
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
        $result = json_decode($this->get_IPQ_URL(sprintf('https://www.ipqualityscore.com/api/json/account/%s', $this->key)), true);
        
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
        $result = json_decode($this->get_IPQ_URL(sprintf('https://www.ipqualityscore.com/api/json/ip/%s/%s?user_agent=%s&fast=true&user_language=%s;&strictness=%s&allow_public_access_points=true', $this->key, $this->ip->address, $this->user_agent, $this->language, $this->strictness)), true);
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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_REFERER, "http://my.tracking202.com");
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
}
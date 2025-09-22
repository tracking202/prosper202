<?php

require_once __DIR__ . '/class-indexes.php';

if (!function_exists('getIndexesInstance')) {
    function getIndexesInstance()
    {
        static $instance = null;

        if ($instance === null) {
            $instance = new INDEXES();
        }

        return $instance;
    }
}

if (!function_exists('get_country_id')) {
    function get_country_id($country_name, $country_code)
    {
        return getIndexesInstance()->get_country_id($country_name, $country_code);
    }
}

if (!function_exists('get_city_id')) {
    function get_city_id($city_name, $country_id)
    {
        return getIndexesInstance()->get_city_id($city_name, $country_id);
    }
}

if (!function_exists('get_isp_id')) {
    function get_isp_id($isp)
    {
        return getIndexesInstance()->get_isp_id($isp);
    }
}

if (!function_exists('get_ip_id')) {
    function get_ip_id($ip_address)
    {
        return INDEXES::get_ip_id($ip_address);
    }
}

if (!function_exists('get_site_domain_id')) {
    function get_site_domain_id($site_url)
    {
        return INDEXES::get_site_domain_id($site_url);
    }
}

if (!function_exists('get_site_url_id')) {
    function get_site_url_id($site_url)
    {
        return INDEXES::get_site_url_id($site_url);
    }
}

if (!function_exists('get_keyword_id')) {
    function get_keyword_id($keyword_name)
    {
        return INDEXES::get_keyword_id($keyword_name);
    }
}

if (!function_exists('get_c1_id')) {
    function get_c1_id($c1)
    {
        return getIndexesInstance()->get_c1_id($c1);
    }
}

if (!function_exists('get_c2_id')) {
    function get_c2_id($c2)
    {
        return getIndexesInstance()->get_c2_id($c2);
    }
}

if (!function_exists('get_c3_id')) {
    function get_c3_id($c3)
    {
        return getIndexesInstance()->get_c3_id($c3);
    }
}

if (!function_exists('get_c4_id')) {
    function get_c4_id($c4)
    {
        return getIndexesInstance()->get_c4_id($c4);
    }
}

if (!function_exists('get_browser_id')) {
    function get_browser_id($browser_name)
    {
        return INDEXES::get_browser_id($browser_name);
    }
}

if (!function_exists('get_platform_id')) {
    function get_platform_id($platform_name)
    {
        return INDEXES::get_platform_id($platform_name);
    }
}

if (!function_exists('get_device_id')) {
    function get_device_id($device_name)
    {
        return INDEXES::get_device_id($device_name);
    }
}

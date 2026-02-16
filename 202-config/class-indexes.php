<?php

if (class_exists('INDEXES')) {
    return;
}

class INDEXES
{

    // this returns the location_country_id, when a Country Code is given
    function get_country_id($country_name, $country_code)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        $mysql['country_code'] = $db->real_escape_string($country_code);
        $mysql['country_name'] = $db->real_escape_string($country_name);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("country-id" . $mysql['country_code'] . systemHash()));

            if ($getID) {
                $country_id = $getID;
            } else {
                $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
                $country_result = _mysqli_query($country_sql);
                $country_row = $country_result->fetch_assoc();
                if ($country_row['country_id']) {
                    // if this country_id already exists, return the country_id for it.
                    $country_id = $country_row['country_id'];
                    // add to memcached
                    $setID = setCache(md5("country-id" . $mysql['country_code'] . systemHash()), $country_id, $time);
                } else {
                    //insert country
                    $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                    $country_result = _mysqli_query($country_sql);
                    $country_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("country-id" . $mysql['country_code'] . systemHash()), $country_id, $time);
                }
            }
        } else {
            $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
            $country_result = _mysqli_query($country_sql);
            $country_row = $country_result->fetch_assoc();
            if ($country_row['country_id']) {
                // if this country_id already exists, return the country_id for it.
                $country_id = $country_row['country_id'];
            } else {
                //insert country
                $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                $country_result = _mysqli_query($country_sql);
                $country_id = $db->insert_id;
            }
        }

        //return the country_id
        return $country_id;
    }

    // this returns the location_city_id, when a City name is given
    function get_city_id($city_name, $country_id)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        $mysql['city_name'] = $db->real_escape_string($city_name);
        $mysql['country_id'] = $db->real_escape_string((string) $country_id);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("city-id" . $mysql['city_name'] . $mysql['country_id'] . systemHash()));

            if ($getID) {
                $city_id = $getID;
            } else {
                $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "' AND country_id='" . $mysql['country_id'] . "'";
                $city_result = _mysqli_query($city_sql);
                $city_row = $city_result->fetch_assoc();
                if ($city_row['city_id']) {
                    // if this city_id already exists, return the city_id for it.
                    $city_id = $city_row['city_id'];
                    // add to memcached
                    $setID = setCache(md5("city-id" . $mysql['city_name'] . $mysql['country_id'] . systemHash()), $city_id, $time);
                } else {
                    //insert city
                    $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', country_id='" . $mysql['country_id'] . "'";
                    $city_result = _mysqli_query($city_sql);
                    $city_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("city-id" . $mysql['city_name'] . $mysql['country_id'] . systemHash()), $city_id, $time);
                }
            }
        } else {
            $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "' AND country_id='" . $mysql['country_id'] . "'";
            $city_result = _mysqli_query($city_sql);
            $city_row = $city_result->fetch_assoc();
            if ($city_row['city_id']) {
                // if this city_id already exists, return the city_id for it.
                $city_id = $city_row['city_id'];
            } else {
                //insert city
                $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', country_id='" . $mysql['country_id'] . "'";
                $city_result = _mysqli_query($city_sql);
                $city_id = $db->insert_id;
            }
        }

        //return the city_id
        return $city_id;
    }

    // this returns the isp_id, when a isp name is given
    function get_isp_id($isp)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        $mysql['isp_name'] = $db->real_escape_string($isp);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("isp-id" . $mysql['isp_name'] . systemHash()));

            if ($getID) {
                $isp_id = $getID;
            } else {
                $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp_name'] . "'";
                $isp_result = _mysqli_query($isp_sql);
                $isp_row = $isp_result->fetch_assoc();
                if ($isp_row['isp_id']) {
                    // if this isp_id already exists, return the isp_id for it.
                    $isp_id = $isp_row['isp_id'];
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $mysql['isp_name'] . systemHash()), $isp_id, $time);
                } else {
                    //insert isp
                    $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp_name'] . "'";
                    $isp_result = _mysqli_query($isp_sql);
                    $isp_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $mysql['isp_name'] . systemHash()), $isp_id, $time);
                }
            }
        } else {
            $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp_name'] . "'";
            $isp_result = _mysqli_query($isp_sql);
            $isp_row = $isp_result->fetch_assoc();
            if ($isp_row['isp_id']) {
                // if this isp_id already exists, return the isp_id for it.
                $isp_id = $isp_row['isp_id'];
            } else {
                //insert isp
                $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp_name'] . "'";
                $isp_result = _mysqli_query($isp_sql);
                $isp_id = $db->insert_id;

                return $isp_id;
            }
        }
    }

    // this returns the ip_id, when a ip_address is given
    public static function get_ip_id($ip_address)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($ip_address)) {
            return 0;
        }

        $mysql['ip_address'] = $db->real_escape_string(trim((string) $ip_address));
        $ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='" . $mysql['ip_address'] . "'";
        $ip_result = $db->query($ip_sql); // or record_mysql_error($ip_sql);

        if ($ip_result->num_rows == 0) {

            //get geo info
            $ipRegistry = new \IPRegistry\IPRegistry();
            $ipInfo = $ipRegistry->getIpInfo($ip_address);

            $mysql['country_code'] = $db->real_escape_string($ipInfo['country_code']);
            $mysql['region_code'] = $db->real_escape_string($ipInfo['region_code']);
            $mysql['city_name'] = $db->real_escape_string($ipInfo['city_name']);
            $mysql['zip_code'] = $db->real_escape_string($ipInfo['zip_code']);
            $mysql['isp_name'] = $db->real_escape_string($ipInfo['isp_name']);
            $mysql['connection_type_name'] = $db->real_escape_string($ipInfo['connection_type_name']);

            $ip_sql = "INSERT INTO 202_ips SET ip_address='" . $mysql['ip_address'] . "',
                                                country_code='" . $mysql['country_code'] . "',
                                                region_code='" . $mysql['region_code'] . "',
                                                city_name='" . $mysql['city_name'] . "',
                                                zip_code='" . $mysql['zip_code'] . "',
                                                isp_name='" . $mysql['isp_name'] . "',
                                                connection_type_name='" . $mysql['connection_type_name'] . "'";
            delay_sql($ip_sql);
            $ip_id = mysqli_insert_id($db);
        } else {
            $ip_row = $ip_result->fetch_assoc();
            $ip_id = $ip_row['ip_id'];
        }
        return $ip_id;
    }

    // Modified to accept string IP address
    public static function insert_ip($ip_address_string)
    {
        global $db; // Use global $db

        // Validate and sanitize the input IP address string
        $ip_address_string = trim((string) $ip_address_string);
        if (empty($ip_address_string)) {
            error_log("Empty IP address provided to insert_ip.");
            return 0; // Or handle as an error
        }

        // Determine IP type (IPv4 or IPv6)
        $ip_type = 'ipv4';
        if (filter_var($ip_address_string, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip_type = 'ipv6';
        } elseif (!filter_var($ip_address_string, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            error_log("Invalid IP address format provided to insert_ip: " . $ip_address_string);
            return 0; // Or handle as an error
        }

        $mysql['ip_address'] = $db->real_escape_string($ip_address_string);

        // Define IPv6 conversion functions if they exist
        $inet6_pton = function_exists('inet_pton') ? 'inet_pton' : '';

        $ip_id = 0; // Initialize ip_id

        if ($ip_type === 'ipv6') {
            if ($inet6_pton === '') {
                error_log("IPv6 address provided for insertion, but inet_pton function is not available.");
                return 0; // Cannot process IPv6 without inet_pton
            }
            $packed_ip = $inet6_pton($mysql['ip_address']);
            $escaped_packed_ip = $db->real_escape_string($packed_ip);

            // Insert the IPv6 address (assuming binary storage)
            $ip_sql_v6 = "INSERT INTO 202_ips_v6 SET ip_address = '" . $escaped_packed_ip . "'";
            $ip_result_v6 = _mysqli_query($ip_sql_v6); // Correct call: uses global $db

            if ($ip_result_v6) {
                $ipv6_ref_id = $db->insert_id; // Get the ID from 202_ips_v6 table

                // Insert a reference into 202_ips (adjust based on schema relationship)
                // Assuming 202_ips.ip_address stores the reference ID for v6
                $ip_sql_ref = "INSERT INTO 202_ips SET ip_address = '" . $db->real_escape_string($ipv6_ref_id) . "' "; // You might need a flag column to indicate this is a v6 reference
                $ip_result_ref = _mysqli_query($ip_sql_ref); // Correct call

                if ($ip_result_ref) {
                    $ip_id = $db->insert_id; // This is the final ip_id from 202_ips
                } else {
                    error_log("Failed to insert IPv6 reference into 202_ips: " . $db->error);
                }
            } else {
                error_log("Failed to insert IPv6 address into 202_ips_v6: " . $db->error);
            }
            return $ip_id;
        } else { // IPv4
            $ip_sql = "INSERT INTO 202_ips SET ip_address = '" . $mysql['ip_address'] . "'";
            $ip_result = _mysqli_query($ip_sql); // Correct call: uses global $db

            if ($ip_result) {
                $ip_id = $db->insert_id;
            } else {
                error_log("Failed to insert IPv4 address into 202_ips: " . $db->error);
            }
            return $ip_id;
        }
    }

    // this returns the site_domain_id, when a site_url_address is given
    public static function get_site_domain_id($site_url)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($site_url)) {
            return 0;
        }

        $site_domain = parse_url((string) $site_url, PHP_URL_HOST);
        $mysql['site_domain'] = $db->real_escape_string(trim($site_domain));
        $site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain='" . $mysql['site_domain'] . "'";
        $site_domain_result = $db->query($site_domain_sql); // or record_mysql_error($site_domain_sql);

        if ($site_domain_result->num_rows == 0) {
            $site_domain_sql = "INSERT INTO 202_site_domains SET site_domain='" . $mysql['site_domain'] . "'";
            delay_sql($site_domain_sql);
            $site_domain_id = mysqli_insert_id($db);
        } else {
            $site_domain_row = $site_domain_result->fetch_assoc();
            $site_domain_id = $site_domain_row['site_domain_id'];
        }
        return $site_domain_id;
    }

    // this returns the site_url_id, when a site_url_address is given
    public static function get_site_url_id($site_url)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($site_url)) {
            return 0;
        }

        $mysql['site_url'] = $db->real_escape_string(trim((string) $site_url));
        $site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_url='" . $mysql['site_url'] . "'";
        $site_url_result = $db->query($site_url_sql); // or record_mysql_error($site_url_sql);

        if ($site_url_result->num_rows == 0) {

            $site_domain_id = INDEXES::get_site_domain_id($site_url);
            $mysql['site_domain_id'] = $db->real_escape_string((string) $site_domain_id);

            $site_url_sql = "INSERT INTO 202_site_urls SET site_url='" . $mysql['site_url'] . "', site_domain_id='" . $mysql['site_domain_id'] . "'";
            delay_sql($site_url_sql);
            $site_url_id = mysqli_insert_id($db);
        } else {
            $site_url_row = $site_url_result->fetch_assoc();
            $site_url_id = $site_url_row['site_url_id'];
        }
        return $site_url_id;
    }

    // this returns the keyword_id
    public static function get_keyword_id($keyword_name)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($keyword_name)) {
            return 0;
        }

        $mysql['keyword_name'] = $db->real_escape_string(trim((string) $keyword_name));
        $keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword_name='" . $mysql['keyword_name'] . "'";
        $keyword_result = $db->query($keyword_sql); // or record_mysql_error($keyword_sql);

        if ($keyword_result->num_rows == 0) {
            $keyword_sql = "INSERT INTO 202_keywords SET keyword_name='" . $mysql['keyword_name'] . "'";
            delay_sql($keyword_sql);
            $keyword_id = mysqli_insert_id($db);
        } else {
            $keyword_row = $keyword_result->fetch_assoc();
            $keyword_id = $keyword_row['keyword_id'];
        }
        return $keyword_id;
    }

    // this returns the c1 id
    function get_c1_id($c1)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        // only grab the first 350 charactesr of c1
        $c1 = substr((string) $c1, 0, 350);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("c1-id" . $c1 . systemHash()));

            if ($getID) {
                $c1_id = $getID;
            } else {
                $mysql['c1'] = $db->real_escape_string($c1);
                $c1_sql = "SELECT c1_id FROM 202_clicks_c1 WHERE c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($c1_sql);
                $c1_row = $c1_result->fetch_assoc();
                if ($c1_row['c1_id']) {
                    // if this c1_id already exists, return the c1_id for it.
                    $c1_id = $c1_row['c1_id'];
                    // add to memcached
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id, $time);
                } else {
                    //insert c1
                    $c1_sql = "INSERT INTO 202_clicks_c1 SET c1='" . $mysql['c1'] . "'";
                    $c1_result = _mysqli_query($c1_sql);
                    $c1_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id, $time);
                }
            }
        } else {
            $mysql['c1'] = $db->real_escape_string($c1);
            $c1_sql = "SELECT c1_id FROM 202_clicks_c1 WHERE c1='" . $mysql['c1'] . "'";
            $c1_result = _mysqli_query($c1_sql);
            $c1_row = $c1_result->fetch_assoc();
            if ($c1_row['c1_id']) {
                // if this c1_id already exists, return the c1_id for it.
                $c1_id = $c1_row['c1_id'];
            } else {
                //insert c1
                $c1_sql = "INSERT INTO 202_clicks_c1 SET c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($c1_sql);
                $c1_id = $db->insert_id;
            }
        }

        //return the c1_id
        return $c1_id;
    }

    // this returns the c2 id
    function get_c2_id($c2)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        // only grab the first 350 charactesr of c2
        $c2 = substr((string) $c2, 0, 350);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("c2-id" . $c2 . systemHash()));

            if ($getID) {
                $c2_id = $getID;
            } else {
                $mysql['c2'] = $db->real_escape_string($c2);
                $c2_sql = "SELECT c2_id FROM 202_clicks_c2 WHERE c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($c2_sql);
                $c2_row = $c2_result->fetch_assoc();
                if ($c2_row['c2_id']) {
                    // if this c2_id already exists, return the c2_id for it.
                    $c2_id = $c2_row['c2_id'];
                    // add to memcached
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id, $time);
                } else {
                    //insert c2
                    $c2_sql = "INSERT INTO 202_clicks_c2 SET c2='" . $mysql['c2'] . "'";
                    $c2_result = _mysqli_query($c2_sql);
                    $c2_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id, $time);
                }
            }
        } else {
            $mysql['c2'] = $db->real_escape_string($c2);
            $c2_sql = "SELECT c2_id FROM 202_clicks_c2 WHERE c2='" . $mysql['c2'] . "'";
            $c2_result = _mysqli_query($c2_sql);
            $c2_row = $c2_result->fetch_assoc();
            if ($c2_row['c2_id']) {
                // if this c2_id already exists, return the c2_id for it.
                $c2_id = $c2_row['c2_id'];
            } else {
                //insert c2
                $c2_sql = "INSERT INTO 202_clicks_c2 SET c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($c2_sql);
                $c2_id = $db->insert_id;
            }
        }

        //return the c2_id
        return $c2_id;
    }

    // this returns the c3 id
    function get_c3_id($c3)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        // only grab the first 350 charactesr of c3
        $c3 = substr((string) $c3, 0, 350);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("c3-id" . $c3 . systemHash()));

            if ($getID) {
                $c3_id = $getID;
            } else {
                $mysql['c3'] = $db->real_escape_string($c3);
                $c3_sql = "SELECT c3_id FROM 202_clicks_c3 WHERE c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($c3_sql);
                $c3_row = $c3_result->fetch_assoc();
                if ($c3_row['c3_id']) {
                    // if this c3_id already exists, return the c3_id for it.
                    $c3_id = $c3_row['c3_id'];
                    // add to memcached
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id, $time);
                } else {
                    //insert c3
                    $c3_sql = "INSERT INTO 202_clicks_c3 SET c3='" . $mysql['c3'] . "'";
                    $c3_result = _mysqli_query($c3_sql);
                    $c3_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id, $time);
                }
            }
        } else {
            $mysql['c3'] = $db->real_escape_string($c3);
            $c3_sql = "SELECT c3_id FROM 202_clicks_c3 WHERE c3='" . $mysql['c3'] . "'";
            $c3_result = _mysqli_query($c3_sql);
            $c3_row = $c3_result->fetch_assoc();
            if ($c3_row['c3_id']) {
                // if this c3_id already exists, return the c3_id for it.
                $c3_id = $c3_row['c3_id'];
            } else {
                //insert c3
                $c3_sql = "INSERT INTO 202_clicks_c3 SET c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($c3_sql);
                $c3_id = $db->insert_id;
            }
        }

        //return the c3_id
        return $c3_id;
    }

    // this returns the c4 id
    function get_c4_id($c4)
    {
        global $memcacheWorking, $memcache;
        $database = DB::getInstance();
        $db = $database->getConnection();

        // only grab the first 350 charactesr of c4
        $c4 = substr((string) $c4, 0, 350);

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("c4-id" . $c4 . systemHash()));

            if ($getID) {
                $c4_id = $getID;
            } else {
                $mysql['c4'] = $db->real_escape_string($c4);
                $c4_sql = "SELECT c4_id FROM 202_clicks_c4 WHERE c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($c4_sql);
                $c4_row = $c4_result->fetch_assoc();
                if ($c4_row['c4_id']) {
                    // if this c4_id already exists, return the c4_id for it.
                    $c4_id = $c4_row['c4_id'];
                    // add to memcached
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id, $time);
                } else {
                    //insert c4
                    $c4_sql = "INSERT INTO 202_clicks_c4 SET c4='" . $mysql['c4'] . "'";
                    $c4_result = _mysqli_query($c4_sql);
                    $c4_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id, $time);
                }
            }
        } else {
            $mysql['c4'] = $db->real_escape_string($c4);
            $c4_sql = "SELECT c4_id FROM 202_clicks_c4 WHERE c4='" . $mysql['c4'] . "'";
            $c4_result = _mysqli_query($c4_sql);
            $c4_row = $c4_result->fetch_assoc();
            if ($c4_row['c4_id']) {
                // if this c4_id already exists, return the c4_id for it.
                $c4_id = $c4_row['c4_id'];
            } else {
                //insert c4
                $c4_sql = "INSERT INTO 202_clicks_c4 SET c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($c4_sql);
                $c4_id = $db->insert_id;
            }
        }

        //return the c4_id
        return $c4_id;
    }

    public static function get_browser_id($browser_name)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($browser_name)) {
            return 0;
        }

        $mysql['browser_name'] = $db->real_escape_string(trim((string) $browser_name));
        $browser_sql = "SELECT browser_id FROM 202_browsers WHERE browser_name='" . $mysql['browser_name'] . "'";
        $browser_result = $db->query($browser_sql); // or record_mysql_error($browser_sql);

        if ($browser_result->num_rows == 0) {
            $browser_sql = "INSERT INTO 202_browsers SET browser_name='" . $mysql['browser_name'] . "'";
            delay_sql($browser_sql);
            $browser_id = mysqli_insert_id($db);
        } else {
            $browser_row = $browser_result->fetch_assoc();
            $browser_id = $browser_row['browser_id'];
        }
        return $browser_id;
    }

    public static function get_platform_id($platform_name)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($platform_name)) {
            return 0;
        }

        $mysql['platform_name'] = $db->real_escape_string(trim((string) $platform_name));
        $platform_sql = "SELECT platform_id FROM 202_platforms WHERE platform_name='" . $mysql['platform_name'] . "'";
        $platform_result = $db->query($platform_sql); // or record_mysql_error($platform_sql);

        if ($platform_result->num_rows == 0) {
            $platform_sql = "INSERT INTO 202_platforms SET platform_name='" . $mysql['platform_name'] . "'";
            delay_sql($platform_sql);
            $platform_id = mysqli_insert_id($db);
        } else {
            $platform_row = $platform_result->fetch_assoc();
            $platform_id = $platform_row['platform_id'];
        }
        return $platform_id;
    }

    public static function get_device_id($device_name)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();

        if (empty($device_name)) {
            return 0;
        }

        $mysql['device_name'] = $db->real_escape_string(trim((string) $device_name));
        $device_sql = "SELECT device_id FROM 202_devices WHERE device_name='" . $mysql['device_name'] . "'";
        $device_result = $db->query($device_sql); // or record_mysql_error($device_sql);

        if ($device_result->num_rows == 0) {
            $device_sql = "INSERT INTO 202_devices SET device_name='" . $mysql['device_name'] . "'";
            delay_sql($device_sql);
            $device_id = mysqli_insert_id($db);
        } else {
            $device_row = $device_result->fetch_assoc();
            $device_id = $device_row['device_id'];
        }
        return $device_id;
    }
}

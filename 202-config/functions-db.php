<?php

// Define memcache wrapper functions that were missing
if (!function_exists('memcache_get')) {
    function memcache_get($key)
    {
        global $memcache, $memcacheWorking;
        if ($memcacheWorking && $memcache) {
            return $memcache->get($key);
        }
        return false;
    }
}

if (!function_exists('memcache_set')) {
    function memcache_set($key, $value, $expiration = 0)
    {
        global $memcache, $memcacheWorking;
        if ($memcacheWorking && $memcache) {
            // Use appropriate method based on memcache implementation
            if ($memcache instanceof Memcache) {
                return $memcache->set($key, $value, false, $expiration);
            } elseif ($memcache instanceof Memcached) {
                return $memcache->set($key, $value, $expiration);
            }
        }
        return false;
    }
}

if (!function_exists('query')) {
    function query($sql)
    {
        return _mysqli_query($sql);
    }
}

if (!function_exists('memcache_mysql_fetch_assoc')) {
    function memcache_mysql_fetch_assoc($result)
    {
        $row = memcache_get($result);
        if ($row) {
            return $row;
        }

        if (is_object($result)) {
            $row = $result->fetch_assoc();
        } else {
            return false;
        }

        if ($row) {
            memcache_set($result, $row);
        }

        return $row;
    }
}

if (!function_exists('foreach_memcache_mysql_fetch_assoc')) {
    function foreach_memcache_mysql_fetch_assoc($result)
    {
        $rows = [];
        while ($row = memcache_mysql_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('delay_sql')) {
    function delay_sql($sql, $delay = 0)
    {
        if ($delay > 0) {
            sleep($delay);
        }

        return _mysqli_query($sql);
    }
}

if (!function_exists('user_cache_time')) {
    function user_cache_time($user_id)
    {
        $cache_time = memcache_get('user_cache_time_' . $user_id);
        if ($cache_time) {
            return $cache_time;
        }

        $cache_time = time();
        memcache_set('user_cache_time_' . $user_id, $cache_time);

        return $cache_time;
    }
}

if (!function_exists('get_user_data_feedback')) {
    function get_user_data_feedback($user_id)
    {
        $cache_key = 'user_data_feedback_' . $user_id;
        $data = memcache_get($cache_key);
        if ($data) {
            return $data;
        }

        try {
            $sql = "SELECT * FROM user_data_feedback WHERE user_id = " . intval($user_id);
            $result = _mysqli_query($sql);
            $data = foreach_memcache_mysql_fetch_assoc($result);

            if ($data) {
                memcache_set($cache_key, $data);
                return $data;
            }
        } catch (Exception) {
            // Table doesn't exist or other DB error - fall through to defaults
        }

        $default_data = [
            'install_hash' => '',
            'user_email' => '',
            'user_hash' => '',
            'time_stamp' => time(),
            'api_key' => '',
            'vip_perks_status' => 0,
            'modal_status' => 1,
        ];

        memcache_set($cache_key, $default_data);

        return $default_data;
    }
}

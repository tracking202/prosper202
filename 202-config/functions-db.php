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

function query($sql)
{
    $result = _mysqli_query($sql);
    return $result;
}

function memcache_mysql_fetch_assoc($result)
{
    $row = memcache_get($result);
    if ($row) {
        return $row;
    } else {
        // Only use mysqli's fetch_assoc method, removing the deprecated mysql_fetch_assoc call
        if (is_object($result)) {
            $row = $result->fetch_assoc();
        } else {
            // If result is not an object (possibly a resource or something else),
            // we can't reliably fetch data, so return false
            return false;
        }

        if ($row) {
            memcache_set($result, $row);
        }
        return $row;
    }
}

function foreach_memcache_mysql_fetch_assoc($result)
{
    $rows = [];
    while ($row = memcache_mysql_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function delay_sql($sql, $delay)
{
    sleep($delay);
    return query($sql);
}

function user_cache_time($user_id)
{
    $cache_time = memcache_get('user_cache_time_' . $user_id);
    if ($cache_time) {
        return $cache_time;
    } else {
        $cache_time = time();
        memcache_set('user_cache_time_' . $user_id, $cache_time);
        return $cache_time;
    }
}

function get_user_data_feedback($user_id)
{
    $cache_key = 'user_data_feedback_' . $user_id;
    $data = memcache_get($cache_key);
    if ($data) {
        return $data;
    } else {
        // Check if table exists before querying
        try {
            $sql = "SELECT * FROM user_data_feedback WHERE user_id = " . intval($user_id);
            $result = query($sql);
            $data = foreach_memcache_mysql_fetch_assoc($result);

            if ($data) {
                memcache_set($cache_key, $data);
                return $data;
            }
        } catch (Exception $e) {
            // Table doesn't exist or other DB error - return default structure
        }

        // Default values when table doesn't exist or is empty
        $default_data = array(
            'install_hash' => '',
            'user_email' => '',
            'user_hash' => '',
            'time_stamp' => time(),
            'api_key' => '',
            'vip_perks_status' => 0,
            'modal_status' => 1 // Set to 1 to prevent modal popup
        );

        memcache_set($cache_key, $default_data);
        return $default_data;
    }
}

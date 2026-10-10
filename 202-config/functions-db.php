<?php

declare(strict_types=1);

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

// query(), memcache_mysql_fetch_assoc(), foreach_memcache_mysql_fetch_assoc(),
// delay_sql(), user_cache_time() and get_user_data_feedback() are
// functions-tracking202.php's, which connect.php loads first: the guarded
// copies that stood here never ran on a page, and the tests that exercised
// them tested a stand-in.

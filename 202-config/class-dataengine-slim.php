<?php

declare(strict_types=1);

use Prosper202\DataEngine\ClickRollupSql;

if (!isset($_SESSION['user_timezone'])) {
    date_default_timezone_set('GMT');
} else {
    date_default_timezone_set($_SESSION['user_timezone']);
}

if (!class_exists('DataEngine')) {
    /**
     * Lightweight DataEngine used on the tracking hot path (static pixels,
     * postbacks). Provides only the click rollup; reporting endpoints load
     * the full engine from class-dataengine.php instead.
     */
    class DataEngine
    {
        private static ?mysqli $db = null;

        public function __construct()
        {
            try {
                self::$db = DB::getInstance()->getConnection();
            } catch (Exception) {
                self::$db = null;
            }

            // Make MySQL use the timezone chosen by the user.
            $timezone = new DateTimeZone(date_default_timezone_get());
            $offsetHours = round($timezone->getOffset(new DateTime()) / 3600);

            if ($offsetHours != 0 && self::$db !== null) {
                self::$db->query("SET time_zone = '" . $offsetHours . ":00'");
            }
        }

        /**
         * Roll a single click up into 202_dataengine so reports reflect it.
         * When no click id is given, the most recent click from the current
         * visitor IP (last 24h) is used.
         */
        public function setDirtyHour($click_id)
        {
            global $ip_address, $db, $inet6_ntoa, $inet6_aton;

            // connect2.php does not initialize these globals; later code in
            // the same request may rely on this side effect.
            if (!isset($inet6_ntoa)) {
                $inet6_ntoa = '';
                $inet6_aton = 'INET6_ATON';
            }

            if (!isset($click_id) || $click_id == '') {
                // No native IPv6 support: compare against the PHP-encoded value.
                if ($inet6_ntoa == '' && isset($ip_address) && $ip_address->type == 'ipv6') {
                    $escapedIp = inet6_aton($db->real_escape_string($ip_address->address));
                } else {
                    $escapedIp = $db->real_escape_string($ip_address->address);
                }

                $daysago = time() - 86400; // 24 hours

                // Start from the IP table for better index usage.
                $click_sql1 = 'SELECT c.click_id
                           FROM            202_ips i
                           INNER JOIN      202_clicks_advance ca ON (ca.ip_id = i.ip_id)
                           INNER JOIN      202_clicks c ON (c.click_id = ca.click_id)
                           WHERE           i.ip_address = "' . $escapedIp . '"
                           AND             c.user_id = "1"
                           AND             c.click_time >= "' . $daysago . '"
                           ORDER BY        c.click_id DESC
                           LIMIT           1';

                $click_result1 = $db->query($click_sql1) or record_mysql_error($db);
                $click_row1 = $click_result1->fetch_assoc();

                if ($click_row1 && isset($click_row1['click_id'])) {
                    $click_id = $db->real_escape_string((string) $click_row1['click_id']);
                } else {
                    $click_id = '';
                }
            }

            if (!isset($click_id) || $click_id == '') {
                return false;
            }

            $dsql = ClickRollupSql::insertSelect(
                '202_dataengine',
                '2c.click_id=' . $click_id,
                updateLandingPageId: true
            );

            if (!$db->query($dsql)) {
                error_log('DataEngine (slim) setDirtyHour rollup failed: ' . $db->error);
                return false;
            }

            return true;
        }

        /**
         * Compatibility shim for endpoints that call the full DataEngine API.
         */
        public function getSummary($start, $end, $params, $user_id = 1, $upgrade = false, $new = false)
        {
            return '';
        }
    }
} // End class_exists check

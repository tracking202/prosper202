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

            // The connection's zone is left alone. This used to SET it to the
            // signed-in user's offset today, rounded to whole hours, for the
            // rest of the request — and without the sign for a zone east of
            // UTC, which MariaDB refuses ("Unknown or incorrect time zone:
            // '5:00'"), so it held for zones west of UTC only. The rollup it
            // runs reads no clock.
        }

        /**
         * Roll a single click up into 202_dataengine so reports reflect it.
         *
         * A request that names no click re-rolls none. It used to take "the
         * visitor's latest click": user 1's newest click in the last 24 hours from
         * the address in $ip_address. Measured, a cookie-less lpc.php request
         * from an address re-rolled user 1's click from it. Nothing leaked — the
         * answer is a bool, and a re-roll writes what the click's own rows say —
         * but it was a lookup and a rollup for a request that changed no click,
         * and it read the wrong things: only user 1's clicks, any visitor behind
         * the same address, and the $ip_address global where the click path's
         * own address lookups read the address as stored (StoredVisitorIp,
         * LastClickFromAddress).
         */
        public function setDirtyHour($click_id)
        {
            global $db, $inet6_ntoa, $inet6_aton;

            // connect2.php does not initialize these globals; later code in
            // the same request may rely on this side effect.
            if (!isset($inet6_ntoa)) {
                $inet6_ntoa = '';
                $inet6_aton = 'INET6_ATON';
            }

            if (!isset($click_id) || $click_id == '') {
                return false;
            }

            // click_id can originate from a caller-supplied cookie/request
            // value; cast to int so it cannot break out of the WHERE clause.
            $dsql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=' . (int) $click_id);

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

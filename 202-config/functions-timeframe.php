<?php

function grab_timeframe($time)
{
    switch ($time) {
        case 'today':
            $from = strtotime('today 00:00:00');
            $to = strtotime('today 23:59:59');
            break;
        case 'yesterday':
            $from = strtotime('yesterday 00:00:00');
            $to = strtotime('yesterday 23:59:59');
            break;
        case 'last7':
            $from = strtotime('-7 days 00:00:00');
            $to = strtotime('today 23:59:59');
            break;
        case 'last14':
            $from = strtotime('-14 days 00:00:00');
            $to = strtotime('today 23:59:59');
            break;
        case 'last30':
            $from = strtotime('-30 days 00:00:00');
            $to = strtotime('today 23:59:59');
            break;
        case 'thismonth':
            $from = strtotime('first day of this month 00:00:00');
            $to = strtotime('last day of this month 23:59:59');
            break;
        case 'lastmonth':
            $from = strtotime('first day of last month 00:00:00');
            $to = strtotime('last day of last month 23:59:59');
            break;
        default:
            $from = strtotime('today 00:00:00');
            $to = strtotime('today 23:59:59');
            break;
    }
    return array('from' => $from, 'to' => $to);
}

function getLastDayOfMonth($month, $year)
{
    return date("t", strtotime("$year-$month-01"));
}

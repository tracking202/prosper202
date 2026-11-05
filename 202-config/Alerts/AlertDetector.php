<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * One kind of money-loss alert: what an account's activity says is wrong
 * now. AlertRun sends each alert once per incident, so a detector answers
 * every campaign the condition holds for, every run.
 */
interface AlertDetector
{
    public function kind(): AlertKind;

    /** @return list<Alert> */
    public function detect(Connection $conn, AccountActivity $activity, AlertSettings $settings): array;
}

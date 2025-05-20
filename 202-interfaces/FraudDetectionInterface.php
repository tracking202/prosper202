<?php
declare(strict_types=1);
interface FraudDetectionInterface
{
    public function isFraud($ip);
    public function verifyKey();
}
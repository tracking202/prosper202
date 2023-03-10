<?php
interface FraudDetectionInterface
{
    public function isFraud($ip);
    public function verifyKey();
}
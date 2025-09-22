<?php
declare(strict_types=1);
namespace GeoIp2\Exception;

/**
 * This class represents an error returned by MaxMind's GeoIP2
 * web service.
 */
class InvalidRequestException extends HttpException
{
    public function __construct(
        $message,
        /**
         * The code returned by the MaxMind web service.
         */
        public $error,
        $httpStatus,
        $uri,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $httpStatus, $uri, $previous);
    }
}

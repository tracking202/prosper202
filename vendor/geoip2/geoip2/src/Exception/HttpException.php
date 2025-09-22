<?php
declare(strict_types=1);
namespace GeoIp2\Exception;

/**
 *  This class represents an HTTP transport error.
 */
class HttpException extends GeoIp2Exception
{
    public function __construct(
        $message,
        $httpStatus,
        /**
         * The URI queried.
         */
        public $uri,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}

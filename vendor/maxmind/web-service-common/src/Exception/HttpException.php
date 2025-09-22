<?php
declare(strict_types=1);
namespace MaxMind\Exception;

/**
 *  This class represents an HTTP transport error.
 */
class HttpException extends WebServiceException
{
    /**
     * @param string     $message    a message describing the error
     * @param int        $httpStatus the HTTP status code of the response
     * @param string     $uri        the URI used in the request
     * @param \Exception $previous   the previous exception, if any
     */
    public function __construct(
        $message,
        $httpStatus,
        private $uri,
        ?\Exception $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function getStatusCode()
    {
        return $this->getCode();
    }
}

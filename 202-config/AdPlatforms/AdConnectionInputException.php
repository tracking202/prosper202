<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

/**
 * A connection request refused for one field, named, with what would be
 * accepted. $platform is the AdvertiserAnswer kind when the platform's answer
 * is the reason (NOT_FOUND is a 404), '' when the request is.
 */
final class AdConnectionInputException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message, public readonly string $platform = '')
    {
        parent::__construct($message);
    }
}

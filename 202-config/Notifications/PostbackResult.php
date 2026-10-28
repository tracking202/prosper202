<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

/**
 * What became of one server-to-server postback: delivered (the network
 * answered 2xx or 3xx), failed (worth trying again: no answer, a 4xx or
 * 5xx, a host that did not resolve), or refused (never sent, and never
 * will be: the URL, or a redirect it answered with, names an address this
 * server does not call). The reason names hosts, never a path or a query,
 * which can carry a network's key.
 */
final class PostbackResult
{
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?int $status,
    ) {
    }

    public static function delivered(?int $status): self
    {
        return new self(self::DELIVERED, '', $status);
    }

    public static function failed(string $reason, ?int $status = null): self
    {
        return new self(self::FAILED, $reason, $status);
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, $reason, null);
    }

    /**
     * A sender's answer as a result: a test's fetch answers true or false,
     * which says nothing about why, so false is a failure to retry.
     */
    public static function of(bool|self $answer): self
    {
        if ($answer instanceof self) {
            return $answer;
        }

        return $answer ? self::delivered(null) : self::failed('the traffic source did not accept it');
    }

    public function isDelivered(): bool
    {
        return $this->outcome === self::DELIVERED;
    }

    public function isRefused(): bool
    {
        return $this->outcome === self::REFUSED;
    }
}

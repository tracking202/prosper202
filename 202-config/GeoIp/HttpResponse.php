<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $location,
        public readonly string $body,
        public readonly int $bytes,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * A public id read from a link or a cookie: a landing page's lpip, a
 * tracker's t202id, a campaign's acip, a click's pci. The column is an
 * integer, so a value compared as text was cast by MySQL first: `12abc`
 * named record 12 and `1e3` record 1000. Exactly one id or none, by the
 * rule ClickId::parse() reads a click id with.
 */
final class PublicId
{
    private function __construct()
    {
    }

    public static function parse(mixed $value): ?int
    {
        return ClickId::parse($value);
    }
}

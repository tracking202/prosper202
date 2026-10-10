<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\NotFoundException;

/**
 * A record id from the URL path: the digits of a positive whole number that
 * the int cast leaves unchanged, or a 404 saying the segment is not an id.
 *
 * The routes read their ids with `(int) $ctx['id']`, and `(int) '1e3'` is
 * 1000, `(int) '12x'` is 12 and `(int) '99999999999999999999'` is
 * PHP_INT_MAX: measured live, DELETE /campaigns/2e0 deleted campaign 2 and
 * PUT /aff-networks/2x renamed category 2, each answered as though the path
 * had named it. A path that names no record is a 404, as a well-formed id
 * of no record is; the message says the segment was not an id, so a client
 * that built the path wrong can tell the two apart.
 *
 * Every {id}-style path parameter in api/v3/index.php is read through here
 * (PathIdsAreReadStrictlyTest); the string ones (a sync job's id, a staged
 * change's, an API key, an install's uuid, a subscription's external ref,
 * a changes-feed entity) are read as strings.
 */
final class PathId
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $ctx the router's path parameters
     * @throws NotFoundException when the segment is not a positive id
     */
    public static function of(array $ctx, string $key = 'id'): int
    {
        $segment = $ctx[$key] ?? null;
        if (!is_string($segment)) {
            // A route asked for a parameter its pattern does not have: a
            // server bug, never the request's.
            throw new \LogicException('The route has no path parameter "' . $key . '"');
        }
        if (preg_match('/^[1-9][0-9]*$/D', $segment) === 1 && (string) (int) $segment === $segment) {
            return (int) $segment;
        }

        // A request line can carry bytes that are not UTF-8, which json_encode()
        // refuses with false, and the message would name nothing: substituted.
        $shown = json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        throw new NotFoundException('Not found: ' . ($shown === false ? 'the path segment' : $shown) . ' is not an id');
    }
}

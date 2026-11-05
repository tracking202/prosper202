<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * A type whose credentials include a grant the operator gives through the
 * platform's consent screen (an OAuth refresh token), never typed in.
 */
interface AuthorisedDestination
{
    /** The credential key the grant is stored under. */
    public const GRANT = 'refresh_token';

    /** @param array<string, mixed> $credentials */
    public function authorised(array $credentials): bool;

    /**
     * The credentials to store when new ones are typed in: $given, plus the
     * stored grant while it still belongs to them (the same OAuth client).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    public function keptOnReplace(array $stored, array $given): array;
}

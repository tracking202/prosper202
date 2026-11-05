<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * One ad platform. The framework owns the queue, the consent gate, hashing
 * and the log; a type owns its settings, its request and what its answer means.
 */
interface DestinationType
{
    /**
     * The type's own settings and credentials, normalised. $credentials null
     * keeps the stored ones (an update that does not replace them).
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $credentials
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     * @throws DestinationInputException
     */
    public function validate(array $settings, ?array $credentials): array;

    /**
     * Send one conversion; a test fire goes to the platform's validation
     * endpoint where it has one. Never throws for a failed send.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $credentials
     */
    public function send(array $settings, array $credentials, ConversionFacts $facts, bool $test, int $now): SendResult;

    /** @return list<string> the settings keys the type reads, beside the framework's (DestinationStore::COMMON_SETTINGS) */
    public function settingKeys(): array;

    /** @return list<string> the credential keys the type reads */
    public function credentialKeys(): array;

    /** The host requests go to, for the worker's per-host skip. */
    public function host(array $settings): string;
}

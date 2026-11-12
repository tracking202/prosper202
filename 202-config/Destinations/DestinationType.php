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

    /** How many days back the platform takes a conversion: the most a backfill offers. */
    public function backfillDays(): int;

    /**
     * Whether the platform takes the ad_user_data and ad_personalization
     * consent signals (the framework's `consent` setting). A type that does
     * not refuses the setting, and a visitor who may be in the EEA or UK is
     * withheld from it: there is no way to state their consent.
     */
    public function takesConsent(): bool;

    /** Whether the settings name everything a send needs; a destination that does not waits (needs_account). */
    public function configured(array $settings): bool;

    /** The customer's email as the platform hashes it, null when it cannot be normalised. */
    public function hashEmail(?string $email): ?string;

    /** The customer's phone number as the platform hashes it, null when it cannot be normalised. */
    public function hashPhone(?string $phone): ?string;

    /**
     * The type's own settings read from Setup › Destinations' edit form over
     * the stored ones; the framework's (campaigns, user data, consent) are
     * DestinationForm's.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     * @throws DestinationInputException
     */
    public function formSettings(array $post, array $settings): array;

    /** How the list names the destination's account: the platform and its id. */
    public function label(array $settings): string;
}

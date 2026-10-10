<?php

declare(strict_types=1);

namespace Api\V3\Exception;

/**
 * Another Prosper202 instance's API answered an error status: what a sync
 * read from or wrote to its source or target.
 *
 * RemoteApiClient threw a plain DatabaseException with the remote message as
 * its internal detail, so every remote refusal - a 409 "Version mismatch"
 * from a force-update whose target record changed after the sync read it, a
 * 422, a 401 - reached the sync job as "Internal server error", and nothing
 * downstream could tell a conflict from an outage. This keeps the status and
 * the remote's own message (the remote API already wrote that message for a
 * client) so the sync can name what happened, and decide by status rather
 * than by guessing at text.
 *
 * Escaping to an HTTP response it is still the generic 500 its parent
 * gives: whose instance answered what is the sync job's to report.
 */
final class RemoteApiException extends DatabaseException
{
    /** A remote message is the other instance's text; it is kept, not unbounded. */
    private const MAX_MESSAGE = 500;

    public readonly string $remoteMessage;

    /** @var array<string, string> */
    public readonly array $fieldErrors;

    /**
     * @param array<array-key, mixed> $fieldErrors the remote's field_errors, if any
     */
    public function __construct(
        public readonly int $remoteStatus,
        string $remoteMessage,
        array $fieldErrors = [],
        public readonly string $method = '',
        public readonly string $path = '',
    ) {
        $this->remoteMessage = mb_substr($remoteMessage, 0, self::MAX_MESSAGE);
        $errors = [];
        foreach ($fieldErrors as $field => $error) {
            if (is_scalar($error)) {
                $errors[mb_substr((string) $field, 0, 100)] = mb_substr((string) $error, 0, self::MAX_MESSAGE);
            }
        }
        $this->fieldErrors = $errors;
        parent::__construct($this->describe());
    }

    /**
     * What happened, for a sync job's record: "409 from PUT campaigns/12:
     * Version mismatch" (and the remote's field errors, when it sent some).
     */
    public function describe(): string
    {
        $text = $this->remoteStatus . ' from ' . trim($this->method . ' ' . $this->path) . ': ' . $this->remoteMessage;
        if ($this->fieldErrors !== []) {
            $fields = [];
            foreach ($this->fieldErrors as $field => $error) {
                $fields[] = $field . ': ' . $error;
            }
            $text .= ' (' . implode('; ', $fields) . ')';
        }

        return $text;
    }

    /**
     * The remote refused the write because of the record's state: a
     * version mismatch (the record changed after it was read) or a
     * duplicate. Nothing was written, and running the same write again
     * without re-reading would overwrite whatever made it conflict.
     */
    public function isConflict(): bool
    {
        return $this->remoteStatus === 409;
    }
}

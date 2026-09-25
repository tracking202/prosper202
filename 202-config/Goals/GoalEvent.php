<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * One event reported for a goal subject: the evidence goals are evaluated
 * against (plan §5.5). An event on its own records nothing.
 *
 *   event_id         1–128 printable ASCII characters, no spaces, not
 *                    starting with "@" (reserved: "@install" is the install
 *                    itself); unique within its subject
 *   name             an event name, as GoalDefinition's trigger.event
 *   occurred_at      unix seconds, as the reporter's clock says
 *   received_at      unix seconds, as the server's clock says
 *   properties       flat object, at most 32 entries; keys are property
 *                    names, values a string (up to 255 bytes), a number or
 *                    a bool
 *   revenue          a number or null: what the reporter says it was worth
 *   revenue_trusted  whether the path it arrived by may set a paid value
 *                    (decided by the intake, never by the payload)
 *   transaction_id   the network's id for it, kept on the ledger row
 *   clocked_by_server the reporter sent no occurred_at, so the intake used
 *                    its own clock (a pixel, p202.track(), an API event
 *                    without one). Such a time is not something the
 *                    reporter said, so a retry that arrives a second
 *                    later is still the same event (GoalEngine compares it
 *                    at the stored time).
 *
 * The evaluation time is `min(occurred_at, received_at)`: a reporter's clock
 * can move an event earlier, never later than it arrived, so it cannot be
 * pushed into a window it missed.
 */
final class GoalEvent
{
    public const INSTALL_EVENT_ID = '@install';
    public const MAX_PROPERTIES = 32;
    private const EVENT_ID = '/^[\x21-\x3F\x41-\x7E][\x21-\x7E]{0,127}$/D';
    private const EVENT_NAME = '/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$/D';
    private const PROP_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    /**
     * @param array<string, string|int|float|bool> $properties
     */
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $name,
        public readonly int $occurredAt,
        public readonly int $receivedAt,
        public readonly array $properties,
        public readonly int|float|null $revenue,
        public readonly bool $revenueTrusted,
        public readonly ?string $transactionId,
        public readonly bool $isInstall = false,
        public readonly bool $clockedByServer = false,
    ) {
    }

    /** The same event at another occurred_at (a server-clocked retry compared at the stored time). */
    public function withOccurredAt(int $occurredAt): self
    {
        return new self(
            $this->eventId, $this->name, $occurredAt, $this->receivedAt, $this->properties, $this->revenue,
            $this->revenueTrusted, $this->transactionId, $this->isInstall, $this->clockedByServer,
        );
    }

    /** The same event, marked as timed by the intake's clock rather than the reporter's. */
    public function clockedByServer(): self
    {
        return new self(
            $this->eventId, $this->name, $this->occurredAt, $this->receivedAt, $this->properties, $this->revenue,
            $this->revenueTrusted, $this->transactionId, $this->isInstall, true,
        );
    }

    /** The install itself, as the one event an install trigger matches. */
    public static function install(int $installAt): self
    {
        return new self(self::INSTALL_EVENT_ID, null, $installAt, $installAt, [], null, false, null, true);
    }

    /** The time the event is ordered and windowed by. */
    public function effectiveAt(): int
    {
        return min($this->occurredAt, $this->receivedAt);
    }

    /**
     * Order two events for evaluation: by effective time, then arrival, then
     * event id byte order. Total, so every evaluator sorts a set the same way.
     */
    public static function compare(self $a, self $b): int
    {
        return [$a->effectiveAt(), $a->receivedAt] <=> [$b->effectiveAt(), $b->receivedAt]
            ?: strcmp($a->eventId, $b->eventId) <=> 0;
    }

    /**
     * Read and validate an event from decoded JSON.
     *
     * @throws InvalidGoalDefinition naming every bad field
     */
    public static function fromArray(mixed $raw, string $path = 'event'): self
    {
        $e = [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidGoalDefinition([$path => 'must be a JSON object'], 'The event is invalid');
        }
        $allowed = ['event_id', 'name', 'occurred_at', 'received_at', 'properties', 'revenue', 'revenue_trusted', 'transaction_id'];
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $e[$path . '.' . $key] = 'is not a field here (allowed: ' . implode(', ', $allowed) . ')';
            }
        }

        $eventId = $raw['event_id'] ?? null;
        if (!is_string($eventId) || preg_match(self::EVENT_ID, $eventId) !== 1) {
            $e[$path . '.event_id'] = 'must be 1-128 printable characters without spaces, not starting with "@"';
        }
        $name = $raw['name'] ?? null;
        if (!is_string($name) || preg_match(self::EVENT_NAME, $name) !== 1) {
            $e[$path . '.name'] = 'must be an event name: 1-64 of letters, digits, _ . : -, starting with a letter, digit or _';
        }
        foreach (['occurred_at', 'received_at'] as $t) {
            if (!is_int($raw[$t] ?? null) || $raw[$t] < 0 || $raw[$t] > 4294967295) {
                $e[$path . '.' . $t] = 'must be a unix time in seconds';
            }
        }
        $properties = $raw['properties'] ?? [];
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            $e[$path . '.properties'] = 'must be an object';
            $properties = [];
        } elseif (count($properties) > self::MAX_PROPERTIES) {
            $e[$path . '.properties'] = 'may hold at most ' . self::MAX_PROPERTIES . ' properties';
        } else {
            foreach ($properties as $k => $v) {
                if (preg_match(self::PROP_NAME, (string) $k) !== 1) {
                    $e[$path . '.properties.' . $k] = 'is not a property name (a letter or _, then letters, digits or _, up to 64)';
                } elseif (!(is_bool($v) || GoalDefinition::isNumber($v) || (is_string($v) && strlen($v) <= GoalDefinition::MAX_STRING))) {
                    $e[$path . '.properties.' . $k] = 'must be a string (up to 255 bytes), a number or a bool';
                }
            }
        }
        $revenue = $raw['revenue'] ?? null;
        if ($revenue !== null && !GoalDefinition::isNumber($revenue)) {
            $e[$path . '.revenue'] = 'must be a number or null';
        }
        $trusted = $raw['revenue_trusted'] ?? false;
        if (!is_bool($trusted)) {
            $e[$path . '.revenue_trusted'] = 'must be true or false';
        }
        $tx = $raw['transaction_id'] ?? null;
        if ($tx !== null && (!is_string($tx) || trim($tx) === '' || strlen($tx) > 255)) {
            $e[$path . '.transaction_id'] = 'must be a non-empty string of up to 255 bytes, or null';
        }

        if ($e !== []) {
            ksort($e);
            throw new InvalidGoalDefinition($e, 'The event is invalid');
        }

        /** @var array<string, string|int|float|bool> $properties */
        return new self(
            (string) $eventId,
            (string) $name,
            (int) $raw['occurred_at'],
            (int) $raw['received_at'],
            $properties,
            $revenue,
            $trusted === true,
            $tx === null ? null : trim((string) $tx),
            false,
        );
    }

    /**
     * The value of a property as a goal reads it: `$revenue` is the event's
     * own revenue field. Null when absent.
     */
    public function property(string $prop): string|int|float|bool|null
    {
        if ($prop === GoalDefinition::REVENUE_PROP) {
            return $this->revenue;
        }

        return $this->properties[$prop] ?? null;
    }

    /**
     * A digest of everything the event says, so a reused event id carrying
     * different content can be told from a retry (CLAUDE.md #15: the
     * discriminator lives in the record, not in the key that finds it).
     * Every field is length-prefixed, so no two events share a digest by
     * moving a delimiter (#17).
     */
    public function fingerprint(): string
    {
        $props = $this->properties;
        ksort($props, SORT_STRING);
        $parts = [
            $this->eventId,
            (string) $this->name,
            (string) $this->occurredAt,
            json_encode($props, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->revenue === null ? '' : json_encode($this->revenue, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $this->transactionId ?? '',
        ];
        $buf = '';
        foreach ($parts as $p) {
            $buf .= strlen($p) . ':' . $p;
        }

        return hash('sha256', $buf);
    }
}

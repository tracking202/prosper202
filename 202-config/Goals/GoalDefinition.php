<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;

/**
 * One validated goal definition (plan §5.5). Data only: there is no
 * expression evaluation anywhere, so a definition cannot inject anything,
 * and its complexity is bounded so evaluation is O(goals) per event.
 *
 * The schema, strict at every level (an unknown key is an error, never
 * ignored — a misspelled `treshold` silently falling back to "the first
 * event" would pay for the wrong thing; CLAUDE.md #4), and typed (a count
 * of "3" or 3.0 is refused rather than cast; #18):
 *
 *   name       string, 1–100 characters after trimming, no control characters
 *   trigger    {"event": <event name>, "where": [<predicate>…]}  or  {"install": true}
 *   threshold  {"count": 1–10000}  or  {"sum": {"prop": <prop>, "gte": <amount > 0>}}
 *              (absent: {"count": 1})
 *   after      [<goal id>…], at most 5, distinct, never the goal itself (absent: [])
 *   within     {"days": 1–3650, "from": "install" | "click"}  (absent or null: no window)
 *   repeat     {"mode": "once"}  or  {"mode": "each"} / {"mode": "each", "max": 1–10000}
 *              (absent: once); a sum threshold with "each" requires max
 *   value      {"type": "fixed", "amount": <amount ≥ 0>}
 *              {"type": "from_property"} / {"type": "from_property", "prop": <prop>}
 *              {"type": "none"}   (absent: none)
 *
 *   predicate  {"prop": <prop>, "op": "eq"|"neq"|"gt"|"gte"|"lt"|"lte"|"in"|"exists", "value": …}
 *              eq/neq take a string, number or bool; gt/gte/lt/lte a number;
 *              in a list of 1–50 of those; exists takes no value.
 *              At most 20 predicates, ANDed; a second goal expresses OR.
 *   event name 1–64 of A–Z a–z 0–9 _ . : - , not starting with . : or -
 *   prop       a property name (a letter or _, then letters, digits, _; up
 *              to 64) or `$revenue`, the event's own revenue field
 *   amount     a number or a decimal string with at most 5 decimal places,
 *              up to 999999.99999 (the ledger's decimal(11,5))
 *
 * An install trigger is the install itself, so it takes no `where`, a count
 * of 1, `repeat: once`, no `after`, no `within`, and no `from_property`
 * value (an install carries no revenue).
 */
final class GoalDefinition
{
    public const MAX_NAME = 100;
    public const MAX_PREDICATES = 20;
    public const MAX_AFTER = 5;
    public const MAX_IN = 50;
    public const MAX_STRING = 255;
    public const MAX_COUNT = 10000;
    public const MAX_DAYS = 3650;
    public const MAX_REPEAT = 10000;
    /** The ledger stores amounts as decimal(11,5). */
    public const MAX_AMOUNT_UNITS = 99999999999;
    /**
     * The largest summand a sum threshold adds, either sign: an amount the
     * ledger can hold. A summed property outside ±999999.99999 does not
     * count, exactly like one that is not a number.
     */
    public const MAX_SUMMAND_UNITS = self::MAX_AMOUNT_UNITS;
    /** A stored definition longer than this is refused on load. */
    public const MAX_JSON_BYTES = 16384;

    public const REVENUE_PROP = '$revenue';
    public const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'exists'];

    private const EVENT_NAME = '/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$/D';
    private const PROP_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    /**
     * @param list<array{prop: string, op: string, value?: mixed}> $where
     * @param list<int> $after
     */
    private function __construct(
        public readonly string $name,
        public readonly bool $triggerInstall,
        public readonly ?string $triggerEvent,
        public readonly array $where,
        public readonly string $thresholdKind,
        public readonly int $count,
        public readonly ?string $sumProp,
        public readonly int $sumGteUnits,
        public readonly array $after,
        public readonly ?int $withinDays,
        public readonly ?string $withinFrom,
        public readonly string $repeatMode,
        public readonly ?int $repeatMax,
        public readonly string $valueType,
        public readonly ?int $valueUnits,
        public readonly ?string $valueProp,
    ) {
    }

    /**
     * Parse and validate a definition.
     *
     * @param mixed $raw The decoded JSON (an array for an object).
     * @param int|null $selfId The goal's own id when it has one, so `after`
     *        cannot name the goal itself.
     * @throws InvalidGoalDefinition naming every error by path
     */
    public static function parse(mixed $raw, ?int $selfId = null): self
    {
        $e = [];
        if (!self::isObject($raw)) {
            throw new InvalidGoalDefinition(['definition' => 'must be a JSON object']);
        }
        /** @var array<string, mixed> $raw */
        self::unknownKeys($raw, ['name', 'trigger', 'threshold', 'after', 'within', 'repeat', 'value'], '', $e);

        // name
        $name = '';
        if (!array_key_exists('name', $raw)) {
            $e['name'] = 'is required';
        } elseif (!is_string($raw['name'])) {
            $e['name'] = 'must be a string';
        } else {
            $name = trim($raw['name']);
            if ($name === '') {
                $e['name'] = 'must not be empty';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $e['name'] = 'must be at most ' . self::MAX_NAME . ' characters';
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || !mb_check_encoding($name, 'UTF-8')) {
                $e['name'] = 'must be valid UTF-8 text without control characters';
            }
        }

        // trigger
        $triggerInstall = false;
        $triggerEvent = null;
        $where = [];
        if (!array_key_exists('trigger', $raw)) {
            $e['trigger'] = 'is required: {"event": "<name>"} or {"install": true}';
        } elseif (!self::isObject($raw['trigger'])) {
            $e['trigger'] = 'must be an object: {"event": "<name>"} or {"install": true}';
        } else {
            /** @var array<string, mixed> $t */
            $t = $raw['trigger'];
            if (array_key_exists('install', $t)) {
                self::unknownKeys($t, ['install'], 'trigger', $e);
                if ($t['install'] !== true) {
                    $e['trigger.install'] = 'must be true (an install trigger has no other form)';
                }
                $triggerInstall = true;
            } else {
                self::unknownKeys($t, ['event', 'where'], 'trigger', $e);
                if (!array_key_exists('event', $t)) {
                    $e['trigger.event'] = 'is required unless the trigger is {"install": true}';
                } elseif (!is_string($t['event']) || preg_match(self::EVENT_NAME, $t['event']) !== 1) {
                    $e['trigger.event'] = 'must be an event name: 1-64 of letters, digits, _ . : -, starting with a letter, digit or _';
                } else {
                    $triggerEvent = $t['event'];
                }
                if (array_key_exists('where', $t)) {
                    $where = self::parseWhere($t['where'], $e);
                }
            }
        }

        // threshold
        $thresholdKind = 'count';
        $count = 1;
        $sumProp = null;
        $sumGte = 0;
        if (array_key_exists('threshold', $raw)) {
            $th = $raw['threshold'];
            if (!self::isObject($th)) {
                $e['threshold'] = 'must be an object: {"count": N} or {"sum": {"prop": "<prop>", "gte": <amount>}}';
            } else {
                /** @var array<string, mixed> $th */
                self::unknownKeys($th, ['count', 'sum'], 'threshold', $e);
                $hasCount = array_key_exists('count', $th);
                $hasSum = array_key_exists('sum', $th);
                if ($hasCount === $hasSum) {
                    $e['threshold'] = 'must have exactly one of count or sum';
                } elseif ($hasCount) {
                    $count = self::intIn($th['count'], 1, self::MAX_COUNT, 'threshold.count', $e) ?? 1;
                } else {
                    $thresholdKind = 'sum';
                    $s = $th['sum'];
                    if (!self::isObject($s)) {
                        $e['threshold.sum'] = 'must be an object: {"prop": "<prop>", "gte": <amount>}';
                    } else {
                        /** @var array<string, mixed> $s */
                        self::unknownKeys($s, ['prop', 'gte'], 'threshold.sum', $e);
                        $sumProp = self::prop($s['prop'] ?? null, 'threshold.sum.prop', $e);
                        if (!array_key_exists('gte', $s)) {
                            $e['threshold.sum.gte'] = 'is required';
                        } else {
                            $units = self::amount($s['gte'], 'threshold.sum.gte', $e);
                            if ($units !== null && $units <= 0) {
                                $e['threshold.sum.gte'] = 'must be greater than 0';
                            }
                            $sumGte = $units ?? 0;
                        }
                    }
                }
            }
        }

        // after
        $after = [];
        if (array_key_exists('after', $raw)) {
            $a = $raw['after'];
            if (!is_array($a) || !array_is_list($a)) {
                $e['after'] = 'must be a list of goal ids';
            } elseif (count($a) > self::MAX_AFTER) {
                $e['after'] = 'may name at most ' . self::MAX_AFTER . ' goals';
            } else {
                foreach ($a as $i => $id) {
                    if (!is_int($id) || $id <= 0) {
                        $e['after[' . $i . ']'] = 'must be a goal id (a positive integer)';
                        continue;
                    }
                    if ($selfId !== null && $id === $selfId) {
                        $e['after[' . $i . ']'] = 'a goal cannot require itself';
                        continue;
                    }
                    if (in_array($id, $after, true)) {
                        $e['after[' . $i . ']'] = 'names goal ' . $id . ' twice';
                        continue;
                    }
                    $after[] = $id;
                }
            }
        }

        // within
        $withinDays = null;
        $withinFrom = null;
        if (array_key_exists('within', $raw) && $raw['within'] !== null) {
            $w = $raw['within'];
            if (!self::isObject($w)) {
                $e['within'] = 'must be an object: {"days": N, "from": "install" | "click"}, or null';
            } else {
                /** @var array<string, mixed> $w */
                self::unknownKeys($w, ['days', 'from'], 'within', $e);
                $withinDays = self::intIn($w['days'] ?? null, 1, self::MAX_DAYS, 'within.days', $e);
                $from = $w['from'] ?? null;
                if ($from !== 'install' && $from !== 'click') {
                    $e['within.from'] = 'must be "install" or "click"';
                } else {
                    $withinFrom = $from;
                }
            }
        }

        // repeat
        $repeatMode = 'once';
        $repeatMax = null;
        if (array_key_exists('repeat', $raw)) {
            $r = $raw['repeat'];
            if (!self::isObject($r)) {
                $e['repeat'] = 'must be an object: {"mode": "once"} or {"mode": "each", "max": N}';
            } else {
                /** @var array<string, mixed> $r */
                self::unknownKeys($r, ['mode', 'max'], 'repeat', $e);
                $mode = $r['mode'] ?? null;
                if ($mode !== 'once' && $mode !== 'each') {
                    $e['repeat.mode'] = 'must be "once" or "each"';
                } else {
                    $repeatMode = $mode;
                }
                if (array_key_exists('max', $r)) {
                    if ($mode === 'once') {
                        $e['repeat.max'] = 'applies only to mode "each"';
                    } else {
                        $repeatMax = self::intIn($r['max'], 1, self::MAX_REPEAT, 'repeat.max', $e);
                    }
                }
            }
        }

        // value
        $valueType = 'none';
        $valueUnits = null;
        $valueProp = null;
        if (array_key_exists('value', $raw)) {
            $v = $raw['value'];
            if (!self::isObject($v)) {
                $e['value'] = 'must be an object: {"type": "fixed", "amount": N}, {"type": "from_property"} or {"type": "none"}';
            } else {
                /** @var array<string, mixed> $v */
                $type = $v['type'] ?? null;
                if ($type === 'fixed') {
                    self::unknownKeys($v, ['type', 'amount'], 'value', $e);
                    $valueType = 'fixed';
                    if (!array_key_exists('amount', $v)) {
                        $e['value.amount'] = 'is required for a fixed value';
                    } else {
                        $valueUnits = self::amount($v['amount'], 'value.amount', $e);
                    }
                } elseif ($type === 'from_property') {
                    self::unknownKeys($v, ['type', 'prop'], 'value', $e);
                    $valueType = 'from_property';
                    $valueProp = array_key_exists('prop', $v)
                        ? self::prop($v['prop'], 'value.prop', $e)
                        : self::REVENUE_PROP;
                } elseif ($type === 'none') {
                    self::unknownKeys($v, ['type'], 'value', $e);
                } else {
                    $e['value.type'] = 'must be "fixed", "from_property" or "none"';
                }
            }
        }

        // A sum can jump past many multiples of its threshold in one event
        // (a $1,000 purchase against "every $0.01"), so a sum that repeats
        // must say how many times it can be reached: without a bound, one
        // event could reach billions of outcomes. A count cannot jump — it
        // grows by one per event — so a count's `each` may stay unbounded.
        if ($thresholdKind === 'sum' && $repeatMode === 'each' && $repeatMax === null && !isset($e['repeat.max'])) {
            $e['repeat.max'] = 'is required for a sum threshold with mode "each" (1-' . self::MAX_REPEAT
                . '): one event can cross many multiples of a sum, so a sum goal that repeats needs a bound';
        }

        if ($triggerInstall) {
            if ($where !== []) {
                $e['trigger.where'] = 'an install trigger takes no predicates';
            }
            if ($thresholdKind !== 'count' || $count !== 1) {
                $e['threshold'] = 'an install is reached once: the threshold must be {"count": 1}';
            }
            if ($repeatMode !== 'once') {
                $e['repeat'] = 'an install is reached once: repeat must be "once"';
            }
            if ($after !== []) {
                $e['after'] = 'an install trigger cannot wait for other goals';
            }
            if ($withinDays !== null) {
                $e['within'] = 'an install trigger takes no window';
            }
            if ($valueType === 'from_property') {
                $e['value.type'] = 'an install carries no properties or revenue: use "fixed" or "none"';
            }
        }

        if ($e !== []) {
            ksort($e);
            throw new InvalidGoalDefinition($e);
        }

        return new self(
            $name,
            $triggerInstall,
            $triggerEvent,
            $where,
            $thresholdKind,
            $count,
            $sumProp,
            $sumGte,
            $after,
            $withinDays,
            $withinFrom,
            $repeatMode,
            $repeatMax,
            $valueType,
            $valueUnits,
            $valueProp,
        );
    }

    /**
     * Parse a stored definition (the JSON text of a goal version).
     *
     * @throws InvalidGoalDefinition
     */
    public static function fromJson(string $json, ?int $selfId = null): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidGoalDefinition(['definition' => 'is longer than ' . self::MAX_JSON_BYTES . ' bytes']);
        }
        try {
            $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $ex) {
            throw new InvalidGoalDefinition(['definition' => 'is not valid JSON: ' . $ex->getMessage()]);
        }

        return self::parse($raw, $selfId);
    }

    /**
     * The canonical form: every key present, defaults written out, amounts
     * as decimal strings. Two definitions that mean the same thing have the
     * same canonical form, which is what decides whether an edit is a new
     * version.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $trigger = $this->triggerInstall
            ? ['install' => true]
            : ['event' => $this->triggerEvent, 'where' => $this->where];
        $threshold = $this->thresholdKind === 'count'
            ? ['count' => $this->count]
            : ['sum' => ['prop' => $this->sumProp, 'gte' => self::formatUnits($this->sumGteUnits)]];
        $repeat = $this->repeatMode === 'once'
            ? ['mode' => 'once']
            : ($this->repeatMax === null ? ['mode' => 'each'] : ['mode' => 'each', 'max' => $this->repeatMax]);
        $value = match ($this->valueType) {
            'fixed' => ['type' => 'fixed', 'amount' => self::formatUnits((int) $this->valueUnits)],
            'from_property' => ['type' => 'from_property', 'prop' => $this->valueProp],
            default => ['type' => 'none'],
        };

        return [
            'name' => $this->name,
            'trigger' => $trigger,
            'threshold' => $threshold,
            'after' => $this->after,
            'within' => $this->withinDays === null ? null : ['days' => $this->withinDays, 'from' => $this->withinFrom],
            'repeat' => $repeat,
            'value' => $value,
        ];
    }

    /** The canonical JSON text, as stored in 202_goal_versions. */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * A plain event goal: reached by the first occurrence of one named
     * event, with nothing else to evaluate. Until the on-device evaluator
     * ships (plan §5.5), an SKAN encoding may only name such a goal: the iOS
     * SDK encodes by event name.
     */
    public function isPlainEvent(): bool
    {
        return !$this->triggerInstall
            && $this->where === []
            && $this->thresholdKind === 'count' && $this->count === 1
            && $this->after === []
            && $this->withinDays === null
            && $this->repeatMode === 'once';
    }

    /** Units of 0.00001 as the shortest decimal string with at least two places. */
    public static function formatUnits(int $units): string
    {
        $text = Amount::fromUnits($units);
        [$whole, $fraction] = explode('.', $text);
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $whole . '.' . $fraction;
    }

    /**
     * @param array<string, string> $e
     * @return list<array{prop: string, op: string, value?: mixed}>
     */
    private static function parseWhere(mixed $where, array &$e): array
    {
        if (!is_array($where) || !array_is_list($where)) {
            $e['trigger.where'] = 'must be a list of predicates';
            return [];
        }
        if (count($where) > self::MAX_PREDICATES) {
            $e['trigger.where'] = 'may hold at most ' . self::MAX_PREDICATES . ' predicates';
            return [];
        }
        $out = [];
        foreach ($where as $i => $p) {
            $path = 'trigger.where[' . $i . ']';
            if (!self::isObject($p)) {
                $e[$path] = 'must be an object: {"prop": "<prop>", "op": "<op>", "value": …}';
                continue;
            }
            /** @var array<string, mixed> $p */
            self::unknownKeys($p, ['prop', 'op', 'value'], $path, $e);
            $prop = self::prop($p['prop'] ?? null, $path . '.prop', $e);
            $op = $p['op'] ?? null;
            if (!is_string($op) || !in_array($op, self::OPS, true)) {
                $e[$path . '.op'] = 'must be one of ' . implode(', ', self::OPS);
                continue;
            }
            $hasValue = array_key_exists('value', $p);
            $value = $p['value'] ?? null;
            if ($op === 'exists') {
                if ($hasValue) {
                    $e[$path . '.value'] = '"exists" takes no value';
                }
                if ($prop !== null) {
                    $out[] = ['prop' => $prop, 'op' => 'exists'];
                }
                continue;
            }
            if (!$hasValue) {
                $e[$path . '.value'] = 'is required for "' . $op . '"';
                continue;
            }
            if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
                if (!self::isNumber($value)) {
                    $e[$path . '.value'] = '"' . $op . '" compares numbers: the value must be a number';
                    continue;
                }
            } elseif ($op === 'in') {
                if (!is_array($value) || !array_is_list($value) || $value === []) {
                    $e[$path . '.value'] = '"in" takes a non-empty list';
                    continue;
                }
                if (count($value) > self::MAX_IN) {
                    $e[$path . '.value'] = '"in" takes at most ' . self::MAX_IN . ' values';
                    continue;
                }
                $bad = false;
                foreach ($value as $j => $item) {
                    if (!self::isScalarValue($item)) {
                        $e[$path . '.value[' . $j . ']'] = 'must be a string (up to ' . self::MAX_STRING . ' bytes), a number or a bool';
                        $bad = true;
                    }
                }
                if ($bad) {
                    continue;
                }
            } elseif (!self::isScalarValue($value)) {
                $e[$path . '.value'] = 'must be a string (up to ' . self::MAX_STRING . ' bytes), a number or a bool';
                continue;
            }
            if ($prop !== null) {
                $out[] = ['prop' => $prop, 'op' => $op, 'value' => $value];
            }
        }

        return $out;
    }

    /** A JSON object: an associative array (an empty one decodes the same as []). */
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    /** A finite JSON number; a bool is not one. */
    public static function isNumber(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v));
    }

    private static function isScalarValue(mixed $v): bool
    {
        return is_bool($v) || self::isNumber($v) || (is_string($v) && strlen($v) <= self::MAX_STRING);
    }

    /**
     * @param array<string, mixed> $obj
     * @param list<string> $allowed
     * @param array<string, string> $e
     */
    private static function unknownKeys(array $obj, array $allowed, string $path, array &$e): void
    {
        foreach (array_keys($obj) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $e[($path === '' ? '' : $path . '.') . $key] = 'is not a field here (allowed: ' . implode(', ', $allowed) . ')';
            }
        }
    }

    /** @param array<string, string> $e */
    private static function intIn(mixed $v, int $min, int $max, string $path, array &$e): ?int
    {
        if (!is_int($v)) {
            $e[$path] = 'must be an integer from ' . $min . ' to ' . $max;
            return null;
        }
        if ($v < $min || $v > $max) {
            $e[$path] = 'must be from ' . $min . ' to ' . $max;
            return null;
        }

        return $v;
    }

    /** @param array<string, string> $e */
    private static function prop(mixed $v, string $path, array &$e): ?string
    {
        if (is_string($v) && ($v === self::REVENUE_PROP || preg_match(self::PROP_NAME, $v) === 1)) {
            return $v;
        }
        $e[$path] = 'must be a property name (a letter or _, then letters, digits or _, up to 64) or "$revenue"';

        return null;
    }

    /** @param array<string, string> $e */
    private static function amount(mixed $v, string $path, array &$e): ?int
    {
        $units = self::amountUnits($v);
        if ($units === null) {
            $e[$path] = 'must be an amount from 0 to 999999.99999 with at most 5 decimal places';
        }

        return $units;
    }

    /**
     * A definition amount in units, or null when it is not one: a number or
     * a plain decimal string, not negative, at most 5 decimal places, and
     * small enough for the ledger's column.
     */
    public static function amountUnits(mixed $v): ?int
    {
        if (is_string($v)) {
            if (preg_match('/^\d{1,6}(\.\d{1,5})?$/D', $v) !== 1) {
                return null;
            }
            $units = Amount::toUnits($v);
        } elseif (self::isNumber($v)) {
            if ($v < 0 || $v > 999999.99999) {
                return null;
            }
            $units = Amount::toUnits($v);
            if (abs((float) $v - $units / 100000) > 1e-9) {
                return null; // more than five decimal places
            }
        } else {
            return null;
        }

        return $units >= 0 && $units <= self::MAX_AMOUNT_UNITS ? $units : null;
    }
}

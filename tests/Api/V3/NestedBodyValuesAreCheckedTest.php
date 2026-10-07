<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A request body's nested objects and lists are checked before they are read
 * (CLAUDE.md #4).
 *
 * PayloadHandlersRefuseUnknownKeysTest holds every handler to refusing the
 * body's top-level keys. The objects inside were read by hand, for the keys
 * the reader knew, and their values cast: a line item's `unit_pirce` stored a
 * line at 0, `customer_crm.frist_name` was dropped, `"events":
 * "revenue.recorded"` subscribed a webhook to every event, and
 * `"prune_denylist": "campaigns"` protected nothing — each answered 2xx. They
 * now go through PayloadKeys::objectErrors(), listErrors() or
 * valueListErrors(), which refuse a key or value by its place in the body
 * (`items.0.unit_pirce`), handed to PayloadKeys::refuse().
 *
 * What this test reads, from the tokens of every PHP file outside tests/: a
 * use of `$payload['K']` as a structure, in a function that has a
 * `$payload` (the name is the heuristic: the request body is called that in
 * every handler, and in the repositories handed one). A structure use is
 *
 *  - an index into it: `$payload['K']['x']`, `isset($payload['K'][0])` (a
 *    write — `$payload['K']['x'] = …`, `[] =`, `++`, `unset()` — is not a read);
 *  - `foreach ($payload['K'] as …)`;
 *  - `$payload['K'] ?? []` (or `?? array()`), and an `(array)` cast of it;
 *  - the array argument of an array function, alone or as `$payload['K'] ??
 *    …`: is_array, array_is_list, count, array_keys, array_values,
 *    array_filter, array_map, array_merge, array_replace, array_combine,
 *    array_intersect_key, array_diff_key, array_column, array_unique,
 *    array_slice, array_sum, array_flip, array_walk, array_key_first,
 *    array_key_last, iterator_to_array, reset, end, in_array, array_search,
 *    array_key_exists, key_exists and implode (STRUCTURE_ARGUMENTS).
 *
 * Such a use is checked when one of these holds:
 *
 *  1. Earlier in the same function, a statement of its own directly in the
 *     function body (not inside an if, a loop or a closure) is a call to
 *     PayloadKeys::refuse() whose arguments hold
 *     `PayloadKeys::objectErrors|listErrors|valueListErrors($payload, 'K', …)`,
 *     or a call `self::h($payload…)`, `static::h($payload…)` or
 *     `$this->h($payload…)` to a method of the same class whose first
 *     parameter is `$payload` and one of whose `return` statements holds
 *     that check. The check's answer must reach refuse(): one whose result
 *     is thrown away, or that sits in a branch, checks nothing. The class
 *     is the one `use` and the namespace resolve the name to, so a
 *     lookalike `MyPayloadKeys` is not it.
 *  2. The function is private or protected, and every call of it in the
 *     same class sits in a function that checks K before the call (by 1,
 *     or by 2 again): a helper that reads what its callers checked.
 *  3. It is listed in REGISTERED: read by a function the handlers hand the
 *     body to (a repository), with the handlers that check K before calling
 *     it — each held to rule 1 before its first call of that function's
 *     name; or free-form by design, or not a request body, with text the
 *     function must still contain.
 *
 * What it cannot see, and says so in CLAUDE.md #4: a nested value copied
 * into another variable first (`$items = $payload['items']`, then
 * `foreach ($items …)`), one passed on whole without any structure use
 * (`$data['items'] = $payload['items']`), a body under another name
 * (MysqlConversionRepository's `$data`), an array function it does not list,
 * and whether a registered handler calls the registered function or one of
 * the same name on another object. testThePlantedShapesAreReadAsTheyAre
 * holds the scanner to every shape and form above.
 */
final class NestedBodyValuesAreCheckedTest extends TestCase
{
    /**
     * Functions whose structure uses of $payload are checked elsewhere, or
     * need no check: 'Class::function' => ['K' => entry]. An entry is
     * ['why' => …, 'checked_by' => [handler…]] (each handler held to rule 1
     * before its first call of this function's name, or of 'calls' when the
     * handlers reach it through another method of its class) or ['why' => …,
     * 'contains' => ['Class::function' => text…]] (each function must still
     * contain the text).
     */
    private const REGISTERED = [
        'MysqlCustomerCrmRepository::upsert' => [
            'aliases' => [
                'why' => 'The repository behind POST /ltv/customers and PATCH /ltv/customers/{id}; both check '
                    . 'each alias (LtvBody::alias). The customer page (tracking202/ajax/ltv_customer.php) calls '
                    . 'it with a body it builds itself, which carries no aliases.',
                'checked_by' => ['LtvController::upsertCustomer', 'LtvController::patchCustomer'],
            ],
        ],
        'MysqlCustomerCrmRepository::applyCustomFields' => [
            'custom_fields' => [
                'why' => 'Free-form by design: keyed by the account\'s own custom fields, so its keys are checked '
                    . 'against their definitions here (an unknown key is refused by name), and each value by its '
                    . 'field\'s type (MysqlCustomerFieldRepository::coerce(), which refuses an object or a list).',
                'contains' => [
                    'MysqlCustomerCrmRepository::applyCustomFields' => 'Unknown custom field "',
                    'MysqlCustomerFieldRepository::coerce' => 'if (!is_scalar($value)) {',
                ],
            ],
        ],
        'MysqlCustomerFieldRepository::update' => [
            'options' => [
                'why' => 'The repository behind PATCH /ltv/fields/{id}, which checks the list '
                    . '(LtvController::optionErrors).',
                'checked_by' => ['LtvController::updateField'],
            ],
        ],
        'MysqlSubscriptionRepository::resolveCustomer' => [
            'customer_crm' => [
                'why' => 'Reached from the repository\'s upsert(), behind POST /ltv/subscriptions, which checks '
                    . 'it; the repository also refuses one that is not an array.',
                'checked_by' => ['LtvController::upsertSubscription'],
                'calls' => 'upsert',
            ],
        ],
        'LtvController::createIntegration' => [
            'config' => [
                'why' => 'Free-form by design: the provider\'s own settings, stored as sent and read by nothing '
                    . 'here, so no key is refused. It must be an object, which this handler checks.',
                'contains' => ['LtvController::createIntegration' => 'array_is_list($payload[\'config\'])'],
            ],
        ],
        'RotatorsController::ruleParts' => [
            'criteria' => [
                'why' => 'A rule\'s entries are checked by refuseNestedKeys(), which predates PayloadKeys\' '
                    . 'nested checks and also holds each entry\'s read-only keys to the rule\'s own values; both '
                    . 'handlers that read a rule call it first.',
                'contains' => [
                    'RotatorsController::createRule' => 'self::refuseNestedKeys($payload, null);',
                    'RotatorsController::updateRule' => 'self::refuseNestedKeys($payload, $rule);',
                ],
            ],
            'redirects' => [
                'why' => 'As criteria: refuseNestedKeys() checks every redirect first.',
                'contains' => [
                    'RotatorsController::createRule' => 'self::refuseNestedKeys($payload, null);',
                    'RotatorsController::updateRule' => 'self::refuseNestedKeys($payload, $rule);',
                ],
            ],
        ],
        'RotatorsController::updateRule' => [
            'criteria' => [
                'why' => 'refuseNestedKeys() has checked every criterion by the time the update tests the '
                    . 'list\'s type.',
                'contains' => ['RotatorsController::updateRule' => 'self::refuseNestedKeys($payload, $rule);'],
            ],
            'redirects' => [
                'why' => 'As criteria.',
                'contains' => ['RotatorsController::updateRule' => 'self::refuseNestedKeys($payload, $rule);'],
            ],
        ],
        'IntegrityPolicy::judge' => [
            'appIntegrity' => [
                'why' => 'Not a request body: Google\'s decoded integrity verdict, whose fields are Google\'s.',
                'contains' => ['IntegrityPolicy::judge' => "\$payload['appIntegrity']"],
            ],
            'deviceIntegrity' => [
                'why' => 'Not a request body: Google\'s decoded integrity verdict.',
                'contains' => ['IntegrityPolicy::judge' => "\$payload['deviceIntegrity']"],
            ],
            'accountDetails' => [
                'why' => 'Not a request body: Google\'s decoded integrity verdict.',
                'contains' => ['IntegrityPolicy::judge' => "\$payload['accountDetails']"],
            ],
        ],
    ];

    /**
     * The array functions whose argument at one of these positions is read
     * as an array; null is any position.
     *
     * @var array<string, list<int>|null>
     */
    private const STRUCTURE_ARGUMENTS = [
        'is_array' => [0], 'array_is_list' => [0], 'count' => [0], 'array_keys' => [0], 'array_values' => [0],
        'array_filter' => [0], 'array_map' => null, 'array_merge' => null, 'array_replace' => null,
        'array_combine' => null, 'array_intersect_key' => null, 'array_diff_key' => null, 'array_column' => [0],
        'array_unique' => [0], 'array_slice' => [0], 'array_sum' => [0], 'array_flip' => [0], 'array_walk' => [0],
        'array_key_first' => [0], 'array_key_last' => [0], 'iterator_to_array' => [0], 'reset' => [0], 'end' => [0],
        'in_array' => [1], 'array_search' => [1], 'array_key_exists' => [1], 'key_exists' => [1], 'implode' => [1],
    ];

    private const CHECKS = ['objectErrors', 'listErrors', 'valueListErrors'];
    private const PAYLOAD_KEYS = 'Api\\V3\\Support\\PayloadKeys';
    private const WRITES = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_COALESCE_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_INC, T_DEC,
    ];
    private const OPEN = ['(', '[', '{'];
    private const CLOSE = [')', ']', '}'];

    /** The planted handler; %s is its body. */
    private const PLANTED = <<<'PHP'
        <?php
        namespace Api\V3\Controllers;
        use Api\V3\Support\PayloadKeys;
        use Other\MyPayloadKeys;
        final class Planted
        {
            public function handler(array $payload, array $body, string $key, bool $strict, bool $a, $v): void
            {
                %s
            }
            private static function itemErrors(array $payload): array
            {
                return PayloadKeys::listErrors($payload, 'items', ['sku'], 'a line item');
            }
            private static function otherErrors(array $payload): array
            {
                return PayloadKeys::listErrors($payload, 'other', ['sku'], 'x');
            }
            private static function discardingErrors(array $payload): array
            {
                PayloadKeys::listErrors($payload, 'items', ['sku'], 'x');
                return [];
            }
        }
        PHP;

    /** A private helper read by checked callers; %s is one more caller. */
    private const PLANTED_HELPER = <<<'PHP'
        <?php
        namespace Api\V3\Controllers;
        use Api\V3\Support\PayloadKeys;
        final class Planted
        {
            public function checked(array $payload): void
            {
                PayloadKeys::refuse(PayloadKeys::listErrors($payload, 'items', ['sku'], 'x'));
                $this->read($payload);
            }
            public function alsoChecked(array $payload): void
            {
                PayloadKeys::refuse(PayloadKeys::listErrors($payload, 'items', ['sku'], 'x'));
                $f = fn () => self::read($payload);
            }
            %s
            private function read(array $payload): void
            {
                foreach ($payload['items'] as $item) {
                }
            }
        }
        PHP;

    /** A repository that reads, and the handler that hands it the body (%s). */
    private const PLANTED_REPO = <<<'PHP'
        <?php
        namespace Api\V3\Controllers;
        final class Repo
        {
            public function upsert(array $payload): void
            {
                foreach ($payload['items'] as $item) {
                }
            }
        }
        PHP;
    private const PLANTED_HANDLER = <<<'PHP'
        <?php
        namespace Api\V3\Controllers;
        use Api\V3\Support\PayloadKeys;
        final class Handlers
        {
            public function create(array $payload): void
            {
                %s
            }
        }
        PHP;

    public function testEveryNestedValueOfABodyIsCheckedBeforeItIsRead(): void
    {
        $files = array_filter(
            SourceScan::phpFiles(),
            static fn (string $path): bool => !str_starts_with($path, 'tests/'),
            ARRAY_FILTER_USE_KEY
        );
        [$uses, $failures] = self::scan($files, self::REGISTERED);

        self::assertGreaterThanOrEqual(15, $uses, 'the scan found the nested reads (none found proves nothing)');
        self::assertSame([], $failures, "A nested value of a request body is read without its keys or values checked.\n"
            . "Hand it to PayloadKeys::objectErrors(), listErrors() or valueListErrors() inside a\n"
            . "PayloadKeys::refuse() statement before the read (see this test's docblock), or register\n"
            . "why it needs none:\n"
            . implode("\n", $failures));
    }

    /**
     * Every shape and form the docblock names, planted in a scratch file: each
     * defect is reported, each correct form is not.
     */
    public function testThePlantedShapesAreReadAsTheyAre(): void
    {
        $check = "PayloadKeys::refuse(PayloadKeys::listErrors(\$payload, 'items', ['sku'], 'x'));";
        $list = static fn (string $class, string $var, string $key): string
            => "$class::listErrors($var, '$key', ['sku'], 'x')";
        $reads = [
            'index' => "\$x = \$payload['items'][0];",
            'isset index' => "\$x = isset(\$payload['items'][0]['sku']);",
            'foreach' => "foreach (\$payload['items'] as \$item) {}",
            '?? []' => "\$items = \$payload['items'] ?? [];",
            '?? array()' => "\$items = \$payload['items'] ?? array();",
            '(array)' => "\$items = (array) \$payload['items'];",
            '(array) (… ?? null)' => "\$items = (array) (\$payload['items'] ?? null);",
            'is_array' => "\$ok = is_array(\$payload['items']);",
            'is_array(… ?? null)' => "\$ok = is_array(\$payload['items'] ?? null);",
            '\\is_array' => "\$ok = \\is_array(\$payload['items']);",
            'array_is_list' => "\$ok = array_is_list(\$payload['items']);",
            'count' => "\$n = count(\$payload['items']);",
            'array_map 2nd' => "\$m = array_map('strval', \$payload['items']);",
            'in_array haystack' => "\$ok = in_array('a', \$payload['items'], true);",
            'array_key_exists array' => "\$ok = array_key_exists('a', \$payload['items']);",
            'implode pieces' => "\$s = implode(',', \$payload['items']);",
        ];
        $notReads = [
            'scalar read' => "\$x = \$payload['items'];",
            'write' => "\$payload['items']['sku'] = 'A';",
            'append' => "\$payload['items'][] = 'A';",
            'increment' => "\$payload['items'][0]++;",
            'unset' => "unset(\$payload['items'][0]);",
            'in_array needle' => "\$ok = in_array(\$payload['items'], ['a'], true);",
            'array_key_exists key' => "\$ok = array_key_exists(\$payload['items'], ['a' => 1]);",
            'other function' => "\$s = strtolower(\$payload['items']);",
            'method of the same name' => "\$ok = \$this->count(\$payload['items']);",
            '?? null' => "\$x = \$payload['items'] ?? null;",
        ];

        $cases = [];
        foreach ($reads as $name => $read) {
            $cases["unchecked $name"] = [$read, true];
            $cases["checked $name"] = ["$check\n$read", false];
        }
        foreach ($notReads as $name => $code) {
            $cases["not a read: $name"] = [$code, false];
        }
        $read = $reads['foreach'];
        $full = '\\Api\\V3\\Support\\PayloadKeys';
        $then = static fn (string $first): string => "$first\n$read";
        $refuse = static fn (string $class, string $check): string => $then("$class::refuse($check);");
        $items = $list('PayloadKeys', '$payload', 'items');
        $cases += [
            'check after the read' => ["$read\n$check", true],
            'check result thrown away' => [$then($list('PayloadKeys', '$payload', 'items') . ';'), true],
            'check result kept, never refused' => [$then("\$e = $items;"), true],
            'check in a branch' => [$then("if (\$strict) { $check }"), true],
            'check in a closure' => [$then("\$f = function () use (\$payload) { $check };"), true],
            'check part of an expression' => [
                $then('$ok = $a && PayloadKeys::refuse(' . $list('PayloadKeys', '$payload', 'items') . ');'),
                true,
            ],
            'check of another key' => [$refuse('PayloadKeys', $list('PayloadKeys', '$payload', 'item')), true],
            'check of another variable' => [$refuse('PayloadKeys', $list('PayloadKeys', '$body', 'items')), true],
            'check by a lookalike class' => [
                $then('MyPayloadKeys::refuse(' . $list('MyPayloadKeys', '$payload', 'items') . ');'),
                true,
            ],
            'refuse of a lookalike' => [$refuse('MyPayloadKeys', $items), true],
            'check by its full name' => [$then("$full::refuse(" . $list($full, '$payload', 'items') . ');'), false],
            'objectErrors' => [$refuse('PayloadKeys', "PayloadKeys::objectErrors(\$payload, 'items', [], 'x')"), false],
            'valueListErrors' => [
                $then("PayloadKeys::refuse(PayloadKeys::valueListErrors(\$payload, 'items', 'x', \$v));"),
                false,
            ],
            'one of several checks' => [
                $then("PayloadKeys::refuse(PayloadKeys::objectErrors(\$payload, 'crm', [], 'x') + "
                    . $list('PayloadKeys', '$payload', 'items') . ');'),
                false,
            ],
            'helper by self::' => [$then('PayloadKeys::refuse(self::itemErrors($payload));'), false],
            'helper by static::' => [$then('PayloadKeys::refuse(static::itemErrors($payload));'), false],
            'helper by $this->' => [$then('PayloadKeys::refuse($this->itemErrors($payload));'), false],
            'helper given another variable' => [$then('PayloadKeys::refuse(self::itemErrors($body));'), true],
            'helper that checks another key' => [$then('PayloadKeys::refuse(self::otherErrors($payload));'), true],
            'helper whose check is not returned' => [$refuse('PayloadKeys', 'self::discardingErrors($payload)'), true],
            'a non-literal key' => ["foreach (\$payload[\$key] as \$item) {}", true],
        ];

        $failures = [];
        foreach ($cases as $name => [$body, $reported]) {
            [, $found] = self::scan(['planted/Planted.php' => sprintf(self::PLANTED, $body)], []);
            if (($found !== []) !== $reported) {
                $failures[] = "$name: " . self::verdict($reported, $found);
            }
        }

        // Rule 2: a private helper read by callers that check, and one that is not.
        $planted = [
            'every caller checks' => ['', false],
            'a caller that does not' => [
                "public function unchecked(array \$payload): void\n{\n\$this->read(\$payload);\n}",
                true,
            ],
            'a caller that checks after' => [
                "public function late(array \$payload): void\n{\n\$this->read(\$payload);\n$check\n}",
                true,
            ],
        ];
        foreach ($planted as $name => [$caller, $reported]) {
            [, $found] = self::scan(['planted/Planted.php' => sprintf(self::PLANTED_HELPER, $caller)], []);
            if (($found !== []) !== $reported) {
                $failures[] = "private helper, $name: " . self::verdict($reported, $found);
            }
        }
        $public = str_replace('private function read', 'public function read', sprintf(self::PLANTED_HELPER, ''));
        [, $found] = self::scan(['planted/Planted.php' => $public], []);
        if ($found === []) {
            $failures[] = 'a public function read by checked callers: not reported (its other callers are unknown)';
        }

        // Rule 3: a registered function is held to its handlers, and a stale entry is reported.
        $registry = ['Repo::upsert' => ['items' => ['why' => 'planted', 'checked_by' => ['Handlers::create']]]];
        $call = '$this->repo->upsert($payload);';
        $registered = [
            'the handler checks before the call' => ["$check\n$call", false],
            'the handler checks after the call' => ["$call\n$check", true],
            'the handler never calls it' => [$check, true],
            'the handler does not check' => [$call, true],
        ];
        foreach ($registered as $name => [$body, $reported]) {
            $files = [
                'planted/Repo.php' => self::PLANTED_REPO,
                'planted/Handlers.php' => sprintf(self::PLANTED_HANDLER, $body),
            ];
            [, $found] = self::scan($files, $registry);
            if (($found !== []) !== $reported) {
                $failures[] = "registered, $name: " . self::verdict($reported, $found);
            }
        }
        [, $found] = self::scan(['planted/Handlers.php' => sprintf(self::PLANTED_HANDLER, $check)], $registry);
        if ($found === []) {
            $failures[] = 'a registered function that no longer reads the key: not reported';
        }
        $contains = ['Repo::upsert' => ['items' => ['why' => 'planted', 'contains' => ['Repo::upsert' => 'no such']]]];
        [, $found] = self::scan(['planted/Repo.php' => self::PLANTED_REPO], $contains);
        if ($found === []) {
            $failures[] = 'a registered function that no longer contains its text: not reported';
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * @param array<string, string> $files path => source
     * @param array<string, array<string, array<string, mixed>>> $registry as REGISTERED
     * @return array{int, list<string>} the structure uses found, and the failures
     */
    private static function scan(array $files, array $registry): array
    {
        $functions = [];
        foreach ($files as $path => $source) {
            foreach (self::functions($path, $source) as $id => $function) {
                // Two classes of one short name would share an id; the later
                // one is kept under its path so neither is lost.
                $functions[isset($functions[$id]) ? "$path#$id" : $id] = $function;
            }
        }

        $failures = [];
        $uses = 0;
        $seen = [];
        foreach ($functions as $id => $function) {
            foreach (self::structureUses($function) as [$key, $at, $shape, $line]) {
                $uses++;
                $where = "{$function['path']}:$line $id";
                if ($key === null) {
                    $failures[] = "$where: \$payload[…] with a key that is not a string literal, used as a "
                        . "structure ($shape): name the key, so its check can be found";
                    continue;
                }
                $seen["$id|$key"] = true;
                $entry = $registry[$id][$key] ?? null;
                if ($entry !== null) {
                    foreach (self::registryProblems($functions, $id, $key, $entry) as $problem) {
                        $failures[] = "$where: '$key' is registered, but $problem";
                    }
                    continue;
                }
                if (!self::checkedAt($functions, $id, $key, $at, [])) {
                    $failures[] = "$where: \$payload['$key'] is read as a structure ($shape) and nothing checks it "
                        . 'first';
                }
            }
        }
        foreach ($registry as $id => $keys) {
            foreach (array_keys($keys) as $key) {
                if (!isset($seen["$id|$key"])) {
                    $failures[] = "REGISTERED names $id's '$key', which it no longer reads as a structure: remove it";
                }
            }
        }

        return [$uses, $failures];
    }

    /**
     * Whether a use of K at token $at in function $id is checked: rule 1 in
     * the function, or rule 2 through every caller of a private helper.
     *
     * @param array<string, array<string, mixed>> $functions
     * @param array<string, true> $visiting
     */
    private static function checkedAt(array $functions, string $id, string $key, int $at, array $visiting): bool
    {
        $function = $functions[$id];
        if (self::checksBefore($functions, $function, $key, $at)) {
            return true;
        }
        if ($function['visibility'] === 'public' || $function['class'] === null || isset($visiting[$id])) {
            return false;
        }
        $visiting[$id] = true;
        $calls = 0;
        foreach ($functions as $callerId => $caller) {
            if ($caller['path'] !== $function['path'] || $caller['class'] !== $function['class']) {
                continue;
            }
            foreach (self::callsOf($caller, $function['name']) as $callAt) {
                $calls++;
                if (!self::checkedAt($functions, $callerId, $key, $callAt, $visiting)) {
                    return false;
                }
            }
        }

        return $calls > 0;
    }

    /**
     * @param array<string, array<string, mixed>> $functions
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function registryProblems(array $functions, string $id, string $key, array $entry): array
    {
        $problems = [];
        $name = $entry['calls'] ?? $functions[$id]['name'];
        foreach ($entry['checked_by'] ?? [] as $handlerId) {
            if (!isset($functions[$handlerId])) {
                $problems[] = "its handler $handlerId is not a function in the tree";
                continue;
            }
            $calls = self::callsOf($functions[$handlerId], $name, true);
            if ($calls === []) {
                $problems[] = "$handlerId no longer calls $name()";
            } elseif (!self::checksBefore($functions, $functions[$handlerId], $key, $calls[0])) {
                $problems[] = "$handlerId does not check '$key' before it calls $name()";
            }
        }
        foreach ($entry['contains'] ?? [] as $holderId => $text) {
            if (!isset($functions[$holderId]) || !str_contains(self::text($functions[$holderId]['body']), $text)) {
                $problems[] = "$holderId no longer contains: $text";
            }
        }
        if (!isset($entry['checked_by']) && !isset($entry['contains'])) {
            $problems[] = 'the entry names neither handlers nor text';
        }

        return $problems;
    }

    /**
     * Rule 1: a refuse() statement directly in the function body, before
     * token $at, whose arguments check K on $payload directly or through a
     * same-class helper.
     *
     * @param array<string, array<string, mixed>> $functions
     * @param array<string, mixed> $function
     */
    private static function checksBefore(array $functions, array $function, string $key, int $at): bool
    {
        $body = $function['body'];
        $depth = 0;
        foreach ($body as $k => $token) {
            if ($k >= $at) {
                return false;
            }
            if ($token === '{' || self::is($body, $k, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || self::payloadKeysCall($function, $k, ['refuse']) === null) {
                continue;
            }
            $before = self::prev($body, $k);
            if ($before === null || !in_array($body[$before], [';', '{', '}'], true)) {
                continue; // part of a larger expression: it may not run
            }
            $open = self::next($body, self::next($body, self::next($body, $k)));
            $close = self::matching($body, (int) $open);
            if ($close >= $at) {
                continue;
            }
            for ($j = (int) $open + 1; $j < $close; $j++) {
                if (self::checkAt($function, $j, $key)) {
                    return true;
                }
                $helper = self::helperCall($body, $j);
                if ($helper !== null && self::helperChecks($functions, $function, $helper, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether token $j of the function's body starts
     * `PayloadKeys::objectErrors|listErrors|valueListErrors($payload, 'K'`.
     *
     * @param array<string, mixed> $function
     */
    private static function checkAt(array $function, int $j, string $key): bool
    {
        $body = $function['body'];
        if (self::payloadKeysCall($function, $j, self::CHECKS) === null) {
            return false;
        }
        $open = self::next($body, self::next($body, self::next($body, $j)));
        $arg = self::next($body, $open);
        $comma = self::next($body, $arg);
        $literal = self::next($body, $comma);

        return self::is($body, $arg, T_VARIABLE) && $body[$arg][1] === '$payload'
            && $comma !== null && $body[$comma] === ','
            && self::is($body, $literal, T_CONSTANT_ENCAPSED_STRING) && self::unquote($body[$literal][1]) === $key;
    }

    /**
     * The name of the same-class method called at token $j as
     * `self::h($payload…`, `static::h($payload…` or `$this->h($payload…`.
     *
     * @param list<array{0:int|string,1:string,2:int}|string> $body
     */
    private static function helperCall(array $body, int $j): ?string
    {
        $isSelf = self::isSelf($body, $j);
        $isThis = self::is($body, $j, T_VARIABLE) && $body[$j][1] === '$this';
        if (!$isSelf && !$isThis) {
            return null;
        }
        $op = self::next($body, $j);
        $name = self::next($body, $op);
        $open = self::next($body, $name);
        $arg = self::next($body, $open);
        $after = self::next($body, $arg);
        if (
            !self::is($body, $op, $isSelf ? T_DOUBLE_COLON : T_OBJECT_OPERATOR)
            || !self::is($body, $name, T_STRING) || $open === null || $body[$open] !== '('
            || !self::is($body, $arg, T_VARIABLE) || $body[$arg][1] !== '$payload'
            || $after === null || !in_array($body[$after], [',', ')'], true)
        ) {
            return null;
        }

        return $body[$name][1];
    }

    /**
     * Whether the same-class method $name takes `$payload` first and returns
     * a check of K on it from one of its `return` statements.
     *
     * @param array<string, array<string, mixed>> $functions
     * @param array<string, mixed> $caller
     */
    private static function helperChecks(array $functions, array $caller, string $name, string $key): bool
    {
        foreach ($functions as $function) {
            $same = $function['path'] === $caller['path'] && $function['class'] === $caller['class'];
            if (!$same || $function['name'] !== $name || $function['firstParam'] !== '$payload') {
                continue;
            }
            $body = $function['body'];
            foreach ($body as $k => $token) {
                if (!self::is($body, $k, T_RETURN)) {
                    continue;
                }
                for ($j = $k + 1; $j < count($body) && $body[$j] !== ';'; $j++) {
                    if (self::checkAt($function, $j, $key)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether token $k starts a static call `PayloadKeys::<one of $methods>(`
     * on Api\V3\Support\PayloadKeys, as the file's namespace and imports
     * resolve the class name.
     *
     * @param array<string, mixed> $function
     * @param list<string> $methods
     */
    private static function payloadKeysCall(array $function, int $k, array $methods): ?string
    {
        $body = $function['body'];
        if (!self::is($body, $k, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED)) {
            return null;
        }
        $before = self::prev($body, $k);
        $member = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NEW, T_FUNCTION];
        if (self::is($body, $before, ...$member)) {
            return null;
        }
        $op = self::next($body, $k);
        $name = self::next($body, $op);
        $open = self::next($body, $name);
        if (
            !self::is($body, $op, T_DOUBLE_COLON) || !self::is($body, $name, T_STRING)
            || !in_array($body[$name][1], $methods, true) || $open === null || $body[$open] !== '('
        ) {
            return null;
        }
        if (self::resolve($body[$k], $function['namespace'], $function['imports']) !== self::PAYLOAD_KEYS) {
            return null;
        }

        return $body[$name][1];
    }

    /**
     * @param array{0:int,1:string,2:int} $t
     * @param array<string, string> $imports
     */
    private static function resolve(array $t, string $namespace, array $imports): string
    {
        if ($t[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($t[1], '\\');
        }
        $first = explode('\\', $t[1])[0];
        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)] . substr($t[1], strlen($first));
        }

        return ($namespace === '' ? '' : $namespace . '\\') . $t[1];
    }

    /**
     * The calls of method $name in a function's body, by token index:
     * `$this->name(`, `self::name(`, `static::name(` — or, with $anyObject,
     * `->name(` and `::name(` on anything.
     *
     * @param array<string, mixed> $function
     * @return list<int>
     */
    private static function callsOf(array $function, string $name, bool $anyObject = false): array
    {
        $body = $function['body'];
        $calls = [];
        foreach ($body as $k => $t) {
            if (!self::is($body, $k, T_STRING) || $t[1] !== $name) {
                continue;
            }
            $op = self::prev($body, $k);
            $open = self::next($body, $k);
            if ($open === null || $body[$open] !== '(') {
                continue;
            }
            if (!self::is($body, $op, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON)) {
                continue;
            }
            if (!$anyObject) {
                $target = self::prev($body, (int) $op);
                $onThis = self::is($body, $op, T_OBJECT_OPERATOR) && self::is($body, $target, T_VARIABLE)
                    && $body[$target][1] === '$this';
                $onSelf = self::is($body, $op, T_DOUBLE_COLON) && $target !== null && self::isSelf($body, $target);
                if (!$onThis && !$onSelf) {
                    continue;
                }
            }
            $calls[] = $k;
        }

        return $calls;
    }

    /**
     * The structure uses of `$payload['K']` in a function: [K or null, token
     * index, shape, line].
     *
     * @param array<string, mixed> $function
     * @return list<array{?string, int, string, int}>
     */
    private static function structureUses(array $function): array
    {
        $body = $function['body'];
        $uses = [];
        foreach ($body as $i => $t) {
            if (!self::is($body, $i, T_VARIABLE) || $t[1] !== '$payload') {
                continue;
            }
            $open = self::next($body, $i);
            if ($open === null || $body[$open] !== '[') {
                continue;
            }
            $close = self::matching($body, $open);
            $keyAt = self::next($body, $open);
            $literal = self::is($body, $keyAt, T_CONSTANT_ENCAPSED_STRING) && self::next($body, $keyAt) === $close;
            $key = $literal ? self::unquote($body[$keyAt][1]) : null;
            $shape = self::shape($body, $i, $close);
            if ($shape !== null) {
                $uses[] = [$key, $i, $shape, (int) $t[2]];
            }
        }

        return $uses;
    }

    /**
     * How `$payload[…]` (token $i to $close) is used, when it is used as a
     * structure; null otherwise.
     *
     * @param list<array{0:int|string,1:string,2:int}|string> $body
     */
    private static function shape(array $body, int $i, int $close): ?string
    {
        $after = self::next($body, $close);
        $before = self::prev($body, $i);
        $beforeBefore = $before === null ? null : self::prev($body, $before);
        if ($after === null || $before === null) {
            return null;
        }
        if ($body[$after] === '[') {
            $end = $after;
            while ($end !== null && $body[$end] === '[') {
                $end = self::next($body, self::matching($body, $end));
            }
            $written = $end !== null && ($body[$end] === '=' || self::is($body, $end, ...self::WRITES))
                || self::is($body, $before, T_INC, T_DEC)
                || ($body[$before] === '(' && self::is($body, $beforeBefore, T_UNSET));

            return $written ? null : 'an index into it';
        }
        if ($body[$before] === '(' && self::is($body, $beforeBefore, T_FOREACH)) {
            return 'foreach';
        }
        if (self::is($body, $after, T_COALESCE)) {
            $fallback = self::next($body, $after);
            if ($fallback !== null && ($body[$fallback] === '[' || self::is($body, $fallback, T_ARRAY))) {
                return '?? []';
            }
        }
        $cast = self::is($body, $before, T_ARRAY_CAST);
        if ($cast || ($body[$before] === '(' && self::is($body, $beforeBefore, T_ARRAY_CAST))) {
            return '(array)';
        }
        $argumentEnd = $body[$after] === ',' || $body[$after] === ')' || self::is($body, $after, T_COALESCE);
        if (!in_array($body[$before], ['(', ','], true) || !$argumentEnd) {
            return null;
        }
        // The call it is an argument of, and its position there.
        $depth = 0;
        $position = 0;
        for ($k = $i - 1; $k >= 0; $k--) {
            $x = $body[$k];
            if (in_array($x, self::CLOSE, true)) {
                $depth++;
            } elseif (in_array($x, self::OPEN, true) || self::is($body, $k, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($x === ',' && $depth === 0) {
                $position++;
            } elseif ($x === ';' && $depth === 0) {
                return null;
            }
        }
        if ($k < 0 || $body[$k] !== '(') {
            return null;
        }
        $callee = self::prev($body, $k);
        if (!self::is($body, $callee, T_STRING, T_NAME_FULLY_QUALIFIED)) {
            return null;
        }
        // A method, or a declaration, of the same name is not the function.
        $calleeBefore = self::prev($body, (int) $callee);
        $member = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        if (self::is($body, $calleeBefore, ...$member)) {
            return null;
        }
        $name = strtolower(ltrim($body[$callee][1], '\\'));
        if (!array_key_exists($name, self::STRUCTURE_ARGUMENTS)) {
            return null;
        }
        $positions = self::STRUCTURE_ARGUMENTS[$name];

        return $positions === null || in_array($position, $positions, true) ? $name . '()' : null;
    }

    /**
     * Every named function and method in a file, keyed 'Class::name' (or
     * 'name' outside a class).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function functions(string $path, string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';
        $imports = [];
        $classes = [];
        for ($i = 0; $i < $count; $i++) {
            if (self::is($tokens, $i, T_NAMESPACE)) {
                $n = self::next($tokens, $i);
                if (self::is($tokens, $n, T_STRING, T_NAME_QUALIFIED)) {
                    $namespace = $tokens[$n][1];
                }
            } elseif (self::is($tokens, $i, T_USE) && $classes === []) {
                $n = self::next($tokens, $i);
                if (self::is($tokens, $n, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED)) {
                    $full = ltrim($tokens[$n][1], '\\');
                    $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
                    $as = self::next($tokens, $n);
                    if (self::is($tokens, $as, T_AS)) {
                        $aliasAt = self::next($tokens, $as);
                        $alias = self::is($tokens, $aliasAt, T_STRING) ? $tokens[$aliasAt][1] : $alias;
                    }
                    $imports[strtolower($alias)] = $full;
                }
            } elseif (self::is($tokens, $i, T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM)) {
                $n = self::next($tokens, $i);
                if (self::is($tokens, self::prev($tokens, $i), T_DOUBLE_COLON) || !self::is($tokens, $n, T_STRING)) {
                    continue; // Foo::class, or an anonymous class
                }
                $open = $i;
                while ($open < $count && $tokens[$open] !== '{') {
                    $open++;
                }
                $classes[] = [$tokens[$n][1], $open, self::matching($tokens, $open)];
            }
        }

        $functions = [];
        for ($i = 0; $i < $count; $i++) {
            if (!self::is($tokens, $i, T_FUNCTION)) {
                continue;
            }
            $n = self::next($tokens, $i);
            if ($n !== null && $tokens[$n] === '&') {
                $n = self::next($tokens, $n);
            }
            if (!self::is($tokens, $n, T_STRING)) {
                continue; // a closure: its tokens belong to the function around it
            }
            $paramsOpen = self::next($tokens, $n);
            if ($paramsOpen === null || $tokens[$paramsOpen] !== '(') {
                continue;
            }
            $paramsClose = self::matching($tokens, $paramsOpen);
            $firstParam = null;
            for ($k = $paramsOpen; $k < $paramsClose; $k++) {
                if (self::is($tokens, $k, T_VARIABLE)) {
                    $firstParam = $tokens[$k][1];
                    break;
                }
            }
            $bodyOpen = $paramsClose + 1;
            while ($bodyOpen < $count && $tokens[$bodyOpen] !== '{' && $tokens[$bodyOpen] !== ';') {
                $bodyOpen++;
            }
            if ($bodyOpen >= $count || $tokens[$bodyOpen] === ';') {
                continue; // abstract, or an interface's
            }
            $bodyClose = self::matching($tokens, $bodyOpen);
            $visibility = 'public';
            for ($k = $i - 1; $k >= 0 && !in_array($tokens[$k], [';', '{', '}'], true); $k--) {
                if (self::is($tokens, $k, T_PRIVATE)) {
                    $visibility = 'private';
                } elseif (self::is($tokens, $k, T_PROTECTED)) {
                    $visibility = 'protected';
                }
            }
            $class = null;
            foreach ($classes as [$name, $open, $close]) {
                if ($i > $open && $i < $close) {
                    $class = $name;
                }
            }
            $name = $tokens[$n][1];
            $functions[$class === null ? $name : "$class::$name"] = [
                'path' => $path,
                'class' => $class,
                'name' => $name,
                'visibility' => $class === null ? 'public' : $visibility,
                'firstParam' => $firstParam,
                'namespace' => $namespace,
                'imports' => $imports,
                // The body from its "{" to its "}": brace depth 1 is directly in it.
                'body' => array_slice($tokens, $bodyOpen, $bodyClose - $bodyOpen + 1),
            ];
        }

        return $functions;
    }

    /** @param list<string> $found */
    private static function verdict(bool $reported, array $found): string
    {
        return $reported ? 'not reported' : 'reported: ' . implode('; ', $found);
    }

    /**
     * Whether token $k exists and is one of the token types $ids.
     *
     * @param list<array{0:int|string,1:string,2:int}|string> $tokens
     */
    private static function is(array $tokens, ?int $k, int ...$ids): bool
    {
        return $k !== null && isset($tokens[$k]) && is_array($tokens[$k]) && in_array($tokens[$k][0], $ids, true);
    }

    /**
     * Whether token $k is `self` or `static`.
     *
     * @param list<array{0:int|string,1:string,2:int}|string> $tokens
     */
    private static function isSelf(array $tokens, int $k): bool
    {
        return self::is($tokens, $k, T_STATIC)
            || (self::is($tokens, $k, T_STRING) && in_array(strtolower($tokens[$k][1]), ['self', 'static'], true));
    }

    private static function unquote(string $literal): string
    {
        return stripcslashes(substr($literal, 1, -1));
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function next(array $tokens, ?int $i): ?int
    {
        if ($i === null) {
            return null;
        }
        for ($k = $i + 1, $n = count($tokens); $k < $n; $k++) {
            if (!self::is($tokens, $k, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT)) {
                return $k;
            }
        }

        return null;
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function prev(array $tokens, int $i): ?int
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (!self::is($tokens, $k, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT)) {
                return $k;
            }
        }

        return null;
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open, $n = count($tokens); $k < $n; $k++) {
            $t = $tokens[$k];
            if (in_array($t, self::OPEN, true) || self::is($tokens, $k, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif (in_array($t, self::CLOSE, true)) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        throw new \RuntimeException('unbalanced brackets at token ' . $open);
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));
    }
}

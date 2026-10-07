<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\StatementHelpers;
use Api\V3\Support\QueryInt;

class RotatorsController
{
    use StatementHelpers;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function list(array $params): array
    {
        $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
        $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');

        $stmt = $this->prepare('SELECT COUNT(*) as total FROM 202_rotators WHERE user_id = ?');
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Count query failed');
        $total = (int)$this->resultOf($stmt, 'Count query failed')->fetch_assoc()['total'];
        $stmt->close();

        $stmt = $this->prepare('SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp, auto_monetizer FROM 202_rotators WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $this->bind($stmt, 'iii', $this->userId, $limit, $offset);
        $this->execute($stmt, 'List query failed');
        $result = $this->resultOf($stmt, 'List query failed');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]];
    }

    public function get(int $id): array
    {
        $stmt = $this->prepare('SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp, auto_monetizer FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $this->resultOf($stmt, 'Query failed')->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Rotator not found');
        }

        // Batch-fetch rules, criteria, and redirects
        $stmt = $this->prepare('SELECT id, rotator_id, rule_name, splittest, status FROM 202_rotator_rules WHERE rotator_id = ? ORDER BY id ASC');
        $this->bind($stmt, 'i', $id);
        $this->execute($stmt, 'Query failed');
        $rules = [];
        $ruleIds = [];
        $result = $this->resultOf($stmt, 'Query failed');
        while ($r = $result->fetch_assoc()) {
            $r['criteria'] = [];
            $r['redirects'] = [];
            $rules[$r['id']] = $r;
            $ruleIds[] = $r['id'];
        }
        $stmt->close();

        if (!empty($ruleIds)) {
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $types = str_repeat('i', count($ruleIds));

            $cStmt = $this->prepare("SELECT id, rotator_id, rule_id, type, statement, value FROM 202_rotator_rules_criteria WHERE rule_id IN ($placeholders)");
            $this->bind($cStmt, $types, ...$ruleIds);
            $this->execute($cStmt, 'Query failed');
            $cr = $this->resultOf($cStmt, 'Query failed');
            while ($c = $cr->fetch_assoc()) {
                $rules[$c['rule_id']]['criteria'][] = $c;
            }
            $cStmt->close();

            $rStmt = $this->prepare("SELECT id, rule_id, redirect_url, redirect_campaign, redirect_lp, auto_monetizer, weight, name FROM 202_rotator_rules_redirects WHERE rule_id IN ($placeholders)");
            $this->bind($rStmt, $types, ...$ruleIds);
            $this->execute($rStmt, 'Query failed');
            $rr = $this->resultOf($rStmt, 'Query failed');
            while ($rd = $rr->fetch_assoc()) {
                $rules[$rd['rule_id']]['redirects'][] = $rd;
            }
            $rStmt->close();
        }

        $row['rules'] = array_values($rules);
        return ['data' => $row];
    }

    /**
     * Pick an unused public_id. 202_rotators has no UNIQUE key on the column,
     * so this is best-effort: it removes deliberate collisions and makes random
     * ones vanishingly unlikely.
     */
    private function publicIdIsFree(int $candidate): bool
    {
        $stmt = $this->prepare('SELECT id FROM 202_rotators WHERE public_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $candidate);
        $this->execute($stmt, 'Public id lookup failed');
        $taken = $this->resultOf($stmt, 'Public id lookup failed')->fetch_assoc();
        $stmt->close();

        return $taken === null || $taken === false;
    }

    private function generatePublicId(): int
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = random_int(100_000, 9_999_999);
            if ($this->publicIdIsFree($candidate)) {
                return $candidate;
            }
        }

        throw new DatabaseException('Unable to allocate a unique rotator public id');
    }

    /**
     * The keys a rotator is written with. public_id is honoured on create
     * when free (see create()), and fixed after.
     */
    private const ROTATOR_FIELDS = ['name', 'default_url', 'default_campaign', 'default_lp'];

    /**
     * What GET answers that no write here sets: rules are written through
     * /rotators/{id}/rules, the auto-monetizer by the Setup page. Accepted
     * on an update only with the values the rotator holds, so a GET body can
     * be sent back; refused on a create.
     */
    private const READ_ONLY = ['id', 'user_id', 'auto_monetizer', 'rules'];

    /** A rule's keys, on create and update alike. */
    private const RULE_FIELDS = ['rule_name', 'splittest', 'status', 'criteria', 'redirects'];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current the rotator an update changes
     * @param list<string> $readOnly
     */
    private static function refuseChangedReadOnly(array $payload, ?array $current, array $readOnly): void
    {
        $errors = \Api\V3\Support\PayloadKeys::changedReadOnly($payload, $readOnly, $current);
        if ($errors !== []) {
            throw new ValidationException('Read-only field', $errors);
        }
    }

    public function create(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, [...self::ROTATOR_FIELDS, 'public_id', ...self::READ_ONLY], 'a redirector');
        self::refuseChangedReadOnly($payload, null, self::READ_ONLY);
        $name = self::ruleOrRotatorName($payload, 'name', true);
        $default = $this->destination($payload, self::DEFAULT_KEYS, false);
        // public_id is the handle offrtr.php/rtr.php resolve for ANY visitor with
        // no user scoping, and 202_rotators has no unique key on it — so a
        // caller-chosen value that is ALREADY TAKEN would route another user's
        // clicks to this rotator (the lookup is then memcached). An integrity
        // bug within the install, not cross-install.
        //
        // The danger is collision, not caller choice, so honour a supplied
        // public_id when it is free and fall back to a generated one otherwise.
        // Rejecting it outright broke `p202 sync`: rotators are matched between
        // installs by public_id, so a server-assigned value meant the target
        // never matched the source — every run re-created every rotator, and
        // remapping trackers' rotator_id failed outright with "unresolvable
        // target foreign key".
        $publicId = 0;
        if (isset($payload['public_id']) && $payload['public_id'] !== '') {
            $requested = (int)$payload['public_id'];
            if ($requested > 0 && $this->publicIdIsFree($requested)) {
                $publicId = $requested;
            }
        }
        if ($publicId === 0) {
            $publicId = $this->generatePublicId();
        }

        $stmt = $this->prepare('INSERT INTO 202_rotators (public_id, user_id, name, default_url, default_campaign, default_lp) VALUES (?, ?, ?, ?, ?, ?)');
        $this->bind($stmt, 'iissii', $publicId, $this->userId, $name, $default['url'], $default['campaign'], $default['lp']);
        $this->execute($stmt, 'Create failed');
        $id = $stmt->insert_id;
        $stmt->close();

        // The rotator row exists; only the read-back can still fail.
        try {
            return $this->get($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('rotator', $e);
        }
    }

    public function update(int $id, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, [...self::ROTATOR_FIELDS, 'public_id', ...self::READ_ONLY], 'a redirector');
        // public_id is what the redirects resolve: it was ignored here, so a
        // caller who sent a new one believed the links had moved.
        self::refuseChangedReadOnly($payload, (array) $this->get($id)['data'], [...self::READ_ONLY, 'public_id']);

        $sets = [];
        $binds = [];
        $types = '';

        if (array_key_exists('name', $payload)) {
            $sets[] = 'name = ?';
            $binds[] = self::ruleOrRotatorName($payload, 'name', true);
            $types .= 's';
        }
        // The default is one destination, as Setup > Redirectors sets it:
        // naming any part of it replaces the whole default and clears the
        // rest, the auto-monetizer included. Setting only default_url left a
        // default campaign in place, and rtr.php, which prefers the campaign,
        // went on sending clicks there.
        if (array_intersect_key($payload, array_flip(self::DEFAULT_KEYS)) !== []) {
            $default = $this->destination($payload, self::DEFAULT_KEYS, false);
            $sets[] = 'default_url = ?, default_campaign = ?, default_lp = ?, auto_monetizer = NULL';
            array_push($binds, $default['url'], $default['campaign'], $default['lp']);
            $types .= 'sii';
        }

        if (empty($sets)) {
            throw new ValidationException('No fields to update');
        }

        $binds[] = $id;
        $types .= 'i';
        $binds[] = $this->userId;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_rotators SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?');
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Update failed');
        $stmt->close();

        return $this->get($id);
    }

    /** The payload keys of a rotator's default, in the order destination() reads them. */
    private const DEFAULT_KEYS = ['url' => 'default_url', 'campaign' => 'default_campaign', 'lp' => 'default_lp'];

    /** The payload keys of a rule redirect. */
    private const REDIRECT_KEYS = ['url' => 'redirect_url', 'campaign' => 'redirect_campaign', 'lp' => 'redirect_lp'];

    /** The criteria rtr.php and offrtr.php evaluate; any other type never matches. */
    private const CRITERIA_TYPES = ['country', 'region', 'city', 'isp', 'ip', 'platform', 'device', 'browser'];

    /**
     * One destination — a rotator's default or a rule redirect — read from
     * the raw request (CLAUDE.md #18), as Setup > Redirectors takes it: a URL,
     * one of the caller's live campaigns, or one of the caller's live landing
     * pages (the page's own ownership check), and at most one of them.
     *
     * The parts not chosen are NULL. The API stored 0 and '' there, and the
     * redirects tell a destination's kind by `redirect_campaign != null`:
     * '0' != null is true, so a URL redirect read as a campaign with no
     * campaign and the click was sent to an empty Location.
     *
     * Empty (null, '', 0, '0') means "not this kind".
     *
     * @param array<string, mixed> $raw
     * @param array{url: string, campaign: string, lp: string} $keys
     * @return array{url: ?string, campaign: ?int, lp: ?int}
     */
    private function destination(array $raw, array $keys, bool $required, string $where = ''): array
    {
        $out = ['url' => null, 'campaign' => null, 'lp' => null];
        $errors = [];
        foreach ($keys as $kind => $key) {
            $value = $raw[$key] ?? null;
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            }
            $field = $where . $key;
            if ($kind === 'url') {
                $url = is_string($value) ? trim($value) : '';
                $parts = $url !== '' ? parse_url($url) : false;
                if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '' || strlen($url) > 2048) {
                    $errors[$field] = 'Must be an http:// or https:// URL of at most 2048 characters';
                    continue;
                }
                $out['url'] = $url;
                continue;
            }
            $text = is_int($value) ? (string)$value : (is_string($value) ? $value : '');
            $table = $kind === 'campaign'
                ? 'SELECT 1 FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? AND COALESCE(aff_campaign_deleted, 0) = 0 LIMIT 1'
                : 'SELECT 1 FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? AND COALESCE(landing_page_deleted, 0) = 0 LIMIT 1';
            $list = $kind === 'campaign' ? '`p202 campaign list`' : '`p202 landing-page list`';
            if (preg_match('/^[1-9][0-9]{0,9}$/D', $text) !== 1 || (int)$text > 2147483647 || !$this->exists($table, (int)$text)) {
                $errors[$field] = 'Must be the id of one of your ' . ($kind === 'campaign' ? 'campaigns' : 'landing pages') . " ($list)";
                continue;
            }
            $out[$kind] = (int)$text;
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid destination', $errors);
        }
        $chosen = count(array_filter($out, static fn ($v): bool => $v !== null));
        if ($chosen > 1) {
            throw new ValidationException('A destination is one URL, campaign or landing page', [
                $where . $keys['url'] => 'Send one of ' . implode(', ', $keys) . '; this names ' . $chosen,
            ]);
        }
        if ($required && $chosen === 0) {
            throw new ValidationException('A redirect needs a destination', [
                rtrim($where, '.') ?: $keys['url'] => 'Send one of ' . implode(', ', $keys),
            ]);
        }

        return $out;
    }

    private function exists(string $sql, int $id): bool
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Ownership lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // "Not found" would refuse a valid id for a database failure.
            $stmt->close();
            throw new DatabaseException('Ownership lookup failed');
        }
        $found = $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    /** @param array<string, mixed> $payload */
    private static function ruleOrRotatorName(array $payload, string $key, bool $required): string
    {
        $value = $payload[$key] ?? '';
        $name = is_string($value) || is_int($value) ? trim((string)$value) : null;
        if ($name === null || ($required && $name === '') || mb_strlen($name) > 255) {
            throw new ValidationException("Invalid $key", [$key => 'Must be text of 1 to 255 characters']);
        }

        return $name;
    }

    /**
     * A 0/1 switch (splittest, status), read raw: '1.5' is not 1.
     *
     * @param array<string, mixed> $payload
     */
    private static function flag(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!in_array($value, [0, 1, '0', '1'], true)) {
            throw new ValidationException("Invalid $key", [$key => 'Must be 0 or 1']);
        }

        return (int)$value;
    }

    /**
     * A rule's criteria and redirects, checked whole before anything is
     * written. Each entry must be an object: an update that skipped a
     * malformed entry wrote the rest and said nothing (CLAUDE.md #4).
     *
     * @param array<string, mixed> $payload
     * @return array{criteria: ?list<array{type: string, statement: string, value: string}>, redirects: ?list<array{url: ?string, campaign: ?int, lp: ?int, weight: string, name: string}>}
     */
    private function ruleParts(array $payload): array
    {
        $out = ['criteria' => null, 'redirects' => null];
        if (array_key_exists('criteria', $payload) && $payload['criteria'] !== null) {
            if (!is_array($payload['criteria']) || !array_is_list($payload['criteria'])) {
                throw new ValidationException('criteria must be an array', ['criteria' => 'Expected an array of objects']);
            }
            $out['criteria'] = [];
            foreach ($payload['criteria'] as $i => $c) {
                $at = "criteria.$i";
                if (!is_array($c)) {
                    throw new ValidationException('Each criterion must be an object', [$at => 'Expected {"type", "statement", "value"}']);
                }
                $type = $c['type'] ?? null;
                $statement = $c['statement'] ?? 'is';
                $value = $c['value'] ?? null;
                if (!is_string($type) || !in_array($type, self::CRITERIA_TYPES, true)) {
                    throw new ValidationException('Unknown criterion type', ["$at.type" => 'One of ' . implode(', ', self::CRITERIA_TYPES) . '; the redirects ignore any other, so the rule would never match']);
                }
                if (!is_string($statement) || !in_array($statement, ['is', 'is_not'], true)) {
                    throw new ValidationException('Unknown criterion statement', ["$at.statement" => 'is or is_not']);
                }
                if ((!is_string($value) && !is_int($value)) || trim((string)$value) === '') {
                    throw new ValidationException('A criterion needs a value', ["$at.value" => 'Comma-separated values, e.g. "United States(US)" (`p202 rotator criteria-values`)']);
                }
                // The redirects compare a country as "Name(CC)". A bare code —
                // the form these docs once showed — never matches, so the
                // rule would sit there matching nothing.
                if ($type === 'country') {
                    foreach (explode(',', (string)$value) as $country) {
                        if (preg_match('/^\s*[A-Za-z]{2}\s*$/D', $country) === 1) {
                            throw new ValidationException('A country is written Name(CC)', ["$at.value" => '"' . trim($country) . '" never matches: write it as the redirects compare it, e.g. "United States(US)" (`p202 rotator criteria-values --search ' . trim($country) . '`)']);
                        }
                    }
                }
                $out['criteria'][] = ['type' => $type, 'statement' => $statement, 'value' => trim((string)$value)];
            }
        }
        if (array_key_exists('redirects', $payload) && $payload['redirects'] !== null) {
            if (!is_array($payload['redirects']) || !array_is_list($payload['redirects'])) {
                throw new ValidationException('redirects must be an array', ['redirects' => 'Expected an array of objects']);
            }
            $out['redirects'] = [];
            foreach ($payload['redirects'] as $i => $r) {
                $at = "redirects.$i";
                if (!is_array($r)) {
                    throw new ValidationException('Each redirect must be an object', [$at => 'Expected {"redirect_url"|"redirect_campaign"|"redirect_lp", "weight", "name"}']);
                }
                $destination = $this->destination($r, self::REDIRECT_KEYS, true, "$at.");
                $weight = $r['weight'] ?? 100;
                if ((!is_int($weight) && !is_string($weight)) || preg_match('/^(?:100|[1-9]?[0-9])$/D', (string)$weight) !== 1) {
                    throw new ValidationException('Invalid weight', ["$at.weight" => 'A whole number from 0 to 100']);
                }
                $name = $r['name'] ?? '';
                if ((!is_string($name) && !is_int($name)) || mb_strlen((string)$name) > 255) {
                    throw new ValidationException('Invalid redirect name', ["$at.name" => 'Text of at most 255 characters']);
                }
                $out['redirects'][] = $destination + ['weight' => (string)$weight, 'name' => (string)$name];
            }
        }

        return $out;
    }

    /** @param list<array{type: string, statement: string, value: string}> $criteria */
    private function insertCriteria(int $rotatorId, int $ruleId, array $criteria): void
    {
        if ($criteria === []) {
            return;
        }
        $insert = $this->prepare('INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)');
        foreach ($criteria as $c) {
            $this->bind($insert, 'iisss', $rotatorId, $ruleId, $c['type'], $c['statement'], $c['value']);
            $this->execute($insert, 'Failed to insert criterion');
        }
        $insert->close();
    }

    /** @param list<array{url: ?string, campaign: ?int, lp: ?int, weight: string, name: string}> $redirects */
    private function insertRedirects(int $ruleId, array $redirects): void
    {
        if ($redirects === []) {
            return;
        }
        $insert = $this->prepare('INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($redirects as $r) {
            $this->bind($insert, 'isiiss', $ruleId, $r['url'], $r['campaign'], $r['lp'], $r['weight'], $r['name']);
            $this->execute($insert, 'Failed to insert redirect');
        }
        $insert->close();
    }

    /**
     * Read-only preview of delete() for `?dry_run=1`: the rotator plus the
     * counts of the rule/criteria/redirect rows the cascade would remove.
     */
    public function deletePreview(int $id): array
    {
        $rotator = $this->get($id)['data'];
        $criteriaCount = 0;
        $redirectCount = 0;
        foreach ($rotator['rules'] as $rule) {
            $criteriaCount += count($rule['criteria']);
            $redirectCount += count($rule['redirects']);
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'rotators',
            'mode' => 'hard',
            'record' => $rotator,
            'cascade' => [
                ['resource' => 'rotator-rules', 'count' => count($rotator['rules'])],
                ['resource' => 'rotator-rule-criteria', 'count' => $criteriaCount],
                ['resource' => 'rotator-rule-redirects', 'count' => $redirectCount],
            ],
        ]];
    }

    public function delete(int $id): void
    {
        $this->get($id);

        $this->transaction(function () use ($id): void {
            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ?');
            $this->bind($stmt, 'i', $id);
            $this->execute($stmt, 'Delete criteria failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id = ?)');
            $this->bind($stmt, 'i', $id);
            $this->execute($stmt, 'Delete redirects failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');
            $this->bind($stmt, 'i', $id);
            $this->execute($stmt, 'Delete rules failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotators WHERE id = ? AND user_id = ?');
            $this->bind($stmt, 'ii', $id, $this->userId);
            $this->execute($stmt, 'Delete rotator failed');
            $stmt->close();
        });
    }

    public function listRules(int $rotatorId): array
    {
        $rotator = $this->get($rotatorId);
        return ['data' => $rotator['data']['rules']];
    }

    public function createRule(int $rotatorId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::RULE_FIELDS, 'a redirector rule');
        $this->get($rotatorId);

        $ruleName = self::ruleOrRotatorName($payload, 'rule_name', true);
        $splittest = self::flag($payload, 'splittest', 0);
        $status = self::flag($payload, 'status', 1);
        $parts = $this->ruleParts($payload);

        $this->transaction(function () use ($parts, $rotatorId, $ruleName, $splittest, $status): void {
            $stmt = $this->prepare('INSERT INTO 202_rotator_rules (rotator_id, rule_name, splittest, status) VALUES (?, ?, ?, ?)');
            $this->bind($stmt, 'isii', $rotatorId, $ruleName, $splittest, $status);
            $this->execute($stmt, 'Failed to create rule');
            $ruleId = $stmt->insert_id;
            $stmt->close();

            $this->insertCriteria($rotatorId, $ruleId, $parts['criteria'] ?? []);
            $this->insertRedirects($ruleId, $parts['redirects'] ?? []);
        });

        // Committed: the rule and its redirects exist.
        try {
            return $this->get($rotatorId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('rotator rule', $e);
        }
    }

    public function updateRule(int $rotatorId, int $ruleId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::RULE_FIELDS, 'a redirector rule');
        $this->get($rotatorId);

        // get() above proved the rotator is the caller's; the rule must be its.
        $stmt = $this->prepare('SELECT 1 FROM 202_rotator_rules WHERE id = ? AND rotator_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $ruleId, $rotatorId);
        $this->execute($stmt, 'Rule lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Rule lookup failed');
        }
        $rule = $result->fetch_row();
        $stmt->close();
        if ($rule === null) {
            throw new NotFoundException('Rule not found for rotator');
        }

        $setParts = [];
        $binds = [];
        $types = '';

        if (array_key_exists('rule_name', $payload)) {
            $setParts[] = 'rule_name = ?';
            $binds[] = self::ruleOrRotatorName($payload, 'rule_name', true);
            $types .= 's';
        }
        if (array_key_exists('splittest', $payload)) {
            $setParts[] = 'splittest = ?';
            $binds[] = self::flag($payload, 'splittest', 0);
            $types .= 'i';
        }
        if (array_key_exists('status', $payload)) {
            $setParts[] = 'status = ?';
            $binds[] = self::flag($payload, 'status', 1);
            $types .= 'i';
        }

        $hasCriteria = array_key_exists('criteria', $payload);
        $hasRedirects = array_key_exists('redirects', $payload);
        if ($hasCriteria && !is_array($payload['criteria'])) {
            throw new ValidationException('criteria must be an array', ['criteria' => 'Expected array']);
        }
        if ($hasRedirects && !is_array($payload['redirects'])) {
            throw new ValidationException('redirects must be an array', ['redirects' => 'Expected array']);
        }
        if (empty($setParts) && !$hasCriteria && !$hasRedirects) {
            throw new ValidationException('No fields to update');
        }
        // Every entry checked before the first write: the transaction would
        // roll a mid-list failure back, but a refusal should not need one.
        $parts = $this->ruleParts($payload);

        $this->transaction(function () use ($binds, $hasCriteria, $hasRedirects, $parts, $rotatorId, $ruleId, $setParts, $types): void {
            if (!empty($setParts)) {
                $binds[] = $ruleId;
                $types .= 'i';
                $update = $this->prepare('UPDATE 202_rotator_rules SET ' . implode(', ', $setParts) . ' WHERE id = ?');
                $this->bind($update, $types, ...$binds);
                $this->execute($update, 'Failed to update rule');
                $update->close();
            }

            if ($hasCriteria) {
                $deleteCriteria = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?');
                $this->bind($deleteCriteria, 'i', $ruleId);
                $this->execute($deleteCriteria, 'Failed to clear criteria');
                $deleteCriteria->close();
                $this->insertCriteria($rotatorId, $ruleId, $parts['criteria'] ?? []);
            }

            if ($hasRedirects) {
                $deleteRedirects = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?');
                $this->bind($deleteRedirects, 'i', $ruleId);
                $this->execute($deleteRedirects, 'Failed to clear redirects');
                $deleteRedirects->close();
                $this->insertRedirects($ruleId, $parts['redirects'] ?? []);
            }
        });

        // Committed: a failed read-back must not read as a failed update.
        try {
            return $this->get($rotatorId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('rotator rule', $e);
        }
    }

    /**
     * Read-only preview of deleteRule() for `?dry_run=1`: the rule plus the
     * criteria/redirect rows its deletion would cascade to.
     */
    public function deleteRulePreview(int $rotatorId, int $ruleId): array
    {
        $rotator = $this->get($rotatorId)['data'];
        $target = null;
        foreach ($rotator['rules'] as $rule) {
            if ((int)$rule['id'] === $ruleId) {
                $target = $rule;
                break;
            }
        }
        if ($target === null) {
            throw new NotFoundException('Rule not found for rotator');
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'rotator-rules',
            'mode' => 'hard',
            'record' => $target,
            'cascade' => [
                ['resource' => 'rotator-rule-criteria', 'count' => count($target['criteria'])],
                ['resource' => 'rotator-rule-redirects', 'count' => count($target['redirects'])],
            ],
        ]];
    }

    public function deleteRule(int $rotatorId, int $ruleId): void
    {
        $this->get($rotatorId);

        // Verify the rule belongs to this rotator before touching its child
        // rows: the criteria/redirects deletes below key on rule_id alone, so
        // without this check a foreign rule_id would have its children
        // removed even though the final rule delete (scoped to rotator_id)
        // would no-op. Same ownership check updateRule() already performs.
        $stmt = $this->prepare('SELECT id FROM 202_rotator_rules WHERE id = ? AND rotator_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $ruleId, $rotatorId);
        $this->execute($stmt, 'Rule lookup failed');
        $rule = $this->resultOf($stmt, 'Rule lookup failed')->fetch_assoc();
        $stmt->close();
        if (!$rule) {
            throw new NotFoundException('Rule not found for rotator');
        }

        $this->transaction(function () use ($rotatorId, $ruleId): void {
            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?');
            $this->bind($stmt, 'i', $ruleId);
            $this->execute($stmt, 'Delete criteria failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?');
            $this->bind($stmt, 'i', $ruleId);
            $this->execute($stmt, 'Delete redirects failed');
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules WHERE id = ? AND rotator_id = ?');
            $this->bind($stmt, 'ii', $ruleId, $rotatorId);
            $this->execute($stmt, 'Delete rule failed');
            $stmt->close();
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\SaveNotices;

/**
 * Setup › Redirectors' save tells Slack what it changed, read from the
 * redirector as the save's lock found it and as it was written.
 */
final class SaveNoticesTest extends TestCase
{
    /** @return array<string, mixed> a redirector as RotatorsController::get() answers it */
    private static function rotator(array $default, array $rules): array
    {
        return $default + ['name' => 'Spring sale', 'default_url' => null, 'default_campaign' => null,
            'default_lp' => null, 'auto_monetizer' => null, 'rules' => $rules];
    }

    /** @return array<string, mixed> */
    private static function rule(int $id, string $name, int $status, array $criteria): array
    {
        return ['id' => $id, 'rule_name' => $name, 'status' => $status, 'criteria' => $criteria, 'redirects' => []];
    }

    /** @return array<string, mixed> */
    private static function criterion(int $id, string $value): array
    {
        return ['id' => $id, 'type' => 'country', 'statement' => 'is', 'value' => $value];
    }

    private static function names(): callable
    {
        return static fn (string $type, mixed $id): ?string => ['campaign:7' => 'Trial sign-ups', 'lp:9' => 'Pricing page'][$type . ':' . $id] ?? null;
    }

    public function testEachChangeOfASaveIsNoticed(): void
    {
        $before = self::rotator(['default_campaign' => 7], [
            self::rule(1, 'US visitors', 1, [self::criterion(11, 'United States(US)'), self::criterion(12, 'Canada(CA)')]),
            self::rule(2, 'Mobile', 1, [self::criterion(21, 'ALL')]),
        ]);
        $after = self::rotator(['default_lp' => 9], [
            self::rule(1, 'North America', 0, [self::criterion(11, 'United States(US)'), self::criterion(13, 'Mexico(MX)')]),
            self::rule(3, 'Europe', 1, [self::criterion(31, 'France(FR)')]),
        ]);

        self::assertSame([
            ['rotator_defaults_changed', ['name' => 'Spring sale', 'default_from_type' => 'Campaign', 'default_from_value' => 'Trial sign-ups',
                'default_to_type' => 'Landing Page', 'default_to_value' => 'Pricing page']],
            ['rotator_rule_name_changed', ['rotator' => 'Spring sale', 'old_name' => 'US visitors', 'new_name' => 'North America']],
            ['rotator_rule_status_changed', ['rotator' => 'Spring sale', 'rule' => 'North America', 'old_status' => 'active', 'new_status' => 'inactive']],
            ['rotator_rules_criteria_created', ['rotator' => 'Spring sale', 'rule' => 'North America', 'criteria' => 'country is Mexico(MX)']],
            ['rotator_rule_created', ['rotator' => 'Spring sale', 'rule' => 'Europe']],
            ['rotator_rules_criteria_created', ['rotator' => 'Spring sale', 'rule' => 'Europe', 'criteria' => 'country is France(FR)']],
            ['rotator_rule_deleted', ['rotator' => 'Spring sale', 'rule' => 'Mobile']],
            ['rotator_rules_criteria_deleted', ['rotator' => 'Spring sale', 'rule' => 'US visitors', 'criteria' => 'country is Canada(CA)']],
            ['rotator_rules_criteria_deleted', ['rotator' => 'Spring sale', 'rule' => 'Mobile', 'criteria' => 'country is ALL']],
        ], SaveNotices::of($before, $after, self::names()));
    }

    public function testASaveThatChangesNothingIsNotNoticed(): void
    {
        $rules = [self::rule(1, 'US visitors', 1, [self::criterion(11, 'United States(US)')])];
        $same = self::rotator(['default_url' => 'https://shop.example/'], $rules);

        self::assertSame([], SaveNotices::of($same, $same, self::names()));
    }

    public function testAFirstDefaultIsAddedAndOneAfterTheMonetizerIsChangedFromIt(): void
    {
        $after = self::rotator(['default_url' => 'https://shop.example/'], []);

        self::assertSame(
            [['rotator_defaults_added', ['name' => 'Spring sale', 'default_type' => 'URL', 'default_value' => 'https://shop.example/']]],
            SaveNotices::of(self::rotator([], []), $after, self::names())
        );
        self::assertSame(
            [['rotator_defaults_changed_from_monetizer', ['name' => 'Spring sale', 'default_to_type' => 'URL',
                'default_to_value' => 'https://shop.example/']]],
            SaveNotices::of(self::rotator(['auto_monetizer' => 'true'], []), $after, self::names())
        );
    }
}

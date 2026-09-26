<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Apps\AppRetention;
use Api\V3\Apps\Apple\PostbackReceiver;
use Api\V3\Apps\RetentionClass;
use PHPUnit\Framework\TestCase;

/**
 * Retention is data each signal source registers (plan §4.6). These pin the
 * registration itself — the classes, their environment names and the
 * windows a value resolves to; the pruning SQL is exercised in
 * PostbackReceiverTest and against a real server in AppRegistryIntegrationTest.
 */
final class AppRetentionTest extends TestCase
{
    public function testTheAppleSourceRegistersItsThreeClassesUnderThePlannedNames(): void
    {
        $classes = AppRetention::registeredClasses();
        $this->assertSame(
            [
                'postbacks/unclaimed' => ['P202_APP_RETENTION_DAYS_POSTBACKS_UNCLAIMED', 30],
                'postbacks/refuted' => ['P202_APP_RETENTION_DAYS_POSTBACKS_REFUTED', 90],
                'postbacks/unvouched' => ['P202_APP_RETENTION_DAYS_POSTBACKS_UNVOUCHED', 90],
            ],
            array_combine(
                array_map(static fn (RetentionClass $c): string => $c->name(), $classes),
                array_map(static fn (RetentionClass $c): array => [$c->environmentVariable(), $c->defaultDays], $classes)
            )
        );
        $this->assertEquals(PostbackReceiver::retentionClasses(), array_values(array_filter(
            $classes,
            static fn (RetentionClass $c): bool => $c->table === '202_app_postbacks'
        )));
    }

    public function testEveryClassSelectsAnUntrustedOrUnclaimedRowAndNeverATrustedClaimedOne(): void
    {
        foreach (AppRetention::registeredClasses() as $class) {
            $this->assertStringNotContainsString('trusted = 1', $class->predicate, $class->name());
            $this->assertMatchesRegularExpression('/^(user_id = 0|trusted = 0|trusted IS NULL)$/', $class->predicate, $class->name());
        }
    }

    public function testARetentionClassRefusesATableOutsideTheAppTables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionClass('202_clicks', 'old', 'user_id = 0', 'click_time', 30);
    }

    /**
     * @dataProvider windows
     */
    public function testWhatAnOverrideResolvesTo(string|false $value, int $days, ?int $cutoff): void
    {
        $now = 1_800_000_000;
        $name = 'P202_APP_RETENTION_DAYS_POSTBACKS_UNCLAIMED';
        $value === false ? putenv($name) : putenv("$name=$value");
        try {
            $policy = (new AppRetention($this->createMock(\mysqli::class), PostbackReceiver::retentionClasses()))->policy($now);
            $this->assertSame(['days' => $days, 'cutoff' => $cutoff], $policy['postbacks/unclaimed']);
        } finally {
            putenv($name);
        }
    }

    /** @return array<string, array{0: string|false, 1: int, 2: int|null}> */
    public static function windows(): array
    {
        $now = 1_800_000_000;
        return [
            'unset: the default' => [false, 30, $now - 30 * 86400],
            'blank: the default' => ['  ', 30, $now - 30 * 86400],
            'a number' => ['7', 7, $now - 7 * 86400],
            'zero disables' => ['0', 0, null],
            // An operator who wrote "never" meant keep: a value that cannot
            // be read prunes nothing rather than the default (#4, #11).
            'a word prunes nothing' => ['never', 0, null],
            'a unit prunes nothing' => ['90 days', 0, null],
            'negative prunes nothing' => ['-5', 0, null],
            // Longer than the epoch is old: keeps everything, rather than an
            // overflowing multiplication producing a float cutoff.
            'longer than the epoch' => ['99999999999999999999', PHP_INT_MAX, 0],
        ];
    }
}

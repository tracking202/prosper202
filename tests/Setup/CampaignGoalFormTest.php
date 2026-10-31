<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;

/**
 * The campaign goal editor (tracking202/setup/_includes/campaign_goals.php)
 * must round-trip a stored definition exactly: opening a goal and saving it
 * unchanged stores the same canonical definition, JSON types included.
 *
 * Goal equality is typed (GoalEvaluator::equal(): the text "123" never
 * equals the number 123, true never equals "true"), so an editor that
 * re-guessed a value's type from its text changed what a goal matches — and
 * what it pays — on an edit that looked like nothing. Every value here is
 * sent through the path the page takes: the stored goal filled into the
 * form, the form's strings posted back, the definition built from them.
 */
final class CampaignGoalFormTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/campaign_goals.php';
    }

    /**
     * @param array<int, array<string, mixed>> $where
     * @return array<string, mixed>
     */
    private static function def(array $where, array $extra = []): array
    {
        return GoalDefinition::parse(array_replace([
            'name' => 'Pro sale',
            'trigger' => ['event' => 'sale', 'where' => $where],
            'value' => ['type' => 'fixed', 'amount' => '4.00'],
        ], $extra))->toArray();
    }

    /**
     * Open the goal in the form, post the form's strings back, and build.
     *
     * @param array<string, mixed> $def
     * @return array{definition: array<string, mixed>, errors: array<string, string>}
     */
    private static function openAndSave(array $def): array
    {
        $shown = p202_goal_form_values(['goal_id' => 7, 'scope_id' => 3, 'campaigns' => [], 'definition' => $def], null);
        foreach ($shown as $k => $v) {
            self::assertIsString($v, $k . ' is posted as a string');
        }
        $posted = p202_goal_form_values(null, $shown);

        return p202_goal_definition_from_form($posted);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function values(): iterable
    {
        foreach (['eq', 'neq'] as $op) {
            yield $op . ' the text "123"' => ['123', $op];
            yield $op . ' the number 123' => [123, $op];
            yield $op . ' true' => [true, $op];
            yield $op . ' false' => [false, $op];
            yield $op . ' the text "true"' => ['true', $op];
            yield $op . ' the text "false"' => ['false', $op];
            yield $op . ' the float 3.0' => [3.0, $op];
            yield $op . ' the integer 3' => [3, $op];
            yield $op . ' the text "3.0"' => ['3.0', $op];
            yield $op . ' the float 0.1' => [0.1, $op];
            yield $op . ' the float -0.5' => [-0.5, $op];
            yield $op . ' the float 1e20' => [1e20, $op];
            yield $op . ' the float 3.14159265358979' => [3.14159265358979, $op];
            yield $op . ' the integer PHP_INT_MAX' => [PHP_INT_MAX, $op];
            yield $op . ' the empty text' => ['', $op];
            yield $op . ' text with spaces' => ['  pro plan ', $op];
            yield $op . ' the text "1e3"' => ['1e3', $op];
            yield $op . ' the text "null"' => ['null', $op];
        }
        foreach (['gt', 'gte', 'lt', 'lte'] as $op) {
            yield $op . ' the integer 3' => [3, $op];
            yield $op . ' the float 3.0' => [3.0, $op];
            yield $op . ' the float 2.5' => [2.5, $op];
            yield $op . ' the integer -40' => [-40, $op];
        }
    }

    /** @dataProvider values */
    public function testAConditionValueKeepsItsJsonTypeThroughAnOpenAndSave(mixed $value, string $op): void
    {
        $stored = self::def([['prop' => 'plan', 'op' => $op, 'value' => $value]]);
        self::assertTrue(p202_goal_form_fits($stored), 'the form can show this goal');
        $saved = self::openAndSave($stored);
        self::assertSame([], $saved['errors']);
        $again = GoalDefinition::parse($saved['definition'])->toArray();
        self::assertSame($stored, $again, 'an unchanged edit stores the same definition');
        // assertSame on arrays compares with ===, but say it in the type's
        // own words too: this is the whole finding.
        self::assertSame(get_debug_type($value), get_debug_type($again['trigger']['where'][0]['value']));
        self::assertSame(
            json_encode($stored, JSON_PRESERVE_ZERO_FRACTION),
            json_encode($again, JSON_PRESERVE_ZERO_FRACTION),
            'the stored JSON text is byte for byte the same'
        );
    }

    public function testTheWholeCommonShapeRoundTrips(): void
    {
        $stored = self::def([['prop' => 'plan', 'op' => 'eq', 'value' => '123']], [
            'threshold' => ['count' => 3],
            'after' => [12],
            'within' => ['days' => 30, 'from' => 'click'],
            'repeat' => ['mode' => 'each', 'max' => 5],
            'value' => ['type' => 'from_property'],
        ]);
        self::assertTrue(p202_goal_form_fits($stored));
        self::assertSame($stored, GoalDefinition::parse(self::openAndSave($stored)['definition'])->toArray());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unshowable(): iterable
    {
        yield 'in' => [['trigger' => ['event' => 'sale', 'where' => [['prop' => 'plan', 'op' => 'in', 'value' => ['pro', 123]]]]]];
        yield 'exists' => [['trigger' => ['event' => 'sale', 'where' => [['prop' => 'plan', 'op' => 'exists']]]]];
        yield 'two conditions' => [['trigger' => ['event' => 'sale', 'where' => [
            ['prop' => 'plan', 'op' => 'eq', 'value' => 'pro'], ['prop' => 'seats', 'op' => 'gt', 'value' => 2],
        ]]]];
        yield 'a running sum' => [['threshold' => ['sum' => ['prop' => 'revenue', 'gte' => '10.00']]]];
        yield 'a window from the install' => [['within' => ['days' => 7, 'from' => 'install']]];
        yield 'two prerequisites' => [['after' => [12, 13]]];
        yield 'the value of another property' => [['value' => ['type' => 'from_property', 'prop' => 'price']]];
        yield 'the install' => [['trigger' => ['install' => true]]];
    }

    /**
     * @dataProvider unshowable
     * @param array<string, mixed> $extra
     */
    public function testADefinitionTheFormCannotShowIsNotOpenedByIt(array $extra): void
    {
        $stored = GoalDefinition::parse(array_replace([
            'name' => 'Pro sale',
            'trigger' => ['event' => 'sale', 'where' => []],
            'value' => ['type' => 'fixed', 'amount' => '4.00'],
        ], $extra))->toArray();
        self::assertFalse(p202_goal_form_fits($stored));
    }

    public function testAValueTheDefinitionRefusesIsNotShowable(): void
    {
        // null is not a predicate value (GoalDefinition refuses it); a row
        // holding one cannot be opened, rather than being opened as "".
        self::assertFalse(p202_goal_form_fits(['name' => 'X', 'trigger' => ['event' => 'sale', 'where' => [['prop' => 'p', 'op' => 'eq', 'value' => null]]]]));
    }

    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function typedInput(): iterable
    {
        yield 'auto: a number reads as a number' => ['123', 'auto', 'eq', 123];
        yield 'auto: a decimal reads as a float' => ['3.0', 'auto', 'eq', 3.0];
        yield 'auto: anything else is text' => ['pro', 'auto', 'eq', 'pro'];
        yield 'auto: true is text, as before' => ['true', 'auto', 'eq', 'true'];
        yield 'text keeps digits as text' => ['123', 'text', 'eq', '123'];
        yield 'number: an integer' => ['3', 'number', 'eq', 3];
        yield 'number: a float with a zero fraction' => ['3.0', 'number', 'eq', 3.0];
        yield 'number: an exponent is a float' => ['1e3', 'number', 'eq', 1000.0];
        yield 'bool: true' => ['true', 'bool', 'eq', true];
        yield 'bool: false' => ['false', 'bool', 'neq', false];
    }

    /** @dataProvider typedInput */
    public function testTheFormReadsAValueInTheTypeItNames(string $raw, string $type, string $op, mixed $want): void
    {
        $built = p202_goal_definition_from_form(self::posted($raw, $type, $op));
        self::assertSame([], $built['errors']);
        self::assertSame($want, GoalDefinition::parse($built['definition'])->toArray()['trigger']['where'][0]['value']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function refusedInput(): iterable
    {
        yield 'number: not a number' => ['abc', 'number', 'eq', 'goal_where_value'];
        yield 'number: a leading zero' => ['03', 'number', 'eq', 'goal_where_value'];
        yield 'number: a whole number past 64 bits' => ['99999999999999999999', 'number', 'eq', 'goal_where_value'];
        yield 'number: infinite' => ['1e999', 'number', 'eq', 'goal_where_value'];
        yield 'bool: not true or false' => ['yes', 'bool', 'eq', 'goal_where_value'];
        yield 'bool: a capital' => ['True', 'bool', 'eq', 'goal_where_value'];
        yield 'text: compared as more than' => ['3', 'text', 'gt', 'goal_where_value'];
        yield 'auto: text compared as more than' => ['pro', 'auto', 'gt', 'goal_where_value'];
        yield 'an unknown type' => ['3', 'json', 'eq', 'goal_where_type'];
        yield 'no type' => ['3', '', 'eq', 'goal_where_type'];
    }

    /** @dataProvider refusedInput */
    public function testAValueThatIsNotOfItsTypeIsRefusedByName(string $raw, string $type, string $op, string $field): void
    {
        $built = p202_goal_definition_from_form(self::posted($raw, $type, $op));
        self::assertArrayHasKey($field, $built['errors']);
    }

    /** @return array<string, string> */
    private static function posted(string $raw, string $type, string $op): array
    {
        return p202_goal_form_values(null, [
            'goal_name' => 'Sale', 'goal_event' => 'sale', 'goal_value' => 'none', 'goal_count' => '1', 'goal_repeat' => 'once',
            'goal_where_prop' => 'plan', 'goal_where_op' => $op, 'goal_where_value' => $raw, 'goal_where_type' => $type,
        ]);
    }
}

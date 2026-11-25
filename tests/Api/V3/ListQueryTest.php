<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ListQuery;
use PHPUnit\Framework\TestCase;

/**
 * ListQuery reads a list's filter, q and sort strictly; and every list's
 * sort and search names fields of its own answer.
 */
final class ListQueryTest extends TestCase
{
    private const FIELDS = [
        'id' => ['type' => 'i'],
        'name' => ['type' => 's'],
        'price' => ['type' => 'd'],
        'day' => ['type' => 's', 'format' => 'date'],
    ];

    public function testAWholeNumberFilterTakesSeveralValuesAndOthersTakeOne(): void
    {
        $filters = fn(array $filter): array => ListQuery::filters(['filter' => $filter], self::FIELDS);
        self::assertSame(['id' => ['1', '4', '-2']], $filters(['id' => '1,4,-2']));
        self::assertSame(['id' => ['7']], $filters(['id' => '7']));
        self::assertSame(['name' => ['a,b']], $filters(['name' => 'a,b']));
        self::assertSame(['day' => ['2026-02-28']], $filters(['day' => '2026-02-28']));
        self::assertSame([], ListQuery::filters([], self::FIELDS));
        $hundred = implode(',', range(1, ListQuery::MAX_FILTER_VALUES));
        self::assertCount(ListQuery::MAX_FILTER_VALUES, $filters(['id' => $hundred])['id']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refusedFilters(): iterable
    {
        yield 'empty item' => [['id' => '1,,2'], 'filter[id]'];
        yield 'leading comma' => [['id' => ',1'], 'filter[id]'];
        yield 'space' => [['id' => '1, 2'], 'filter[id]'];
        yield 'empty' => [['id' => ''], 'filter[id]'];
        yield 'past the cap' => [['id' => implode(',', range(1, ListQuery::MAX_FILTER_VALUES + 1))], 'filter[id]'];
        yield 'a list' => [['id' => ['1']], 'filter[id]'];
        yield 'decimal list' => [['price' => '1,2'], 'filter[price]'];
        yield 'not a date' => [['day' => '2026-02-30'], 'filter[day]'];
        yield 'unknown field' => [['nmae' => 'x'], 'filter[nmae]'];
        yield 'not a map' => ['id', 'filter'];
    }

    /** @dataProvider refusedFilters */
    public function testAFilterThatCannotBeAppliedIsRefusedNamingIt(mixed $filter, string $field): void
    {
        try {
            ListQuery::filters(['filter' => $filter], self::FIELDS);
            self::fail('accepted ' . json_encode($filter));
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
    }

    public function testQIsAContainsPatternWithItsWildcardsTakenLiterally(): void
    {
        self::assertNull(ListQuery::searchPattern([]));
        self::assertNull(ListQuery::searchPattern(['q' => '']));
        self::assertSame('%abc%', ListQuery::searchPattern(['q' => 'AbC']));
        self::assertSame('%100!%!_x!!%', ListQuery::searchPattern(['q' => '100%_x!']));
        self::assertSame('%éa%', ListQuery::searchPattern(['q' => 'ÉA']));
        self::assertSame(
            ["(LOWER(a) LIKE ? ESCAPE '!' OR LOWER(b) LIKE ? ESCAPE '!')", ['%x%', '%x%'], 'ss'],
            ListQuery::searchCondition(['a', 'b'], '%x%')
        );
    }

    public function testSortOrdersByAnAllowedFieldThenTheKey(): void
    {
        $sortable = ['id' => 'id', 'name' => 'name'];
        self::assertNull(ListQuery::orderBy([], $sortable, 'id'));
        self::assertNull(ListQuery::orderBy(['sort' => ''], $sortable, 'id'));
        self::assertSame('name ASC, id ASC', ListQuery::orderBy(['sort' => 'name'], $sortable, 'id'));
        $desc = ['sort' => 'name', 'sort_dir' => 'desc'];
        self::assertSame('name DESC, id DESC', ListQuery::orderBy($desc, $sortable, 'id'));
        self::assertSame('id DESC', ListQuery::orderBy(['sort' => 'id', 'sort_dir' => 'DESC'], $sortable, 'id'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedSorts(): iterable
    {
        yield 'not allowed' => [['sort' => 'secret'], 'sort'];
        yield 'SQL' => [['sort' => 'name; DROP TABLE x'], 'sort'];
        yield 'a list' => [['sort' => ['name']], 'sort'];
        yield 'bad direction' => [['sort' => 'name', 'sort_dir' => 'sideways'], 'sort_dir'];
        yield 'direction alone' => [['sort_dir' => 'desc'], 'sort_dir'];
    }

    /**
     * @dataProvider refusedSorts
     * @param array<string, mixed> $params
     */
    public function testASortThatCannotBeAppliedIsRefusedNamingIt(array $params, string $field): void
    {
        try {
            ListQuery::orderBy($params, ['id' => 'id', 'name' => 'name'], 'id');
            self::fail('accepted ' . json_encode($params));
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
        }
    }

    /**
     * A sort or a search names a field the list answers, so what a client
     * orders or finds by is something it can see; and the key is always
     * sortable.
     */
    public function testEveryListSortsAndSearchesByFieldsOfItsOwnAnswer(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;
        $offenders = [];
        foreach (glob($root . '/api/v3/Controllers/*.php') ?: [] as $file) {
            $class = 'Api\\V3\\Controllers\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract() || !$ref->isSubclassOf(Controller::class)) {
                continue;
            }
            $controller = $ref->newInstanceWithoutConstructor();
            $call = fn(string $method): mixed => (new \ReflectionMethod($controller, $method))->invoke($controller);
            $columns = $call('selectColumns');
            $pk = $call('primaryKey');
            $sorts = $call('listSortFields');
            if (!array_key_exists($pk, $sorts)) {
                $offenders[] = "$class: the key $pk is not sortable";
            }
            foreach ($sorts as $name => $column) {
                if ($name !== $column || !in_array($name, $columns, true)) {
                    $offenders[] = "$class: sort $name => $column is not a column of the answer";
                }
            }
            foreach ($call('listSearchColumns') as $column) {
                if (!in_array($column, $columns, true)) {
                    $offenders[] = "$class: q searches $column, which the answer does not hold";
                }
            }
            $checked++;
        }
        self::assertGreaterThanOrEqual(10, $checked, 'expected the Controller subclasses in api/v3/Controllers');
        self::assertSame([], $offenders, implode("\n", $offenders));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Tests\TestCase;

/**
 * Controller::list() pages with LIMIT/OFFSET (and offset cursors), so each
 * subclass's listOrderBy() must be a total order: rows tied on a non-unique
 * column (two forecast events on one date) come back in a different order per
 * page, and a client paging through skips some and repeats others. Ending the
 * order with the primary key makes it total.
 */
final class ListOrderIsTotalTest extends TestCase
{
    public function testEveryListOrderEndsWithThePrimaryKey(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = [];
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
            $order = (string) (new \ReflectionMethod($controller, 'listOrderBy'))->invoke($controller);
            $pk = (string) (new \ReflectionMethod($controller, 'primaryKey'))->invoke($controller);
            $checked[] = $class;

            $lastTerm = (string) strrchr(',' . $order, ',');
            $last = trim((string) preg_replace('/\s+(ASC|DESC)\s*$/i', '', $lastTerm), ", \t\n");
            if ($last !== $pk) {
                $offenders[] = "$class: ORDER BY $order (primary key $pk)";
            }
        }

        // Floor: ten subclasses exist today; finding few means discovery broke.
        self::assertGreaterThanOrEqual(10, count($checked), 'expected the Controller subclasses in api/v3/Controllers');
        self::assertSame([], $offenders, "listOrderBy() must end with the primary key:\n" . implode("\n", $offenders));
    }
}

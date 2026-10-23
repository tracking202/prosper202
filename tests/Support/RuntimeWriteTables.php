<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The tables the two generic writers name only at runtime, read from the
 * classes that name them: what a structural test that finds their INSERT
 * with an unread table (AssembledStrings) checks the write can reach.
 */
final class RuntimeWriteTables
{
    private function __construct()
    {
    }

    /**
     * api/v3/Controller.php's: the table each concrete CRUD controller names
     * in tableName().
     *
     * @return list<string>
     */
    public static function controllers(): array
    {
        $tables = [];
        foreach (glob(SourceScan::repoRoot() . '/api/v3/Controllers/*Controller.php') ?: [] as $path) {
            $class = 'Api\\V3\\Controllers\\' . basename($path, '.php');
            if (!class_exists($class) || !is_subclass_of($class, \Api\V3\Controller::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $method = $reflection->getMethod('tableName');
            $tables[] = (string) $method->invoke($reflection->newInstanceWithoutConstructor());
        }

        return $tables;
    }

    /**
     * 202-config/Crud/MysqlCrudRepository.php's: the table of every
     * TableConfig its static constructors build.
     *
     * @return list<string>
     */
    public static function tableConfigs(): array
    {
        $tables = [];
        $class = new \ReflectionClass(\Prosper202\Crud\TableConfig::class);
        foreach ($class->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                $config = $method->invoke(null);
                if ($config instanceof \Prosper202\Crud\TableConfig) {
                    $tables[] = $config->table;
                }
            }
        }

        return $tables;
    }
}

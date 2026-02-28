<?php

declare(strict_types=1);

namespace Tests\Database\Schema;

use PHPUnit\Framework\Attributes\Test;
use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;
use Tests\TestCase;

class SchemaBuilderTest extends TestCase
{
    // ─── SchemaBuilder Tests ─────────────────────────────────────────

    #[Test]
    public function testBuildSimpleTable(): void
    {
        $definition = SchemaBuilder::table('202_test_table')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->column('`name` VARCHAR(255) NOT NULL')
            ->primaryKey('`id`')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `202_test_table`', $sql);
        $this->assertStringContainsString('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('`name` VARCHAR(255) NOT NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
    }

    #[Test]
    public function testBuildWithIndex(): void
    {
        $definition = SchemaBuilder::table('202_indexed')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->column('`email` VARCHAR(255) NOT NULL')
            ->primaryKey('`id`')
            ->index('idx_email', '`email`')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('KEY `idx_email` (`email`)', $sql);
    }

    #[Test]
    public function testBuildWithUniqueIndex(): void
    {
        $definition = SchemaBuilder::table('202_unique_test')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->column('`api_key` VARCHAR(64) NOT NULL')
            ->primaryKey('`id`')
            ->uniqueIndex('uk_api_key', '`api_key`')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('UNIQUE KEY `uk_api_key` (`api_key`)', $sql);
    }

    #[Test]
    public function testBuildWithForeignKey(): void
    {
        $definition = SchemaBuilder::table('202_child')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->column('`parent_id` INT UNSIGNED NOT NULL')
            ->primaryKey('`id`')
            ->foreignKey('CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `202_parent` (`id`)')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `202_parent` (`id`)', $sql);
    }

    #[Test]
    public function testBuildWithCustomEngine(): void
    {
        $definition = SchemaBuilder::table('202_myisam_table')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->primaryKey('`id`')
            ->engine('MyISAM')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('ENGINE=MyISAM', $sql);
    }

    #[Test]
    public function testBuildWithCustomCharset(): void
    {
        $definition = SchemaBuilder::table('202_latin_table')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->primaryKey('`id`')
            ->charset('latin1')
            ->collation('latin1_swedish_ci')
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('DEFAULT CHARSET=latin1', $sql);
        $this->assertStringContainsString('COLLATE=latin1_swedish_ci', $sql);
    }

    #[Test]
    public function testBuildWithAutoIncrement(): void
    {
        $definition = SchemaBuilder::table('202_auto_inc')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->primaryKey('`id`')
            ->autoIncrement(1000)
            ->build();

        $sql = $definition->createStatement;

        $this->assertStringContainsString('AUTO_INCREMENT=1000', $sql);
    }

    #[Test]
    public function testBuildReturnsSchemaDefinition(): void
    {
        $definition = SchemaBuilder::table('202_full_table')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->primaryKey('`id`')
            ->engine('InnoDB')
            ->charset('utf8mb4')
            ->collation('utf8mb4_unicode_ci')
            ->build();

        $this->assertInstanceOf(SchemaDefinition::class, $definition);
        $this->assertSame('202_full_table', $definition->tableName);
        $this->assertSame('InnoDB', $definition->engine);
        $this->assertSame('utf8mb4', $definition->charset);
        $this->assertSame('utf8mb4_unicode_ci', $definition->collation);
    }

    #[Test]
    public function testFromRawSql(): void
    {
        $rawSql = 'CREATE TABLE IF NOT EXISTS `202_raw_table` (`id` INT PRIMARY KEY) ENGINE=InnoDB';
        $definition = SchemaBuilder::fromRawSql(
            '202_raw_table',
            $rawSql,
            'InnoDB',
            'utf8mb4',
            'utf8mb4_general_ci'
        );

        $this->assertInstanceOf(SchemaDefinition::class, $definition);
        $this->assertSame('202_raw_table', $definition->tableName);
        $this->assertSame($rawSql, $definition->createStatement);
        $this->assertSame('InnoDB', $definition->engine);
        $this->assertSame('utf8mb4', $definition->charset);
        $this->assertSame('utf8mb4_general_ci', $definition->collation);
    }

    #[Test]
    public function testDefaultEngineIsInnodb(): void
    {
        $definition = SchemaBuilder::table('202_default_engine')
            ->column('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT')
            ->primaryKey('`id`')
            ->build();

        $this->assertSame('InnoDB', $definition->engine);
        $this->assertStringContainsString('ENGINE=InnoDB', $definition->createStatement);
    }

    // ─── SchemaDefinition Tests ──────────────────────────────────────

    #[Test]
    public function testGetShortNameStrips202Prefix(): void
    {
        $definition = new SchemaDefinition(
            tableName: '202_users',
            createStatement: 'CREATE TABLE `202_users` (id INT)'
        );

        $this->assertSame('users', $definition->getShortName());
    }

    #[Test]
    public function testGetShortNameWithoutPrefix(): void
    {
        $definition = new SchemaDefinition(
            tableName: 'my_table',
            createStatement: 'CREATE TABLE `my_table` (id INT)'
        );

        $this->assertSame('my_table', $definition->getShortName());
    }

    #[Test]
    public function testUsesEngineCaseInsensitive(): void
    {
        $definition = new SchemaDefinition(
            tableName: '202_test',
            createStatement: 'CREATE TABLE `202_test` (id INT)',
            engine: 'InnoDB'
        );

        $this->assertTrue($definition->usesEngine('innodb'));
        $this->assertTrue($definition->usesEngine('InnoDB'));
        $this->assertTrue($definition->usesEngine('INNODB'));
        $this->assertFalse($definition->usesEngine('MyISAM'));
    }

    // ─── TableRegistry Tests ─────────────────────────────────────────

    #[Test]
    public function testGetAllTablesReturnsArray(): void
    {
        $tables = TableRegistry::getAllTables();

        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
        $this->assertContainsOnly('string', $tables);
    }

    #[Test]
    public function testIsValidTableForKnownTable(): void
    {
        $this->assertTrue(TableRegistry::isValidTable('202_users'));
    }

    #[Test]
    public function testIsValidTableForUnknownTable(): void
    {
        $this->assertFalse(TableRegistry::isValidTable('nonexistent'));
    }

    #[Test]
    public function testAllTableNamesAreStrings(): void
    {
        $tables = TableRegistry::getAllTables();

        foreach ($tables as $table) {
            $this->assertIsString($table, 'Every table name returned by getAllTables() must be a string');
        }
    }

    #[Test]
    public function testKnownConstantsExist(): void
    {
        $this->assertSame('202_users', TableRegistry::USERS);
        $this->assertSame('202_clicks', TableRegistry::CLICKS);
        $this->assertSame('202_aff_campaigns', TableRegistry::AFF_CAMPAIGNS);
        $this->assertSame('202_rotators', TableRegistry::ROTATORS);
    }
}

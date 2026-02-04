<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

/**
 * Fluent builder for creating SchemaDefinition objects.
 */
final class SchemaBuilder
{
    private string $tableName;
    /** @var array<string> */
    private array $columns = [];
    private ?string $primaryKey = null;
    /** @var array<string, string> */
    private array $indexes = [];
    /** @var array<string, string> */
    private array $uniqueIndexes = [];
    /** @var array<string> */
    private array $foreignKeys = [];
    private string $engine = 'InnoDB';
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_general_ci';
    private ?int $autoIncrement = null;

    private function __construct(string $tableName)
    {
        $this->tableName = $tableName;
    }

    /**
     * Start building a table definition.
     */
    public static function table(string $name): self
    {
        return new self($name);
    }

    /**
     * Add a column definition.
     */
    public function column(string $definition): self
    {
        $this->columns[] = $definition;
        return $this;
    }

    /**
     * Set the primary key.
     */
    public function primaryKey(string $columns): self
    {
        $this->primaryKey = $columns;
        return $this;
    }

    /**
     * Add an index.
     */
    public function index(string $name, string $columns): self
    {
        $this->indexes[$name] = $columns;
        return $this;
    }

    /**
     * Add a unique index.
     */
    public function uniqueIndex(string $name, string $columns): self
    {
        $this->uniqueIndexes[$name] = $columns;
        return $this;
    }

    /**
     * Add a foreign key constraint.
     */
    public function foreignKey(string $constraintDefinition): self
    {
        $this->foreignKeys[] = $constraintDefinition;
        return $this;
    }

    /**
     * Set the storage engine.
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set the character set.
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Set the collation.
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * Set AUTO_INCREMENT starting value.
     */
    public function autoIncrement(int $value): self
    {
        $this->autoIncrement = $value;
        return $this;
    }

    /**
     * Build and return the SchemaDefinition.
     */
    public function build(): SchemaDefinition
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->tableName}` (\n";

        $definitions = [];

        // Add columns
        foreach ($this->columns as $column) {
            $definitions[] = "  {$column}";
        }

        // Add primary key
        if ($this->primaryKey !== null) {
            $definitions[] = "  PRIMARY KEY ({$this->primaryKey})";
        }

        // Add unique indexes
        foreach ($this->uniqueIndexes as $name => $columns) {
            $definitions[] = "  UNIQUE KEY `{$name}` ({$columns})";
        }

        // Add indexes
        foreach ($this->indexes as $name => $columns) {
            $definitions[] = "  KEY `{$name}` ({$columns})";
        }

        // Add foreign keys
        foreach ($this->foreignKeys as $fk) {
            $definitions[] = "  {$fk}";
        }

        $sql .= implode(",\n", $definitions);
        $sql .= "\n) ENGINE={$this->engine}";

        if ($this->autoIncrement !== null) {
            $sql .= " AUTO_INCREMENT={$this->autoIncrement}";
        }

        $sql .= " DEFAULT CHARSET={$this->charset} COLLATE={$this->collation}";

        return new SchemaDefinition(
            tableName: $this->tableName,
            createStatement: $sql,
            engine: $this->engine,
            charset: $this->charset,
            collation: $this->collation
        );
    }

    /**
     * Create a SchemaDefinition directly from a raw SQL statement.
     * Useful for complex tables that don't fit the builder pattern well.
     */
    public static function fromRawSql(
        string $tableName,
        string $sql,
        string $engine = 'InnoDB',
        string $charset = 'utf8mb4',
        string $collation = 'utf8mb4_general_ci'
    ): SchemaDefinition {
        return new SchemaDefinition(
            tableName: $tableName,
            createStatement: $sql,
            engine: $engine,
            charset: $charset,
            collation: $collation
        );
    }
}
